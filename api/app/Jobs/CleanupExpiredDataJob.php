<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MigrationBatch;
use App\Services\Auth\StartingPasswords;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CleanupExpiredDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Declared rather than inherited: prunes notifications, webhook deliveries, import staging and expired data exports across every tenant.
     *
     * Without this the job silently takes the worker's --timeout (60s by
     * default), and the queue retry_after invariant cannot be computed at all -
     * see tests/Feature/QueueRetryAfterInvariantTest.php.
     */
    public int $timeout = 600;

    public function handle(): void
    {
        $deleted = 0;

        $deleted += DB::table('notifications')
            ->where('created_at', '<', now()->subDays(90))
            ->delete();

        $deleted += DB::table('webhook_deliveries')
            ->where('created_at', '<', now()->subDays(30))
            ->delete();

        // Import staging data: hard delete once nobody has touched the batch
        // or any of its rows for MigrationBatch::RETENTION_DAYS (CLAUDE.md
        // soft-delete policy). Staging rows cascade with their batch. Counted
        // from the last activity, not from the day it was staged: a commit
        // updates the batch and choosing a row's action updates that row, so
        // a list being worked on is never the one deleted.
        $stale = now()->subDays(MigrationBatch::RETENTION_DAYS);
        $deleted += DB::table('migration_batches')
            ->where('updated_at', '<', $stale)
            ->whereNotExists(function ($rows) use ($stale): void {
                $rows->selectRaw('1')
                    ->from('migration_staging_rows')
                    ->whereColumn('migration_staging_rows.batch_id', 'migration_batches.id')
                    ->where('migration_staging_rows.updated_at', '>=', $stale);
            })
            ->delete();

        // An organisation's data export is deleted when it expires
        // (TenantExport::EXPIRES_DAYS). The row stays, marked expired, so the
        // settings page and the audit trail can still say it existed.
        $disk = Storage::disk(config('filesystems.default'));
        $expired = DB::table('tenant_exports')
            ->where('status', 'ready')
            ->where('expires_at', '<', now())
            ->get(['id', 'path']);
        foreach ($expired as $export) {
            if (is_string($export->path) && $export->path !== '') {
                $disk->delete($export->path);
            }
            DB::table('tenant_exports')->where('id', $export->id)
                ->update(['status' => 'expired', 'path' => null, 'updated_at' => now()]);
            $deleted++;
        }

        // A starting password nobody used in its 72 hours: the account goes
        // back to `invited`, so it can be invited or given an in-person code
        // again. A sign-in and RequirePasswordChange do the same for one
        // account at the moment it is used; this catches the ones never tried.
        $deleted += app(StartingPasswords::class)->expireOverdue();

        Log::info("CleanupExpiredDataJob: deleted {$deleted} expired records.");
    }

    public function failed(Throwable $exception): void
    {
        // Self-healing: next scheduled run picks up whatever was missed.
        // Visible in the admin failed-jobs dashboard; no alert needed.
        Log::error('CleanupExpiredDataJob failed', ['error' => $exception->getMessage()]);
    }
}
