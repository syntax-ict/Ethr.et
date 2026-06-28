<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Enums\AttendanceSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreDeviceRequest;
use App\Http\Requests\Device\UpdateDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Jobs\PullDeviceEventsJob;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Device;
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

        $query = Device::query()->with('branch')->withCount('attendanceRecords');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%");
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
            'adapter_type' => $request->validated('adapter_type'),
            'branch_id' => $branch->id,
            'serial_number' => $request->validated('serial_number'),
            'connection_config' => $request->validated('connection_config'),
            'status' => 'pending',
        ]);

        AuditLog::record('device.created', $device);

        $device->load('branch');

        return (new DeviceResource($device))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Device $device): DeviceResource
    {
        Gate::authorize('device.view');

        $device->load('branch');
        $device->loadCount('attendanceRecords');

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

        $newStatus = ($status['online'] ?? false) ? 'online' : 'offline';
        if ($device->status !== $newStatus) {
            $device->update(['status' => $newStatus]);
        }

        return response()->json([
            'device_public_id' => $device->public_id,
            'status' => $newStatus,
            'details' => $status,
        ]);
    }

    public function pull(Device $device): JsonResponse
    {
        Gate::authorize('device.update');

        PullDeviceEventsJob::dispatch($device);

        return response()->json([
            'message' => __('device.pull_dispatched'),
            'device_public_id' => $device->public_id,
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        Gate::authorize('device.viewAny');

        $devices = Device::query()->get();

        $totalEvents = \App\Models\AttendanceRecord::query()
            ->where('source', AttendanceSource::BIOMETRIC)
            ->whereDate('date', now()->format('Y-m-d'))
            ->count();

        return response()->json([
            'total' => $devices->count(),
            'online' => $devices->where('status', 'online')->count(),
            'offline' => $devices->where('status', 'offline')->count(),
            'error' => $devices->where('status', 'error')->count(),
            'pending' => $devices->where('status', 'pending')->count(),
            'events_today' => $totalEvents,
        ]);
    }

    public function webhookHikvision(Request $request): JsonResponse
    {
        $payload = $request->all();

        $event = $payload['AccessControllerEvent'] ?? $payload;
        $serialNumber = $event['deviceSerialNo'] ?? $payload['deviceSerialNo'] ?? null;

        if (! $serialNumber) {
            return response()->json(['status' => 'ignored'], 200);
        }

        $device = Device::where('serial_number', $serialNumber)
            ->where('adapter_type', 'hikvision')
            ->first();

        if (! $device) {
            return response()->json(['status' => 'device_not_found'], 200);
        }

        $badge = (string) ($event['employeeNoString'] ?? $event['cardNo'] ?? '');
        $timestamp = $event['time'] ?? $event['dateTime'] ?? now()->toIso8601String();

        if (empty($badge)) {
            return response()->json(['status' => 'no_badge'], 200);
        }

        $employee = Employee::where('tenant_id', $device->tenant_id)
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

        $device->update(['last_sync_at' => now()]);

        return response()->json(['status' => 'processed'], 200);
    }

    public function webhookZkteco(Request $request): JsonResponse
    {
        $payload = $request->all();

        $serialNumber = $payload['sn'] ?? $payload['serial_number'] ?? null;

        if (! $serialNumber) {
            return response()->json(['status' => 'ignored'], 200);
        }

        $device = Device::where('serial_number', $serialNumber)
            ->where('adapter_type', 'zkteco')
            ->first();

        if (! $device) {
            return response()->json(['status' => 'device_not_found'], 200);
        }

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

            $employee = Employee::where('tenant_id', $device->tenant_id)
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

        $device->update(['last_sync_at' => now()]);

        return response()->json(['status' => 'processed', 'count' => $processed], 200);
    }
}
