<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\ListQuarantineRequest;
use App\Http\Requests\Device\QuarantineRowsRequest;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceInboundPunch;
use App\Services\CurrentTenant;
use App\Services\Device\DeviceQuarantine;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Punches a terminal sent that ETHR could not record (DeviceQuarantine).
 * Anyone who may see the device sees the list; what the terminal sent
 * (`raw`), and replaying or dismissing, are for those who may change it.
 */
class DeviceQuarantineController extends Controller
{
    public function __construct(private readonly DeviceQuarantine $quarantine) {}

    public function index(ListQuarantineRequest $request, Device $device): JsonResponse
    {
        Gate::authorize('device.view');

        $withRaw = Gate::allows('device.update');

        $page = $this->quarantine->inQuarantine($this->tenantId())
            ->where('device_id', $device->id)
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        $page->through(fn (DeviceInboundPunch $row) => [
            'public_id' => $row->public_id,
            'badge' => $row->badge,
            'punch_type' => $row->punch_type,
            'occurred_raw' => $row->occurred_raw,
            'failure' => $row->failure,
            'error' => $withRaw ? $row->error : null,
            'failed_at' => $row->failed_at?->toIso8601String(),
            'received_at' => $row->created_at?->toIso8601String(),
            'raw' => $withRaw ? $row->raw : null,
        ]);

        return response()->json($page);
    }

    public function replay(QuarantineRowsRequest $request): JsonResponse
    {
        Gate::authorize('device.update');

        $ids = array_values($request->validated('ids'));
        $result = $this->quarantine->replay($this->tenantId(), $ids);

        AuditLog::record('device.quarantine_replayed', null, [
            'requested' => count($ids),
            'replayed' => $result['replayed'],
            'skipped' => $result['skipped'],
            'devices' => $result['devices'],
            'rows' => $result['ids'],
        ]);

        return response()->json(['replayed' => $result['replayed'], 'skipped' => $result['skipped']]);
    }

    public function dismiss(QuarantineRowsRequest $request): JsonResponse
    {
        Gate::authorize('device.update');

        $ids = array_values($request->validated('ids'));
        $result = $this->quarantine->dismiss($this->tenantId(), $ids);

        AuditLog::record('device.quarantine_dismissed', null, [
            'requested' => count($ids),
            'dismissed' => $result['dismissed'],
            'devices' => $result['devices'],
            'rows' => $result['ids'],
        ]);

        return response()->json(['dismissed' => $result['dismissed']]);
    }

    private function tenantId(): int
    {
        $tenant = app(CurrentTenant::class)->get();
        abort_if($tenant === null, 403);

        return $tenant->id;
    }
}
