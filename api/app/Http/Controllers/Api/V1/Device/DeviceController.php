<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Enums\AttendanceSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreDeviceRequest;
use App\Http\Requests\Device\UpdateDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\DeviceSyncLogResource;
use App\Jobs\PullDeviceEventsJob;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceSyncLog;
use App\Models\Employee;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Device\DeviceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceManager $deviceManager,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('device.viewAny');

        $query = Device::query()->with('branch')->withCount(['attendanceRecords', 'syncLogs']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhere('location_description', 'like', "%{$search}%");
            });
        }

        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        if ($request->has('filter.adapter_type')) {
            $query->where('adapter_type', $request->input('filter.adapter_type'));
        }

        if ($request->filled('filter.branch_public_id')) {
            $branch = Branch::where('public_id', $request->input('filter.branch_public_id'))->first();
            if ($branch) {
                $query->where('branch_id', $branch->id);
            }
        }

        if ($request->has('filter.auto_sync')) {
            $query->where('auto_sync', $request->boolean('filter.auto_sync'));
        }

        $query->orderBy('name');

        return DeviceResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreDeviceRequest $request): JsonResponse
    {
        Gate::authorize('device.create');

        $branch = Branch::where('public_id', $request->validated('branch_public_id'))->firstOrFail();

        $device = Device::create([
            'name' => $request->validated('name'),
            'location_description' => $request->validated('location_description'),
            'adapter_type' => $request->validated('adapter_type'),
            'branch_id' => $branch->id,
            'serial_number' => $request->validated('serial_number'),
            'connection_config' => $request->validated('connection_config'),
            'auto_sync' => $request->validated('auto_sync', true),
            'sync_interval_minutes' => $request->validated('sync_interval_minutes', 5),
            'webhook_token' => Device::generateWebhookToken(),
            'status' => 'pending',
        ]);

        AuditLog::record('device.created', $device);

        $device->load('branch');
        $device->loadCount(['attendanceRecords', 'syncLogs']);

        return (new DeviceResource($device))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Device $device): DeviceResource
    {
        Gate::authorize('device.view');

        $device->load('branch');
        $device->loadCount(['attendanceRecords', 'syncLogs']);

        return new DeviceResource($device);
    }

    public function update(UpdateDeviceRequest $request, Device $device): DeviceResource
    {
        Gate::authorize('device.update');

        $data = $request->validated();

        if (isset($data['branch_public_id'])) {
            $branch = Branch::where('public_id', $data['branch_public_id'])->firstOrFail();
            $data['branch_id'] = $branch->id;
            unset($data['branch_public_id']);
        }

        $device->update($data);

        AuditLog::record('device.updated', $device);

        $device->load('branch');
        $device->loadCount(['attendanceRecords', 'syncLogs']);

        return new DeviceResource($device);
    }

    public function destroy(Device $device): JsonResponse
    {
        Gate::authorize('device.delete');

        AuditLog::record('device.deleted', $device);
        $device->delete();

        return response()->json(null, 204);
    }

    public function status(Device $device): JsonResponse
    {
        Gate::authorize('device.view');

        $adapter = $this->deviceManager->adapter($device);
        $status = $adapter->getStatus($device);
        $info = $adapter->getDeviceInfo($device);

        $newStatus = ($status['online'] ?? false) ? 'online' : 'offline';
        if ($device->status !== $newStatus) {
            $device->update(['status' => $newStatus]);
        }

        return response()->json([
            'device_public_id' => $device->public_id,
            'status' => $newStatus,
            'details' => $status,
            'device_info' => $info,
        ]);
    }

    public function pull(Request $request, Device $device): JsonResponse
    {
        Gate::authorize('device.update');

        PullDeviceEventsJob::dispatch($device, 'manual');

        return response()->json([
            'message' => __('device.pull_dispatched'),
            'device_public_id' => $device->public_id,
        ]);
    }

    public function syncAll(Request $request): JsonResponse
    {
        Gate::authorize('device.update');

        $devices = Device::query()
            ->whereIn('status', ['online', 'pending'])
            ->get();

        $dispatched = 0;
        foreach ($devices as $device) {
            PullDeviceEventsJob::dispatch($device, 'manual');
            $dispatched++;
        }

        return response()->json([
            'message' => "Sync dispatched for {$dispatched} device(s)",
            'dispatched' => $dispatched,
        ]);
    }

    public function regenerateToken(Device $device): JsonResponse
    {
        Gate::authorize('device.update');

        $device->update(['webhook_token' => Device::generateWebhookToken()]);

        AuditLog::record('device.token_regenerated', $device);

        return response()->json([
            'webhook_token' => $device->webhook_token,
            'message' => 'Webhook token regenerated',
        ]);
    }

    public function syncLogs(Request $request, Device $device): AnonymousResourceCollection
    {
        Gate::authorize('device.view');

        $logs = DeviceSyncLog::where('device_id', $device->id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return DeviceSyncLogResource::collection($logs);
    }

    public function deviceEvents(Request $request, Device $device): JsonResponse
    {
        Gate::authorize('device.view');

        $query = AttendanceRecord::where('device_id', $device->id)
            ->with('employee:id,public_id,first_name,last_name,employee_code')
            ->orderByDesc('created_at');

        if ($request->filled('filter.date')) {
            $query->whereDate('date', $request->input('filter.date'));
        }

        $records = $query->paginate($request->integer('per_page', 25));

        $data = $records->through(fn ($r) => [
            'public_id' => $r->public_id,
            'employee_name' => trim(($r->employee->first_name ?? '').' '.($r->employee->last_name ?? '')),
            'employee_code' => $r->employee->employee_code ?? null,
            'date' => $r->date?->format('Y-m-d'),
            'check_in' => $r->check_in?->toIso8601String(),
            'check_out' => $r->check_out?->toIso8601String(),
            'source' => $r->source->value ?? $r->source,
            'status' => $r->status->value ?? $r->status,
            'confidence_score' => $r->confidence_score,
            'created_at' => $r->created_at,
        ]);

        return response()->json($data);
    }

    public function dashboard(Request $request): JsonResponse
    {
        Gate::authorize('device.viewAny');

        $devices = Device::query()->get();

        $totalEvents = AttendanceRecord::query()
            ->where('source', AttendanceSource::BIOMETRIC)
            ->whereDate('date', now()->format('Y-m-d'))
            ->count();

        $lastSync = DeviceSyncLog::query()
            ->orderByDesc('created_at')
            ->first();

        $recentSyncs = DeviceSyncLog::query()
            ->where('created_at', '>=', now()->subHours(24))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'total' => $devices->count(),
            'online' => $devices->where('status', 'online')->count(),
            'offline' => $devices->where('status', 'offline')->count(),
            'error' => $devices->where('status', 'error')->count(),
            'pending' => $devices->where('status', 'pending')->count(),
            'auto_sync_enabled' => $devices->where('auto_sync', true)->count(),
            'events_today' => $totalEvents,
            'last_sync_at' => $lastSync?->created_at?->toIso8601String(),
            'sync_stats_24h' => [
                'success' => $recentSyncs->get('success', 0),
                'partial' => $recentSyncs->get('partial', 0),
                'failed' => $recentSyncs->get('failed', 0),
                'offline' => $recentSyncs->get('offline', 0),
            ],
        ]);
    }

    // --- Webhook endpoints (called without auth, verified by token) ---

    public function webhookHikvision(Request $request): JsonResponse
    {
        $device = $this->resolveWebhookDevice($request, 'hikvision');
        if (! $device) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $payload = $request->all();
        $event = $payload['AccessControllerEvent'] ?? $payload;

        $badge = (string) ($event['employeeNoString'] ?? $event['cardNo'] ?? '');
        $timestamp = $event['time'] ?? $event['dateTime'] ?? now()->toIso8601String();

        if (empty($badge)) {
            return response()->json(['status' => 'no_badge'], 200);
        }

        $employee = Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $device->tenant_id)
            ->where(function ($q) use ($badge) {
                $q->where('badge_number', $badge)
                    ->orWhere('employee_code', $badge);
            })
            ->first();

        if (! $employee) {
            return response()->json(['status' => 'employee_not_found'], 200);
        }

        $idempotencyKey = "webhook:hik:{$device->id}:{$badge}:{$timestamp}";

        $engine = app(AttendanceEngine::class);
        $engine->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $device->tenant_id,
            source: AttendanceSource::BIOMETRIC,
            type: 'check_in',
            idempotencyKey: $idempotencyKey,
            deviceId: $device->id,
        ));

        $device->update(['last_sync_at' => now(), 'status' => 'online']);

        return response()->json(['status' => 'processed'], 200);
    }

    public function webhookZkteco(Request $request): JsonResponse
    {
        $device = $this->resolveWebhookDevice($request, 'zkteco');
        if (! $device) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $payload = $request->all();
        $records = $payload['records'] ?? [$payload];

        $engine = app(AttendanceEngine::class);
        $processed = 0;

        foreach ($records as $record) {
            $badge = (string) ($record['pin'] ?? $record['user_id'] ?? '');
            $timestamp = $record['timestamp'] ?? $record['datetime'] ?? now()->toIso8601String();
            $punchType = match ((int) ($record['punch'] ?? $record['status'] ?? 0)) {
                1, 5 => 'check_out',
                default => 'check_in',
            };

            if (empty($badge)) {
                continue;
            }

            $employee = Employee::withoutGlobalScope('tenant')
                ->where('tenant_id', $device->tenant_id)
                ->where(function ($q) use ($badge) {
                    $q->where('badge_number', $badge)
                        ->orWhere('employee_code', $badge);
                })
                ->first();

            if (! $employee) {
                continue;
            }

            $idempotencyKey = "webhook:zk:{$device->id}:{$badge}:{$timestamp}";

            try {
                $engine->record(new AttendanceInput(
                    employeeId: $employee->id,
                    tenantId: $device->tenant_id,
                    source: AttendanceSource::BIOMETRIC,
                    type: $punchType,
                    idempotencyKey: $idempotencyKey,
                    deviceId: $device->id,
                ));
                $processed++;
            } catch (\Throwable) {
                continue;
            }
        }

        $device->update(['last_sync_at' => now(), 'status' => 'online']);

        return response()->json(['status' => 'processed', 'count' => $processed], 200);
    }

    public function webhookSuprema(Request $request): JsonResponse
    {
        $device = $this->resolveWebhookDevice($request, 'suprema');
        if (! $device) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $payload = $request->all();
        $events = $payload['EventCollection'] ?? $payload['events'] ?? [$payload];

        $engine = app(AttendanceEngine::class);
        $processed = 0;

        foreach ($events as $event) {
            $badge = (string) ($event['user_id'] ?? $event['user']['user_id'] ?? '');
            $timestamp = $event['datetime'] ?? $event['timestamp'] ?? now()->toIso8601String();
            $eventTypeId = (int) ($event['event_type_id'] ?? 0);
            $type = ($eventTypeId >= 0x2000 && $eventTypeId < 0x3000) ? 'check_out' : 'check_in';

            if (empty($badge)) {
                continue;
            }

            $employee = Employee::withoutGlobalScope('tenant')
                ->where('tenant_id', $device->tenant_id)
                ->where(function ($q) use ($badge) {
                    $q->where('badge_number', $badge)
                        ->orWhere('employee_code', $badge);
                })
                ->first();

            if (! $employee) {
                continue;
            }

            $idempotencyKey = "webhook:sup:{$device->id}:{$badge}:{$timestamp}";

            try {
                $engine->record(new AttendanceInput(
                    employeeId: $employee->id,
                    tenantId: $device->tenant_id,
                    source: AttendanceSource::BIOMETRIC,
                    type: $type,
                    idempotencyKey: $idempotencyKey,
                    deviceId: $device->id,
                ));
                $processed++;
            } catch (\Throwable) {
                continue;
            }
        }

        $device->update(['last_sync_at' => now(), 'status' => 'online']);

        return response()->json(['status' => 'processed', 'count' => $processed], 200);
    }

    private function resolveWebhookDevice(Request $request, string $adapterType): ?Device
    {
        $token = $request->query('token') ?? $request->header('X-Webhook-Token');

        if (! $token) {
            $serialNumber = $request->input('sn')
                ?? $request->input('deviceSerialNo')
                ?? $request->input('AccessControllerEvent.deviceSerialNo');

            if ($serialNumber) {
                return Device::withoutGlobalScope('tenant')
                    ->where('serial_number', $serialNumber)
                    ->where('adapter_type', $adapterType)
                    ->first();
            }

            return null;
        }

        return Device::withoutGlobalScope('tenant')
            ->where('webhook_token', $token)
            ->where('adapter_type', $adapterType)
            ->first();
    }
}
