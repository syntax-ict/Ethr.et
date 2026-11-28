<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Leave\LeaveDayCalculator;
use App\Services\Leave\LeaveYear;
use Carbon\Carbon;

/**
 * A tenant with an employee, their login, an HR approver and an annual leave
 * type, for tests/Feature/Idempotency and tests/Concurrency. Static so no
 * global function name can collide with another test file.
 */
final class IdempotencyLeaveFixtures
{
    /** A Monday to Friday week in the future, so no notice rule and no closed period applies. */
    public const START = '2026-12-07';

    public const END = '2026-12-11';

    /**
     * @return array{tenant: Tenant, employee: Employee, person: User, hr: User, type: LeaveType}
     */
    public static function tenant(string $code = 'idem_annual'): array
    {
        $tenant = createTenant();
        $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2024-01-01', 'status' => 'confirmed']);
        $person = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
        $type = LeaveType::factory()->create([
            'tenant_id' => $tenant->id,
            'code' => $code,
            'is_paid' => true,
            'default_days' => 16,
            'min_notice_days' => 0,
        ]);

        return compact('tenant', 'employee', 'person', 'hr', 'type');
    }

    public static function days(Employee $employee, string $start = self::START, string $end = self::END): float
    {
        return app(LeaveDayCalculator::class)->calculateDays(Carbon::parse($start), Carbon::parse($end), $employee->tenant_id, $employee->branch_id, $employee);
    }

    public static function balance(Employee $employee, LeaveType $type, float $entitled = 16, float $used = 0, float $pending = 0, string $start = self::START): LeaveBalance
    {
        return LeaveBalance::query()->create([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => LeaveYear::forTenantId($employee->tenant_id)->yearOf(Carbon::parse($start)),
            'entitled_days' => $entitled,
            'used_days' => $used,
            'carried_days' => 0,
            'pending_days' => $pending,
        ]);
    }

    public static function request(Employee $employee, LeaveType $type, LeaveStatus $status, float $days, string $start = self::START, string $end = self::END): LeaveRequest
    {
        return LeaveRequest::factory()->create([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => $start,
            'end_date' => $end,
            'days' => $days,
            'status' => $status,
        ]);
    }

    public static function url(Tenant $tenant, string $path): string
    {
        return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
    }

    /** @return array{used: float, pending: float, entitled: float} */
    public static function figures(LeaveBalance $balance): array
    {
        $fresh = $balance->fresh();

        return [
            'used' => (float) $fresh->used_days,
            'pending' => (float) $fresh->pending_days,
            'entitled' => (float) $fresh->entitled_days,
        ];
    }
}
