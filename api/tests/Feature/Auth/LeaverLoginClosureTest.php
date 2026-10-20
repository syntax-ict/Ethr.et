<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;

// Moving someone to resigned, terminated or retired left their login working,
// with every token it had issued, until an admin remembered to deactivate the
// user by hand. Access now closes on the leaving date: at once when that date
// has come, and on the first request after it when it is in the future.

afterEach(function () {
    Carbon::setTestNow();
});

function leaverFixture(): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::CONFIRMED]);
    $login = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $login->createToken('phone');

    return [$tenant, $employee, $login];
}

function leaverTransition(Tenant $tenant, Employee $employee, string $effectiveDate, string $to = 'resigned'): void
{
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$employee->public_id}/transition", [
        'to_status' => $to,
        'effective_date' => $effectiveDate,
        'reason' => 'Leaving',
    ])->assertSuccessful();
}

it('closes the login at once when the leaving date has come', function (string $to) {
    [$tenant, $employee, $login] = leaverFixture();

    leaverTransition($tenant, $employee, Carbon::today()->toDateString(), $to);

    expect($login->fresh()->status)->toBe('inactive');
    expect($login->tokens()->count())->toBe(0);
    expect(AuditLog::where('action', 'user.deactivated_on_exit')->count())->toBe(1);
})->with(['resigned', 'terminated']);

it('leaves the login open through a notice period, then closes it on the first request after', function () {
    Carbon::setTestNow('2026-10-20 09:00:00');
    [$tenant, $employee, $login] = leaverFixture();

    leaverTransition($tenant, $employee, '2026-11-03');

    expect($login->fresh()->status)->toBe('active');

    test()->actingAs(User::find($login->id));
    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/me")->assertOk();

    Carbon::setTestNow('2026-11-03 08:00:00');
    test()->actingAs(User::find($login->id));
    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/me")
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://ethr.et/errors/account-inactive');

    expect($login->fresh()->status)->toBe('inactive');
    expect($login->tokens()->count())->toBe(0);
});

it('does not touch a login when the transition is not a departure', function () {
    [$tenant, $employee, $login] = leaverFixture();

    leaverTransition($tenant, $employee, Carbon::today()->toDateString(), 'suspended');

    expect($login->fresh()->status)->toBe('active');
});
