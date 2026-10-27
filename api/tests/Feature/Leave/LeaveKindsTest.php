<?php

declare(strict_types=1);

use App\Enums\ExpectedDay;
use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Services\Attendance\ExpectedDays;
use App\Services\Onboarding\LeaveTypeCatalog;
use App\Services\Onboarding\OrganizationProvisioner;
use Carbon\Carbon;

// Attendance → payroll reconciliation, PR B (2026-10-27). An absence found
// while reconciling is settled through leave, the request already on the
// approval engine: HR files it on the employee's behalf, for a day already
// gone, as unpaid (deducted by the existing unpaid-leave step), excused or a
// business trip. Duty and excused types draw on no balance and are not leave
// taken.

function leaveKindsUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

function leaveKindsType(Tenant $tenant, array $attributes): LeaveType
{
    return LeaveType::factory()->create([
        'tenant_id' => $tenant->id,
        'default_days' => 0,
        'min_notice_days' => 14,
        ...$attributes,
    ]);
}

beforeEach(fn () => Carbon::setTestNow('2026-06-20 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

describe('HR filing on an employee\'s behalf', function () {
    it('records an unpaid day already gone, which the 0-day unpaid type used to refuse', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2020-01-01']);
        $unpaid = leaveKindsType($tenant, ['code' => 'unpaid', 'is_paid' => false]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->postJson(leaveKindsUrl($tenant, 'leave/request'), [
            'leave_type_public_id' => $unpaid->public_id,
            'employee_public_id' => $employee->public_id,
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-15',
            'reason' => 'Absent without notice, settled as unpaid.',
        ])->assertCreated()
            ->assertJsonPath('status', 'pending');

        expect(LeaveRequest::where('employee_id', $employee->id)->count())->toBe(1);
    });

    it('cannot be approved by the HR admin who filed it', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2020-01-01']);
        $unpaid = leaveKindsType($tenant, ['code' => 'unpaid', 'is_paid' => false]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $publicId = test()->postJson(leaveKindsUrl($tenant, 'leave/request'), [
            'leave_type_public_id' => $unpaid->public_id,
            'employee_public_id' => $employee->public_id,
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-15',
        ])->assertCreated()->json('public_id');

        test()->putJson(leaveKindsUrl($tenant, "leave/{$publicId}/approve"))->assertForbidden();

        expect(LeaveRequest::where('public_id', $publicId)->first()->status)->toBe(LeaveStatus::PENDING);
    });

    it('is refused to an employee filing for a colleague', function () {
        $tenant = createTenant();
        $self = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $colleague = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $duty = leaveKindsType($tenant, ['code' => 'official_duty', 'kind' => 'duty']);
        test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $self->id], $tenant));

        test()->postJson(leaveKindsUrl($tenant, 'leave/request'), [
            'leave_type_public_id' => $duty->public_id,
            'employee_public_id' => $colleague->public_id,
            'start_date' => '2026-06-22',
            'end_date' => '2026-06-22',
        ])->assertForbidden();
    });

    it('still refuses an employee a start date in the past for themselves', function () {
        $tenant = createTenant();
        $self = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $duty = leaveKindsType($tenant, ['code' => 'official_duty', 'kind' => 'duty', 'min_notice_days' => 0]);
        test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $self->id], $tenant));

        test()->postJson(leaveKindsUrl($tenant, 'leave/request'), [
            'leave_type_public_id' => $duty->public_id,
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-15',
        ])->assertStatus(422)->assertJsonValidationErrors('start_date');
    });
});

describe('a business trip', function () {
    it('draws on no balance, and once approved the day is not an absence', function () {
        $tenant = createTenant();
        $self = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2020-01-01']);
        $duty = leaveKindsType($tenant, ['code' => 'official_duty', 'kind' => 'duty', 'min_notice_days' => 0]);
        test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $self->id], $tenant));

        $publicId = test()->postJson(leaveKindsUrl($tenant, 'leave/request'), [
            'leave_type_public_id' => $duty->public_id,
            'start_date' => '2026-06-22',
            'end_date' => '2026-06-23',
            'reason' => 'Site visit in Hawassa.',
        ])->assertCreated()
            ->assertJsonPath('leave_type.kind', 'duty')
            ->json('public_id');

        LeaveRequest::where('public_id', $publicId)->update(['status' => LeaveStatus::APPROVED]);

        $days = app(ExpectedDays::class)->forEmployee($self->fresh(), Carbon::parse('2026-06-22'), Carbon::parse('2026-06-22'));

        expect($days['2026-06-22'])->toBe(ExpectedDay::ON_LEAVE);
    });

    it('is a time-off type nowhere: an annual type still needs the balance', function () {
        $tenant = createTenant();
        $self = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2020-01-01']);
        $annual = leaveKindsType($tenant, ['code' => 'annual_zero', 'is_paid' => true, 'min_notice_days' => 0]);
        test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $self->id], $tenant));

        test()->postJson(leaveKindsUrl($tenant, 'leave/request'), [
            'leave_type_public_id' => $annual->public_id,
            'start_date' => '2026-06-22',
            'end_date' => '2026-06-22',
        ])->assertStatus(422)->assertJsonPath('type', 'https://ethr.et/errors/insufficient-balance');
    });
});

it('gives an organisation that signed up with no template the types that settle an absence', function () {
    $tenant = createTenant();

    app(OrganizationProvisioner::class)->ensureSettlingLeaveTypes($tenant);
    app(OrganizationProvisioner::class)->ensureSettlingLeaveTypes($tenant);

    expect(LeaveType::query()->pluck('kind', 'code')->all())
        ->toEqual(['official_duty' => 'duty', 'excused' => 'excused', 'unpaid' => 'leave']);
});

it('adds no unpaid type to an organisation that has its own', function () {
    $tenant = createTenant();
    leaveKindsType($tenant, ['code' => 'lwop', 'is_paid' => false]);

    app(OrganizationProvisioner::class)->ensureSettlingLeaveTypes($tenant);

    expect(LeaveType::query()->pluck('code')->sort()->values()->all())
        ->toBe(['excused', 'lwop', 'official_duty']);
});

it('gives every new organisation the trip and excused-absence types', function () {
    $tenant = createTenant();

    app(OrganizationProvisioner::class)->apply($tenant, ['leave_types' => ['annual']]);

    $kinds = LeaveType::query()->pluck('kind', 'code')->all();

    expect($kinds)->toMatchArray(['annual' => 'leave', 'official_duty' => 'duty', 'excused' => 'excused'])
        ->and(LeaveTypeCatalog::ALWAYS)->toBe(['official_duty', 'excused']);
});
