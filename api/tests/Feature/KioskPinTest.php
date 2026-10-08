<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\KioskSession;
use App\Services\CurrentTenant;

/**
 * Setting the PIN an employee enters at a kiosk.
 *
 * "Require PIN" is a kiosk setting, and KioskCheckInController refuses a punch
 * when the employee has no PIN. Nothing in the API or the UI ever wrote
 * `employees.kiosk_pin`, so turning the setting on locked every employee out
 * of every kiosk (audit N62).
 */
function kioskPinUrl(Employee $employee): string
{
    return "/api/v1/employees/{$employee->public_id}/kiosk-pin";
}

it('lets someone who manages attendance set a PIN, and the kiosk then accepts it', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-PIN']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $this->putJson(kioskPinUrl($employee), ['pin' => '4821'])
        ->assertOk()
        ->assertJsonPath('has_kiosk_pin', true)
        ->assertJsonMissingPath('pin');

    expect($employee->fresh()->kiosk_pin)->not->toBe('4821');
    $this->assertDatabaseHas('audit_log', ['action' => 'employee.kiosk_pin_set']);

    AttendanceSetting::create([
        ...AttendanceSetting::defaults(),
        'tenant_id' => $tenant->id,
        'kiosk_pin_required' => true,
    ]);
    $session = KioskSession::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => Branch::factory()->create(['tenant_id' => $tenant->id])->id,
        'status' => 'active',
    ]);
    app(CurrentTenant::class)->forget();

    $punch = fn (string $pin, string $key) => $this->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/check-in", [
        'employee_code' => 'EMP-PIN', 'type' => 'check_in', 'pin' => $pin, 'idempotency_key' => $key,
    ], ['X-Kiosk-Token' => $session->token]);

    $punch('0000', 'pin-wrong')->assertUnauthorized();
    app(CurrentTenant::class)->forget();
    $punch('4821', 'pin-right')->assertCreated();
});

it('removes a PIN with null, and the employee resource says so', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'kiosk_pin' => bcrypt('1234')]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $this->putJson(kioskPinUrl($employee), ['pin' => null])
        ->assertOk()
        ->assertJsonPath('has_kiosk_pin', false);

    expect($employee->fresh()->kiosk_pin)->toBeNull();
    $this->getJson("/api/v1/employees/{$employee->public_id}")
        ->assertOk()
        ->assertJsonPath('has_kiosk_pin', false)
        ->assertJsonMissingPath('kiosk_pin');
});

it('refuses a PIN that is not four to six digits', function (mixed $pin) {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $this->putJson(kioskPinUrl($employee), ['pin' => $pin])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pin');
})->with(['123', '1234567', 'abcd', '12 34']);

it('refuses an employee setting a PIN', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    $this->putJson(kioskPinUrl($employee), ['pin' => '4821'])->assertForbidden();

    expect($employee->fresh()->kiosk_pin)->toBeNull();
});
