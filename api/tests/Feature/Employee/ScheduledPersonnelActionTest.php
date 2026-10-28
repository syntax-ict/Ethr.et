<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\ApplyDuePersonnelActionsJob;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PersonnelAction;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use Illuminate\Support\Carbon;

/*
 * A personnel action dated after today waits for its effective date.
 *
 * Until 2026-10-27 every action wrote the new grade, position or salary to the
 * employee when it was recorded, so a promotion effective next month raised
 * this month's payroll. "Today" is the organisation's own day (Addis Ababa by
 * default), not the UTC one: for the first three hours of an Ethiopian day
 * UTC is still on yesterday.
 */

function scheduledActionsUrl(Tenant $tenant, Employee $employee, string $suffix = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$employee->public_id}/personnel-actions{$suffix}";
}

function payRaise(string $effectiveDate, int $salaryCents = 2_000_00): array
{
    return ['type' => 'promotion', 'effective_date' => $effectiveDate, 'new_salary_cents' => $salaryCents];
}

beforeEach(function () {
    // 09:00 in Addis Ababa on 27 October 2026.
    $this->travelTo(Carbon::parse('2026-10-27 06:00:00', 'UTC'));
});

it('holds a change dated after today and leaves the employee as they are', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_00]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(scheduledActionsUrl($tenant, $employee), payRaise('2026-11-01'))
        ->assertCreated()
        ->assertJsonPath('is_scheduled', true)
        ->assertJsonPath('changes.salary.to', 2_000_00);

    expect($employee->fresh()->salary_cents)->toBe(1_000_00)
        ->and(AuditLog::where('action', 'employee.personnel_action')->first()?->payload['scheduled'] ?? null)->toBeTrue();
});

it('applies a change dated today or earlier at once, as before', function (string $date) {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_00]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(scheduledActionsUrl($tenant, $employee), payRaise($date))
        ->assertCreated()
        ->assertJsonPath('is_scheduled', false);

    expect($employee->fresh()->salary_cents)->toBe(2_000_00);
})->with(['today' => '2026-10-27', 'last month' => '2026-09-01']);

it('applies it once its day begins in Addis Ababa, not in UTC', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_00]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    test()->postJson(scheduledActionsUrl($tenant, $employee), payRaise('2026-11-01'))->assertCreated();

    // 23:30 on 31 October in Addis Ababa: not yet.
    $this->travelTo(Carbon::parse('2026-10-31 20:30:00', 'UTC'));
    ApplyDuePersonnelActionsJob::dispatchSync($tenant->id);
    expect($employee->fresh()->salary_cents)->toBe(1_000_00);

    // 00:30 on 1 November in Addis Ababa, still 31 October in UTC.
    $this->travelTo(Carbon::parse('2026-10-31 21:30:00', 'UTC'));
    ApplyDuePersonnelActionsJob::dispatchSync($tenant->id);

    $action = PersonnelAction::sole();
    expect($employee->fresh()->salary_cents)->toBe(2_000_00)
        ->and($action->isScheduled())->toBeFalse()
        ->and($action->pending_values)->toBeNull()
        ->and(AuditLog::where('action', 'employee.personnel_action_applied')->count())->toBe(1);

    // Running again changes nothing and logs nothing.
    ApplyDuePersonnelActionsJob::dispatchSync($tenant->id);
    expect(AuditLog::where('action', 'employee.personnel_action_applied')->count())->toBe(1);
});

it('catches up a change whose day passed while nothing ran', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_00, 'salary_step' => 1]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    test()->postJson(scheduledActionsUrl($tenant, $employee), [
        'type' => 'salary_step_increment', 'effective_date' => '2026-11-01',
        'salary_step' => 2, 'new_salary_cents' => 1_100_00,
    ])->assertCreated();

    $this->travelTo(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
    ApplyDuePersonnelActionsJob::dispatchSync($tenant->id);

    $fresh = $employee->fresh();
    expect($fresh->salary_cents)->toBe(1_100_00)->and($fresh->salary_step)->toBe(2);
});

it('applies only the organisation it was dispatched for', function () {
    $theirs = createTenant();
    $theirEmployee = Employee::factory()->create(['tenant_id' => $theirs->id, 'salary_cents' => 1_000_00]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $theirs);
    test()->postJson(scheduledActionsUrl($theirs, $theirEmployee), payRaise('2026-11-01'))->assertCreated();

    $ours = createTenant();
    $this->travelTo(Carbon::parse('2026-11-02 06:00:00', 'UTC'));
    ApplyDuePersonnelActionsJob::dispatchSync($ours->id);

    app(CurrentTenant::class)->set($theirs);
    expect($theirEmployee->fresh()->salary_cents)->toBe(1_000_00)
        ->and(PersonnelAction::sole()->isScheduled())->toBeTrue();
});

it('allows one scheduled change per employee, and no other change while it waits', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_00]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    test()->postJson(scheduledActionsUrl($tenant, $employee), payRaise('2026-11-01'))->assertCreated();

    // Worked out against today's record, a second change could undo the first.
    test()->postJson(scheduledActionsUrl($tenant, $employee), payRaise('2026-12-01', 3_000_00))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('effective_date');
    test()->postJson(scheduledActionsUrl($tenant, $employee), payRaise('2026-10-27', 3_000_00))
        ->assertUnprocessable();

    expect($employee->fresh()->salary_cents)->toBe(1_000_00)
        ->and(PersonnelAction::count())->toBe(1);
});

it('never schedules a temporary posting, which leaves the record alone either way', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_00]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(scheduledActionsUrl($tenant, $employee), [
        'type' => 'acting_assignment', 'effective_date' => '2026-11-01', 'end_date' => '2026-12-01',
        'new_salary_cents' => 1_500_00,
    ])->assertCreated()->assertJsonPath('is_scheduled', false);

    expect($employee->fresh()->salary_cents)->toBe(1_000_00);
});

it('lets HR cancel a scheduled change, and refuses one that has applied', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_00]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $scheduled = test()->postJson(scheduledActionsUrl($tenant, $employee), payRaise('2026-11-01'))->json('public_id');

    test()->deleteJson(scheduledActionsUrl($tenant, $employee, "/{$scheduled}"))->assertNoContent();

    expect(PersonnelAction::count())->toBe(0)
        ->and(AuditLog::where('action', 'employee.personnel_action_cancelled')->count())->toBe(1);

    // With it gone, another change may be scheduled; once applied it stays.
    $applied = test()->postJson(scheduledActionsUrl($tenant, $employee), payRaise('2026-10-01'))->json('public_id');
    test()->deleteJson(scheduledActionsUrl($tenant, $employee, "/{$applied}"))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('action');
    expect(PersonnelAction::count())->toBe(1)->and($employee->fresh()->salary_cents)->toBe(2_000_00);
});

it('does not let an employee cancel, or anyone reach another employee or organisation', function () {
    $theirs = createTenant();
    $theirEmployee = Employee::factory()->create(['tenant_id' => $theirs->id, 'salary_cents' => 1_000_00]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $theirs);
    $theirAction = test()->postJson(scheduledActionsUrl($theirs, $theirEmployee), payRaise('2026-11-01'))->json('public_id');

    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_00]);
    $other = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_00]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $ours = test()->postJson(scheduledActionsUrl($tenant, $employee), payRaise('2026-11-01'))->json('public_id');

    // Another employee's action through this one's address: scoped binding.
    test()->deleteJson(scheduledActionsUrl($tenant, $other, "/{$ours}"))->assertNotFound();
    // Another organisation's employee and action.
    test()->deleteJson(scheduledActionsUrl($tenant, $theirEmployee, "/{$theirAction}"))->assertNotFound();

    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->deleteJson(scheduledActionsUrl($tenant, $employee, "/{$ours}"))->assertForbidden();

    expect(PersonnelAction::count())->toBe(1);
    app(CurrentTenant::class)->set($theirs);
    expect(PersonnelAction::count())->toBe(1);
});

it('marks the change applied without writing to an employee removed in the meantime', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_00]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    test()->postJson(scheduledActionsUrl($tenant, $employee), payRaise('2026-11-01'))->assertCreated();
    $employee->delete();

    $this->travelTo(Carbon::parse('2026-11-02 06:00:00', 'UTC'));
    ApplyDuePersonnelActionsJob::dispatchSync($tenant->id);

    expect(Employee::withTrashed()->find($employee->id)->salary_cents)->toBe(1_000_00)
        ->and(PersonnelAction::sole()->isScheduled())->toBeFalse();
});
