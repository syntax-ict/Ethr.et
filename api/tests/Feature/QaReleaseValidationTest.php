<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;

/**
 * Inputs the 2026-10-13 pre-release QA found accepted that should not be
 * (docs/audit/QA-RELEASE-2026-10-13.md, F1-F7).
 */
function qaUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

beforeEach(function () {
    $this->tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $this->tenant);
});

it('refuses a shift that ends when it starts (F1)', function () {
    test()->postJson(qaUrl($this->tenant, '/shifts'), ['name' => 'Zero', 'start_time' => '08:00', 'end_time' => '08:00'])
        ->assertUnprocessable()->assertJsonValidationErrors('end_time');
    test()->postJson(qaUrl($this->tenant, '/shifts'), ['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00'])
        ->assertCreated();
});

it('refuses employee details no one could mean (F2-F4)', function (array $body, string $field) {
    test()->postJson(qaUrl($this->tenant, '/employees'), [
        'name' => 'Abebe Kebede', 'employee_code' => 'QA-'.uniqid(), 'hire_date' => '2026-10-01', ...$body,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'hired in 2099' => [['hire_date' => '2099-01-01'], 'hire_date'],
    'aged five on the hire date' => [['date_of_birth' => '2021-01-01'], 'date_of_birth'],
    'a phone made of words' => [['phone' => 'call me maybe'], 'phone'],
]);

it('accepts a real employee, a 15-year-old school leaver and an Ethiopian phone', function () {
    test()->postJson(qaUrl($this->tenant, '/employees'), [
        'name' => 'Abebe Kebede', 'employee_code' => 'QA-OK1', 'hire_date' => '2026-10-01',
        'date_of_birth' => '2011-09-30', 'phone' => '+251 911 234 567',
    ])->assertCreated();
});

it('refuses a time zone that does not exist (F5)', function () {
    test()->putJson(qaUrl($this->tenant, '/settings/organization'), ['timezone' => 'Mars/Olympus'])
        ->assertUnprocessable()->assertJsonValidationErrors('timezone');
    test()->putJson(qaUrl($this->tenant, '/settings/organization'), ['timezone' => 'Africa/Addis_Ababa'])
        ->assertOk();
});

it('refuses the same holiday twice on the same day (F6)', function () {
    test()->postJson(qaUrl($this->tenant, '/holidays'), ['name' => 'Meskel', 'date' => '2026-09-27'])->assertCreated();
    test()->postJson(qaUrl($this->tenant, '/holidays'), ['name' => 'Meskel', 'date' => '2026-09-27'])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('refuses opening a vacancy that has already closed (F7)', function () {
    $base = ['title' => 'Driver', 'employment_type' => 'full_time', 'description' => 'Drive.'];

    test()->postJson(qaUrl($this->tenant, '/vacancies'), [...$base, 'status' => 'open', 'closes_on' => '2020-01-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('closes_on');
    // A draft or closed record of an old vacancy is fine.
    test()->postJson(qaUrl($this->tenant, '/vacancies'), [...$base, 'status' => 'closed', 'closes_on' => '2020-01-01'])
        ->assertCreated();
});
