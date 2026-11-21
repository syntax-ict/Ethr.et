<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Device;
use App\Services\Device\DeviceCaptureRedactor;
use App\Services\Device\HeartbeatCapture;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * What one terminal really sent, for writing a fixture from it: the last
 * seven days of its inbox and the heartbeat bodies kept for a day, with every
 * name, number, face field and token replaced (DeviceCaptureRedactor).
 *
 * Platform operator only, behind the console's guard and MFA, and audited
 * with the counts it handed over. The terminal belongs to an organisation the
 * operator is not acting as, so it is found by its public id across
 * organisations, and the inbox is read with that organisation stated.
 */
class AdminDeviceCaptureController extends Controller
{
    /** The most inbox rows one export carries. */
    private const MAX_ROWS = 2000;

    #[ExcludeRouteFromDocs]
    public function show(string $devicePublicId): JsonResponse
    {
        Gate::authorize('admin.manage');

        $device = Device::withoutGlobalScope('tenant')
            ->where('public_id', $devicePublicId)
            ->firstOrFail();

        $redactor = new DeviceCaptureRedactor;

        $rows = DB::table('device_inbound_punches')
            ->where('tenant_id', $device->tenant_id)
            ->where('device_id', $device->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (object $row): array => [
                'received_at' => (string) $row->created_at,
                'badge' => $redactor->badge((string) $row->badge),
                'punch_type' => (string) $row->punch_type,
                'occurred_raw' => (string) $row->occurred_raw,
                'handled' => $row->processed_at !== null,
                'failure' => $row->failure,
                'attempts' => (int) $row->attempts,
                'raw' => $redactor->raw($row->raw === null ? null : (string) $row->raw, $row->failure === null ? null : (string) $row->failure),
            ])
            ->values()
            ->all();

        $heartbeats = array_map(
            fn (array $beat): array => [
                'at' => $beat['at'],
                'body' => $redactor->raw($beat['body']),
            ],
            HeartbeatCapture::all($device),
        );

        $events = array_map(
            fn (array $event): array => [
                'at' => $event['at'],
                'body' => $redactor->raw($event['body']),
            ],
            HeartbeatCapture::events($device),
        );

        AuditLog::record('platform.device_capture_exported', $device, [
            'device' => $device->public_id,
            'rows' => count($rows),
            'heartbeats' => count($heartbeats),
            'events' => count($events),
        ]);

        return response()->json([
            'device' => [
                'adapter_type' => $device->adapter_type,
                'timezone' => $device->timezone,
            ],
            'redacted' => true,
            'inbox' => $rows,
            'heartbeats' => $heartbeats,
            'events' => $events,
        ]);
    }
}
