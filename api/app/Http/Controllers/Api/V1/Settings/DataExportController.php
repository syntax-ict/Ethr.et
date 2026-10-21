<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\ExportTenantDataJob;
use App\Models\AuditLog;
use App\Models\TenantExport;
use App\Services\CurrentTenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An organisation's export of its own data (blueprint assessment A12, P12):
 * every row it owns plus the documents it uploaded, packed by
 * ExportTenantDataJob and kept for TenantExport::EXPIRES_DAYS. Requesting
 * and downloading are both audited; the archive holds every employee's
 * record, so it sits behind `settings.manage` like the rest of the
 * organisation's settings.
 */
class DataExportController extends Controller
{
    /** Exports one organisation may request in a rolling 24 hours. */
    private const DAILY_LIMIT = 3;

    /**
     * A queued or running export older than this is taken to have died with
     * its worker (the host can stop a request mid-run) and no longer blocks a
     * new one.
     */
    private const STALE_HOURS = 3;

    public function index(): JsonResponse
    {
        Gate::authorize('settings.manage');

        $this->expireStale();

        $exports = TenantExport::query()->latest('id')->limit(10)->get();

        return response()->json([
            'data' => $exports->map(fn (TenantExport $export): array => $export->present())->values(),
            'expires_days' => TenantExport::EXPIRES_DAYS,
        ]);
    }

    public function store(Request $request, CurrentTenant $current): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = $current->get();
        $lock = Cache::lock('tenant-export:'.$tenant->id, 10);
        if (! $lock->get()) {
            $this->refuse(409, 'export-in-progress', 'Export in progress', __('export.in_progress'));
        }

        try {
            $this->expireStale();

            if (TenantExport::query()->whereIn('status', [TenantExport::QUEUED, TenantExport::RUNNING])->exists()) {
                $this->refuse(409, 'export-in-progress', 'Export in progress', __('export.in_progress'));
            }

            if (TenantExport::query()->where('created_at', '>=', now()->subDay())->count() >= self::DAILY_LIMIT) {
                $this->refuse(429, 'export-limit', 'Export limit reached', __('export.daily_limit', ['count' => self::DAILY_LIMIT]));
            }

            $export = TenantExport::query()->create([
                'tenant_id' => $tenant->id,
                'requested_by' => $request->user()?->id,
                'status' => TenantExport::QUEUED,
            ]);
        } finally {
            $lock->release();
        }

        AuditLog::record('settings.data_export_requested', $export);
        ExportTenantDataJob::dispatch($tenant->id, $export->id);

        return response()->json($export->refresh()->present(), 202);
    }

    public function download(string $export): StreamedResponse
    {
        Gate::authorize('settings.manage');

        $row = TenantExport::query()->where('public_id', $export)->firstOrFail();

        if ($row->status === TenantExport::EXPIRED || ($row->expires_at !== null && $row->expires_at->isPast())) {
            $this->refuse(410, 'export-expired', 'Export expired', __('export.expired'));
        }

        $disk = Storage::disk(config('filesystems.default'));
        if ($row->status !== TenantExport::READY || $row->path === null || ! $disk->exists($row->path)) {
            $this->refuse(409, 'export-not-ready', 'Export not ready', __('export.not_ready'));
        }

        AuditLog::record('settings.data_export_downloaded', $row);

        $extension = str_ends_with($row->path, '.tar.gz') ? 'tar.gz' : 'zip';

        return $disk->download($row->path, 'ethr-export-'.$row->created_at?->format('Y-m-d').'.'.$extension);
    }

    /** Queued or running exports whose worker evidently died are marked failed. */
    private function expireStale(): void
    {
        TenantExport::query()
            ->whereIn('status', [TenantExport::QUEUED, TenantExport::RUNNING])
            ->where('updated_at', '<', now()->subHours(self::STALE_HOURS))
            ->update(['status' => TenantExport::FAILED, 'error' => 'Stopped before it finished.']);
    }

    private function refuse(int $status, string $type, string $title, string $detail): never
    {
        throw new HttpResponseException(response()->json([
            'type' => 'https://ethr.et/errors/'.$type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status)->header('Content-Type', 'application/problem+json'));
    }
}
