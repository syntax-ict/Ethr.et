<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\LeaveStatus;
use App\Enums\OrgScope;
use App\Enums\ProfileUpdateStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CustomRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\EmployeeContract;
use App\Models\EmployeeEmergencyContact;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\NextStepDismissal;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\ProfileUpdateRequest;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Setup & Next Steps (GET /next-steps): one list, built from who the person is.
 *
 * An employee is shown how to finish their own profile, HR the employee records
 * that are incomplete, an administrator the configuration that is genuinely
 * unfinished and an approver the requests waiting on them. Each provider guards
 * itself, so the cases here are mostly about who must NOT see a step.
 */
afterEach(fn () => Carbon::setTestNow());

function nsUrl(Tenant $tenant, string $path = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/next-steps{$path}";
}

/** @return array{0: User, 1: Employee} */
function nsPerson(Tenant $tenant, UserRole $role, array $employee = []): array
{
    $record = Employee::factory()->create(['tenant_id' => $tenant->id, ...$employee]);
    $user = createUser(['role' => $role, 'employee_id' => $record->id], $tenant);
    test()->actingAs($user);

    return [$user, $record];
}

/** @return array<string, array<string, mixed>> the steps by id */
function nsSteps(Tenant $tenant): array
{
    return collect(test()->getJson(nsUrl($tenant))->assertOk()->json('steps'))->keyBy('id')->all();
}

function nsCompleteProfile(Employee $employee): void
{
    $employee->update(['photo_path' => 'photos/x.jpg', 'tin' => '0012345678']);
    EmployeeEmergencyContact::create([
        'tenant_id' => $employee->tenant_id,
        'employee_id' => $employee->id,
        'name' => 'Almaz Tesfaye',
        'relationship' => 'spouse',
        'phone' => '+251911223344',
        'priority' => 1,
    ]);
    EmployeeBankDetail::create([
        'tenant_id' => $employee->tenant_id,
        'employee_id' => $employee->id,
        'bank_name' => 'Awash Bank',
        'account_number' => '1000123456789',
        'is_primary' => true,
    ]);
}

function nsWithoutPayroll(Tenant $tenant): void
{
    $plan = Plan::factory()->create([
        'slug' => 'no-payroll',
        'features' => ['attendance', 'leave', 'employee_management'],
        'max_employees' => 999999,
        'max_branches' => 999,
        'max_devices' => 999,
    ]);
    Subscription::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);
    $tenant->update(['status' => TenantStatus::ACTIVE]);
    $tenant->refresh()->load('subscription.plan');
}

// ── An employee: finish my own profile ──

test('an employee is shown the parts of their own profile still missing, and nothing else', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::EMPLOYEE);

    $steps = nsSteps($tenant);

    expect(array_keys($steps))->toBe(['complete_profile'])
        ->and($steps['complete_profile']['group'])->toBe('profile')
        ->and($steps['complete_profile']['severity'])->toBe('info')
        ->and($steps['complete_profile']['items'])->toBe(['photo', 'emergency_contact', 'tin', 'bank'])
        ->and($steps['complete_profile']['count'])->toBe(4)
        ->and($steps['complete_profile']['total'])->toBe(6)
        ->and($steps['complete_profile']['href'])->toBe('/profile/personal');
});

test('a finished profile has no step', function () {
    $tenant = createTenant();
    [, $employee] = nsPerson($tenant, UserRole::EMPLOYEE);
    nsCompleteProfile($employee);

    expect(nsSteps($tenant))->toBe([]);
});

test('a field already waiting on HR is not asked for again', function () {
    $tenant = createTenant();
    [, $employee] = nsPerson($tenant, UserRole::EMPLOYEE);
    nsCompleteProfile($employee);
    $employee->update(['tin' => null]);

    expect(nsSteps($tenant)['complete_profile']['items'])->toBe(['tin']);

    ProfileUpdateRequest::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'field_name' => 'tin',
        'old_value' => '',
        'new_value' => '0012345678',
        'status' => ProfileUpdateStatus::PENDING,
    ]);

    expect(nsSteps($tenant))->toBe([]);
});

test('an organisation that does not run payroll is not asked for a TIN or a bank account', function () {
    $tenant = createTenant();
    nsWithoutPayroll($tenant);
    [, $employee] = nsPerson($tenant, UserRole::EMPLOYEE);

    $step = nsSteps($tenant)['complete_profile'];

    expect($step['items'])->toBe(['photo', 'emergency_contact'])
        ->and($step['total'])->toBe(4);
});

test('someone who has left is not asked for anything', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::EMPLOYEE, ['status' => EmployeeStatus::RESIGNED]);

    expect(nsSteps($tenant))->toBe([]);
});

test('an employee never sees records, setup or operations steps', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::EMPLOYEE);
    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id, 'phone' => null]);

    $response = test()->getJson(nsUrl($tenant))->assertOk();

    expect($response->json('setup'))->toBeNull()
        ->and(collect($response->json('steps'))->pluck('group')->unique()->all())->toBe(['profile']);
});

// ── HR: records that are incomplete ──

test('HR sees the employee records that are incomplete, and the list opens exactly those', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::HR_ADMIN);
    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id, 'phone' => null]);
    $complete = Employee::factory()->create(['tenant_id' => $tenant->id]);
    nsCompleteProfile($complete);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => null, 'status' => EmployeeStatus::RESIGNED]);

    $steps = nsSteps($tenant);

    expect($steps['records_missing_phone']['count'])->toBe(3)
        ->and($steps['records_missing_phone']['group'])->toBe('records')
        ->and($steps['records_missing_phone']['href'])->toBe('/employees?missing=phone')
        ->and($steps['records_missing_tin']['count'])->toBe(4)
        ->and($steps['records_missing_bank']['count'])->toBe(4)
        ->and($steps['records_missing_emergency_contact']['count'])->toBe(4);

    // Four, not five: the three without a phone and the HR admin's own record
    // lack the rest; the complete one and the resigned one are not counted.
    foreach (['phone' => 3, 'tin' => 4, 'bank' => 4, 'emergency_contact' => 4] as $gap => $count) {
        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees?filter[missing]={$gap}&per_page=100")
            ->assertOk()
            ->assertJsonPath('meta.total', $count);
    }
});

test('an unknown missing filter is ignored rather than trusted', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::HR_ADMIN);
    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees?filter[missing]=salary")
        ->assertOk()
        ->assertJsonPath('meta.total', 3);
});

test('the export holds exactly the records the missing filter lists', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::HR_ADMIN);
    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'phone' => null]);
    $base = "http://{$tenant->subdomain}.ethr.test/api/v1/employees";

    test()->getJson("{$base}/export?filter[missing]=phone")->assertOk()->assertJsonPath('count', 2);
    test()->getJson("{$base}?filter[missing]=phone")->assertOk()->assertJsonPath('meta.total', 2);

    nsPerson($tenant, UserRole::SUPERVISOR);
    test()->getJson("{$base}/export?filter[missing]=tin")->assertForbidden();
});

test('who has no TIN or bank account is pay data and needs the permission that edits it', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::SUPERVISOR);

    foreach (['tin', 'bank'] as $gap) {
        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees?filter[missing]={$gap}")
            ->assertForbidden();
    }
});

test('a person is only asked about gaps they could fill', function () {
    $tenant = createTenant();
    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'phone' => null]);

    // Finance reads pay data and runs payroll but cannot change employee records.
    nsPerson($tenant, UserRole::FINANCE_ADMIN);

    $steps = nsSteps($tenant);

    expect($steps)->not->toHaveKeys(['records_missing_phone', 'records_missing_tin', 'records_missing_bank']);
});

test('an organisation without payroll is not told its records lack a TIN or bank account', function () {
    $tenant = createTenant();
    nsWithoutPayroll($tenant);
    nsPerson($tenant, UserRole::HR_ADMIN);
    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id]);

    $steps = nsSteps($tenant);

    expect($steps)->not->toHaveKeys(['records_missing_tin', 'records_missing_bank'])
        ->and($steps)->toHaveKey('records_missing_emergency_contact');
});

test('record counts stop at the caller\'s org scope', function () {
    $tenant = createTenant();
    $mine = Department::factory()->create(['tenant_id' => $tenant->id]);
    $theirs = Department::factory()->create(['tenant_id' => $tenant->id]);
    $head = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id]);
    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id, 'phone' => null]);
    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id, 'department_id' => $theirs->id, 'phone' => null]);

    $role = CustomRole::create([
        'tenant_id' => $tenant->id,
        'name' => 'Department HR',
        'org_scope' => OrgScope::DEPARTMENT->value,
    ]);
    $role->permissions()->sync(Permission::whereIn('name', ['employee.viewAny', 'employee.update'])->pluck('id'));
    actingAsUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id, 'employee_id' => $head->id], $tenant);

    expect(nsSteps($tenant)['records_missing_phone']['count'])->toBe(2);
});

test('another organisation\'s employees are never counted', function () {
    $other = createTenant();
    Employee::factory()->count(4)->create(['tenant_id' => $other->id, 'phone' => null]);

    $tenant = createTenant();
    nsPerson($tenant, UserRole::HR_ADMIN);

    expect(nsSteps($tenant))->not->toHaveKey('records_missing_phone');
});

test('contracts ending soon surface for someone who manages employees', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-26 10:00', 'Africa/Addis_Ababa')->utc());
    $tenant = createTenant();
    nsPerson($tenant, UserRole::HR_ADMIN);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    EmployeeContract::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'end_date' => '2026-11-10',
    ]);
    EmployeeContract::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'end_date' => '2027-06-10',
    ]);

    $step = nsSteps($tenant)['contracts_ending'];

    expect($step['count'])->toBe(1)
        ->and($step['href'])->toBe('/employees/contracts');
});

// ── An approver: requests waiting on me ──

test('anyone who may decide a request is told how many wait, supervisor or not', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::HR_ADMIN);
    $someone = Employee::factory()->create(['tenant_id' => $tenant->id]);
    LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $someone->id,
        'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenant->id])->id,
        'status' => LeaveStatus::PENDING,
    ]);

    $queue = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/pending")->assertOk();
    $step = nsSteps($tenant)['approvals'];

    expect($step['count'])->toBe(1)
        ->and($step['group'])->toBe('approvals')
        ->and($step['severity'])->toBe('warning')
        ->and($step['href'])->toBe('/approvals')
        ->and($queue->json('total'))->toBe($step['count']);
});

test('an employee with nothing to decide has no approvals step', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::EMPLOYEE);

    expect(nsSteps($tenant))->not->toHaveKey('approvals');
});

test('a manager is told how many of their team are absent today', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-26 10:00', 'Africa/Addis_Ababa')->utc());
    $tenant = createTenant();
    [, $manager] = nsPerson($tenant, UserRole::SUPERVISOR);
    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'supervisor_id' => $manager->id]);

    $manager = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/manager")->assertOk()
        ->json('team_attendance.absent');
    $steps = nsSteps($tenant);

    // The dashboard's own figure, whatever the day's calendar makes it.
    if ($manager > 0) {
        expect($steps['team_absent']['count'])->toBe($manager);
    } else {
        expect($steps)->not->toHaveKey('team_absent');
    }
});

// ── An administrator: setup that is genuinely unfinished ──

test('an administrator is shown the configuration still missing, with the wizard progress', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::TENANT_ADMIN);

    $response = test()->getJson(nsUrl($tenant))->assertOk();
    $steps = collect($response->json('steps'))->keyBy('id');

    expect($steps->keys()->all())->toContain('setup_branch', 'setup_departments', 'setup_positions', 'setup_leave_types', 'setup_holidays', 'setup_wizard')
        ->and($steps->keys()->all())->not->toContain('setup_employees', 'setup_admin_present', 'setup_admin_active')
        ->and($steps['setup_branch']['group'])->toBe('setup')
        ->and($steps['setup_branch']['href'])->toBe('/organization')
        ->and($response->json('setup'))->toBe(['done' => 0, 'total' => 4, 'complete' => false]);
});

test('a configured organisation has no setup steps left', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::TENANT_ADMIN);

    $before = array_keys(nsSteps($tenant));
    expect($before)->toContain('setup_branch');

    Branch::factory()->create(['tenant_id' => $tenant->id]);
    $after = array_keys(nsSteps($tenant));

    expect($after)->not->toContain('setup_branch')
        ->and($after)->toContain('setup_departments');
});

test('a blocking attendance problem replaces the duplicate shifts step', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::TENANT_ADMIN);

    $steps = nsSteps($tenant);

    expect($steps)->toHaveKey('attendance_blockers')
        ->and($steps['attendance_blockers']['severity'])->toBe('blocker')
        ->and($steps)->not->toHaveKey('setup_shifts');
});

test('HR is not shown setup, which belongs to whoever may change settings', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::HR_ADMIN);

    $response = test()->getJson(nsUrl($tenant))->assertOk();

    expect($response->json('setup'))->toBeNull()
        ->and(collect($response->json('steps'))->pluck('group')->all())->not->toContain('setup');
});

test('a platform super admin belongs to no organisation and gets an empty list', function () {
    createTenant();
    $superAdmin = User::factory()->create(['tenant_id' => null, 'role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true]);
    test()->actingAs($superAdmin);
    app(CurrentTenant::class)->forget();

    test()->getJson('http://admin.ethr.test/api/v1/next-steps')
        ->assertOk()
        ->assertExactJson(['steps' => [], 'setup' => null]);
});

test('a payroll run that failed is a blocker for whoever processes payroll', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::FINANCE_ADMIN);
    PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'failed', 'period_label' => 'October 2026']);

    $step = nsSteps($tenant)['payroll_failed'];

    expect($step['severity'])->toBe('blocker')
        ->and($step['subject'])->toBe('October 2026');
});

test('only the newest payroll run decides, and only a run that needs a hand', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::FINANCE_ADMIN);
    PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'failed']);
    PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'approved']);

    expect(nsSteps($tenant))->not->toHaveKeys(['payroll_failed', 'payroll_approve']);
});

// ── Order ──

test('blockers come before warnings, and warnings before information', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::TENANT_ADMIN);
    PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'failed']);

    $severities = collect(test()->getJson(nsUrl($tenant))->assertOk()->json('steps'))->pluck('severity')->all();
    $rank = ['blocker' => 0, 'warning' => 1, 'info' => 2];
    $ranked = array_map(fn ($s) => $rank[$s], $severities);

    $sorted = $ranked;
    sort($sorted);

    expect($ranked)->toBe($sorted)
        ->and($severities[0])->toBe('blocker');
});

// ── Dismissing and snoozing ──

test('a dismissed step stays away until what is behind it changes', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::HR_ADMIN);
    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'phone' => null]);

    $signature = nsSteps($tenant)['records_missing_phone']['signature'];
    test()->postJson(nsUrl($tenant, '/records_missing_phone/dismiss'), ['signature' => $signature])->assertNoContent();

    expect(nsSteps($tenant))->not->toHaveKey('records_missing_phone');

    Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => null]);

    expect(nsSteps($tenant)['records_missing_phone']['count'])->toBe(3);
});

test('a dismissal is the person\'s own', function () {
    $tenant = createTenant();
    [$first] = nsPerson($tenant, UserRole::HR_ADMIN);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => null]);

    test()->postJson(nsUrl($tenant, '/records_missing_phone/dismiss'), ['signature' => '1'])->assertNoContent();
    expect(nsSteps($tenant))->not->toHaveKey('records_missing_phone');

    nsPerson($tenant, UserRole::HR_ADMIN);

    expect(nsSteps($tenant))->toHaveKey('records_missing_phone');
});

test('a snooze ends on its own', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-26 10:00', 'Africa/Addis_Ababa')->utc());
    $tenant = createTenant();
    nsPerson($tenant, UserRole::HR_ADMIN);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => null]);

    test()->postJson(nsUrl($tenant, '/records_missing_phone/dismiss'), ['signature' => '1', 'snooze_days' => 3])->assertNoContent();
    expect(nsSteps($tenant))->not->toHaveKey('records_missing_phone');

    Carbon::setTestNow(Carbon::now()->addDays(4));

    expect(nsSteps($tenant))->toHaveKey('records_missing_phone');
});

test('a blocker can only be snoozed, and only for a few days', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::FINANCE_ADMIN);
    PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'failed']);
    $signature = nsSteps($tenant)['payroll_failed']['signature'];

    test()->postJson(nsUrl($tenant, '/payroll_failed/dismiss'), ['signature' => $signature])
        ->assertUnprocessable()->assertJsonValidationErrors('snooze_days');
    test()->postJson(nsUrl($tenant, '/payroll_failed/dismiss'), ['signature' => $signature, 'snooze_days' => 8])
        ->assertUnprocessable()->assertJsonValidationErrors('snooze_days');
    expect(nsSteps($tenant))->toHaveKey('payroll_failed');

    test()->postJson(nsUrl($tenant, '/payroll_failed/dismiss'), ['signature' => $signature, 'snooze_days' => 7])->assertNoContent();

    expect(nsSteps($tenant))->not->toHaveKey('payroll_failed');
});

test('a blocker row with no snooze, however it got there, does not hide the step', function () {
    $tenant = createTenant();
    [$user] = nsPerson($tenant, UserRole::FINANCE_ADMIN);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'failed']);
    NextStepDismissal::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'step_id' => 'payroll_failed',
        'signature' => $run->public_id.':failed',
    ]);

    expect(nsSteps($tenant))->toHaveKey('payroll_failed');
});

test('an unknown step id is refused', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::EMPLOYEE);

    test()->postJson(nsUrl($tenant, '/not_a_step/dismiss'), ['signature' => '1'])->assertNotFound();
});

test('restoring brings back everything the person put away, and only theirs', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::HR_ADMIN);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'phone' => null]);
    test()->postJson(nsUrl($tenant, '/records_missing_phone/dismiss'), ['signature' => '1'])->assertNoContent();

    nsPerson($tenant, UserRole::HR_ADMIN);
    test()->postJson(nsUrl($tenant, '/records_missing_phone/dismiss'), ['signature' => '1'])->assertNoContent();
    test()->deleteJson(nsUrl($tenant, '/dismissals'))->assertNoContent();

    expect(nsSteps($tenant))->toHaveKey('records_missing_phone')
        ->and(NextStepDismissal::query()->count())->toBe(1);
});

test('the endpoint needs a signed-in user', function () {
    $tenant = createTenant();

    test()->getJson(nsUrl($tenant))->assertUnauthorized();
});

// ── Cost ──

test('the list for an administrator stays within a query budget', function () {
    $tenant = createTenant();
    nsPerson($tenant, UserRole::TENANT_ADMIN);
    Employee::factory()->count(5)->create(['tenant_id' => $tenant->id]);

    DB::enableQueryLog();
    test()->getJson(nsUrl($tenant))->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThan(80);
});
