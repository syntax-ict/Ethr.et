<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\EmployeeStatus;
use App\Exceptions\DeviceBindingRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\DecideAttendanceDeviceRequest;
use App\Http\Requests\Attendance\ListAttendanceDevicesRequest;
use App\Http\Requests\Attendance\ListUnregisteredEmployeesRequest;
use App\Http\Requests\Attendance\RegisterAttendanceDeviceRequest;
use App\Http\Requests\Attendance\UpdateAttendanceDeviceExemptionRequest;
use App\Http\Resources\AttendanceDeviceResource;
use App\Http\Resources\UnregisteredEmployeeResource;
use App\Models\AttendanceDevice;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\Attendance\Device\AttendanceDeviceGate;
use App\Services\Attendance\Device\DeviceVerdict;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The phone an employee punches with (AttendanceDeviceGate): registering it,
 * signing a punch with it, and the people who approve and revoke phones.
 */
class AttendanceDeviceController extends Controller
{
    public function __construct(private readonly AttendanceDeviceGate $gate) {}

    /** GET /attendance/device: the caller's phone and the rule that applies to them. */
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('attendance.checkIn');

        $employee = $this->employee($request);
        if ($employee === null) {
            return $this->noEmployee();
        }

        $rpId = $this->gate->rpId();
        $active = $this->gate->deviceFor($employee, AttendanceDevice::ACTIVE);
        $pending = $this->gate->deviceFor($employee, AttendanceDevice::PENDING);
        $hostAllowed = $this->gate->hostAllowed($request, $rpId);
        $until = $employee->attendance_device_exempt_until;

        return response()->json([
            'binding' => $this->gate->binding($employee),
            'rp_id' => $rpId,
            'host_allowed' => $hostAllowed,
            'canonical_url' => $hostAllowed ? null : $this->gate->canonicalUrl($employee),
            'device' => $active === null ? null : (new AttendanceDeviceResource($active))->resolve(),
            'pending' => $pending === null ? null : (new AttendanceDeviceResource($pending))->resolve(),
            'exempt_until' => $until?->format('Y-m-d'),
        ]);
    }

    /** POST /attendance/device/register-options: what the phone needs to create its passkey. */
    public function registerOptions(Request $request): JsonResponse
    {
        Gate::authorize('attendance.checkIn');

        $employee = $this->employee($request);
        if ($employee === null) {
            return $this->noEmployee();
        }

        return response()->json(['options' => $this->gate->registrationOptions($employee, $request)]);
    }

    /** POST /attendance/device/register: keep the passkey the phone created. */
    public function register(RegisterAttendanceDeviceRequest $request): JsonResponse
    {
        Gate::authorize('attendance.checkIn');

        $employee = $this->employee($request);
        if ($employee === null) {
            return $this->noEmployee();
        }

        $num = static fn (mixed $value): ?float => is_numeric($value) ? (float) $value : null;
        $device = $this->gate->register(
            $employee,
            $request,
            (array) $request->validated('credential'),
            $num($request->validated('latitude')),
            $num($request->validated('longitude')),
            $num($request->validated('accuracy')),
            $request->validated('device_id'),
            $request->validated('label'),
        );

        return (new AttendanceDeviceResource($device))->response()->setStatusCode(201);
    }

    /** POST /attendance/device/challenge: a single-use challenge for signing one punch. */
    public function challenge(Request $request): JsonResponse
    {
        Gate::authorize('attendance.checkIn');

        $employee = $this->employee($request);
        if ($employee === null) {
            return $this->noEmployee();
        }

        if ($this->gate->deviceFor($employee, AttendanceDevice::ACTIVE) === null) {
            throw DeviceBindingRefused::from(new DeviceVerdict(
                $this->gate->deviceFor($employee, AttendanceDevice::PENDING) === null ? DeviceVerdict::UNREGISTERED : DeviceVerdict::PENDING,
            ));
        }

        return response()->json(['options' => $this->gate->authenticationOptions($employee)]);
    }

    /**
     * GET /attendance/devices: registered and waiting phones, with how many
     * people have one. A waiting replacement carries the phone it replaces.
     */
    public function index(ListAttendanceDevicesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('attendance.manage');

        $search = trim((string) $request->validated('search'));
        $status = $request->validated('status');
        $devices = AttendanceDevice::query()
            ->with(['employee:id,public_id,name,employee_code', 'replacing'])
            ->when($status === 'closed', fn ($q) => $q->whereIn('status', [AttendanceDevice::REVOKED, AttendanceDevice::REJECTED]))
            ->when($status !== null && $status !== 'closed', fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->whereHas('employee', fn ($e) => $e
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('employee_code', 'like', '%'.$search.'%')))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->latest('id')
            ->paginate((int) ($request->validated('per_page') ?? 25));

        // Counted over the people who work here now, so a leaver's phone never
        // makes the coverage look better than it is.
        $current = Employee::query()->whereIn('status', EmployeeStatus::current())->select('id');
        $employees = Employee::query()->whereIn('status', EmployeeStatus::current())->count();
        $registered = AttendanceDevice::query()->where('status', AttendanceDevice::ACTIVE)->whereIn('employee_id', $current)->distinct()->count('employee_id');
        $pending = AttendanceDevice::query()->where('status', AttendanceDevice::PENDING)->whereIn('employee_id', $current)->count();
        $checked = $this->gate->employeesChecked()->count();
        $missing = $this->unregisteredQuery()->count();

        return AttendanceDeviceResource::collection($devices)->additional([
            'coverage' => [
                'employees' => $employees,
                'registered' => $registered,
                'pending' => $pending,
                'checked' => $checked,
                'missing' => $missing,
            ],
        ]);
    }

    /**
     * GET /attendance/devices/unregistered: the people whose phone is checked
     * and who have none registered yet, the ones a switch to enforce would
     * stop. Someone with a phone waiting for approval is marked as such.
     */
    public function unregistered(ListUnregisteredEmployeesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('attendance.manage');

        $search = trim((string) $request->validated('search'));
        $employees = $this->unregisteredQuery()
            ->with('branch:id,name')
            ->withExists(['attendanceDevices as has_pending_phone' => fn ($q) => $q->where('status', AttendanceDevice::PENDING)])
            ->when($search !== '', fn ($q) => $q->where(fn ($e) => $e
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('employee_code', 'like', '%'.$search.'%')))
            ->orderBy('name')
            ->paginate((int) ($request->validated('per_page') ?? 25));

        return UnregisteredEmployeeResource::collection($employees);
    }

    public function approve(Request $request, AttendanceDevice $device): AttendanceDeviceResource
    {
        Gate::authorize('attendance.manage');
        abort_unless($device->status === AttendanceDevice::PENDING, 409, __('attendance.device_not_pending'));

        return new AttendanceDeviceResource($this->gate->approve($device, (int) $request->user()->id)->load('employee'));
    }

    public function reject(DecideAttendanceDeviceRequest $request, AttendanceDevice $device): AttendanceDeviceResource
    {
        Gate::authorize('attendance.manage');
        abort_unless($device->status === AttendanceDevice::PENDING, 409, __('attendance.device_not_pending'));

        return new AttendanceDeviceResource($this->gate->reject($device, $request->reason())->load('employee'));
    }

    public function revoke(DecideAttendanceDeviceRequest $request, AttendanceDevice $device): AttendanceDeviceResource
    {
        Gate::authorize('attendance.manage');
        abort_unless($device->status === AttendanceDevice::ACTIVE, 409, __('attendance.device_not_active'));

        return new AttendanceDeviceResource($this->gate->revoke($device, $request->reason())->load('employee'));
    }

    /** PUT /employees/{employee}/attendance-device-exemption */
    public function exempt(UpdateAttendanceDeviceExemptionRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $until = $request->validated('until');
        $employee->forceFill(['attendance_device_exempt_until' => $until])->save();
        AuditLog::record('employee.attendance_device_exemption', $employee, ['until' => $until]);

        return response()->json([
            'public_id' => $employee->public_id,
            'exempt_until' => $employee->attendance_device_exempt_until?->format('Y-m-d'),
        ]);
    }

    /** @return Builder<Employee> */
    private function unregisteredQuery(): Builder
    {
        return $this->gate->employeesChecked()->whereNotIn('id', AttendanceDevice::query()
            ->where('status', AttendanceDevice::ACTIVE)
            ->select('employee_id'));
    }

    private function employee(Request $request): ?Employee
    {
        $employee = $request->user()?->employee;

        return $employee instanceof Employee ? $employee : null;
    }

    private function noEmployee(): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/not-found',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => __('attendance.no_employee_linked'),
        ], 404)->header('Content-Type', 'application/problem+json');
    }
}
