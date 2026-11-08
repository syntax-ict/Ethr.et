<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * K-44: the shared sign-in form accepted only an email address, so an employee
 * activated in person (#440), who has no email, could not sign in from it.
 *
 * These pin the server half of the fix: the request the form now sends signs in
 * by employee code where the organisation allows it, the organisation tells the
 * form which identifiers to ask for, and a refusal never says which part was
 * wrong.
 */
function employeeWithCodeOnly(array $identifiers): array
{
    $tenant = createTenant(['subdomain' => 'acme', 'settings' => ['login_identifiers' => $identifiers]]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-0042']);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'email' => null,
        'password' => Hash::make('a-fresh-secret-9'),
        'status' => 'active',
        'role' => UserRole::EMPLOYEE,
    ]);

    return [$tenant, $user];
}

describe('signing in from the form with an employee code', function () {
    it('signs in an employee who has no email when the organisation enables employee codes', function () {
        [, $user] = employeeWithCodeOnly(['employee_code', 'email']);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'EMP-0042',
            'password' => 'a-fresh-secret-9',
            'tenant' => 'acme',
        ])->assertOk()->assertJsonStructure(['access_token']);

        expect($user->fresh()->last_login_at)->not->toBeNull();
    });

    it('still accepts the value under `email`, as a form loaded before the fix sends it', function () {
        employeeWithCodeOnly(['employee_code']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'EMP-0042',
            'password' => 'a-fresh-secret-9',
            'tenant' => 'acme',
        ])->assertOk();
    });

    it('refuses the code where the organisation signs in by email only', function () {
        employeeWithCodeOnly(['email']);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'EMP-0042',
            'password' => 'a-fresh-secret-9',
            'tenant' => 'acme',
        ])->assertStatus(422)->assertJsonValidationErrors(['identifier']);
    });

    it('answers a wrong password and an unknown code with the same words', function () {
        employeeWithCodeOnly(['employee_code']);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'EMP-0042',
            'password' => 'not-the-password',
            'tenant' => 'acme',
        ])->assertStatus(422)->json('errors');

        $unknownCode = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'EMP-9999',
            'password' => 'not-the-password',
            'tenant' => 'acme',
        ])->assertStatus(422)->json('errors');

        expect($unknownCode)->toBe($wrongPassword);
    });
});

describe('what the sign-in page is told to ask for', function () {
    it('lists the identifiers the organisation enables, in its order', function () {
        Tenant::factory()->create([
            'subdomain' => 'acme',
            'settings' => ['login_identifiers' => ['employee_code', 'email']],
        ]);

        $this->getJson('http://acme.ethr.et/api/v1/auth/tenant-context')
            ->assertOk()
            ->assertJsonPath('tenant.sign_in.identifiers', ['employee_code', 'email']);
    });

    it('says email for an organisation that never chose', function () {
        Tenant::factory()->create(['subdomain' => 'acme']);

        $this->getJson('http://acme.ethr.et/api/v1/auth/tenant-context')
            ->assertOk()
            ->assertJsonPath('tenant.sign_in.identifiers', ['email']);
    });

    it('drops a type it cannot sign in with', function () {
        Tenant::factory()->create([
            'subdomain' => 'acme',
            'settings' => ['login_identifiers' => ['national_id', 'phone']],
        ]);

        $this->getJson('http://acme.ethr.et/api/v1/auth/tenant-context')
            ->assertOk()
            ->assertJsonPath('tenant.sign_in.identifiers', ['phone']);
    });
});
