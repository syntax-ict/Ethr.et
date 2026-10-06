<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanupExpiredDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Declared rather than inherited: prunes notifications, webhook deliveries and import staging across every tenant.
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

        // Import staging data — hard delete after 7 days (CLAUDE.md soft-delete
        // policy). Staging rows cascade with their batch.
        $deleted += DB::table('migration_batches')
            ->where('created_at', '<', now()->subDays(7))
            ->delete();

        Log::info("CleanupExpiredDataJob: deleted {$deleted} expired records.");
    }

    public function failed(Throwable $exception): void
    {
        // Self-healing: next scheduled run picks up whatever was missed.
        // Visible in the admin failed-jobs dashboard; no alert needed.
        Log::error('CleanupExpiredDataJob failed', ['error' => $exception->getMessage()]);
    }
}
