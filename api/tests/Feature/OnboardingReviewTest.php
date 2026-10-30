<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OnboardingProgress;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Onboarding\IndustryCatalog;
use App\Services\Onboarding\ReadinessScorer;
use Illuminate\Support\Facades\Cache;

/*
 * The onboarding review of 2026-10-30: what a new organisation sees from
 * sign-up to go-live, and what the public site says about it meanwhile.
 */

function reviewHost(Tenant $tenant): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1";
}

function reviewCheck(Tenant $tenant, string $id): ?array
{
    $report = app(ReadinessScorer::class)->score($tenant->id);
    foreach ($report['categories'] as $category) {
        foreach ($category['checks'] as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }
    }

    return null;
}

describe('sign-up', function () {
    it('keeps the administrator name it asks for, and shows it', function () {
        $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Abay Trading',
            'subdomain' => 'abay-trading',
            'admin_name' => 'Hanna Girma',
            'admin_email' => 'hanna@abay.et',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'organization_type' => 'bank',
        ])->assertCreated();

        $user = User::withoutGlobalScopes()->where('email', 'hanna@abay.et')->firstOrFail();
        expect($user->display_name)->toBe('Hanna Girma')
            ->and($user->name)->toBe('Hanna Girma');

        $tenant = Tenant::query()->where('subdomain', 'abay-trading')->firstOrFail();
        app(CurrentTenant::class)->set($tenant);
        $this->actingAs($user)->getJson(reviewHost($tenant).'/auth/me')
            ->assertOk()
            ->assertJsonPath('user.name', 'Hanna Girma');
    });

    it('lets an employee record name the account when there is one', function () {
        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Abebe Kebede']);
        $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id, 'display_name' => 'Someone Else'], $tenant);

        expect($user->refresh()->name)->toBe('Abebe Kebede');
    });
});

describe('the public site', function () {
    beforeEach(fn () => Cache::forget(Tenant::SHOWCASE_CACHE_KEY));

    it('does not name or index a trial until it has people in it', function () {
        $tenant = Tenant::factory()->create([
            'name' => 'Brand New PLC',
            'status' => TenantStatus::TRIAL,
            'trial_ends_at' => now()->addMonths(6),
        ]);

        expect($tenant->isEstablished())->toBeFalse()
            ->and($this->getJson('/api/v1/trusted-by')->json('data.*.name'))->not->toContain('Brand New PLC');

        Employee::factory()->count(Tenant::PUBLIC_MIN_EMPLOYEES)->create(['tenant_id' => $tenant->id]);
        Cache::forget(Tenant::SHOWCASE_CACHE_KEY);

        expect($tenant->refresh()->isEstablished())->toBeTrue()
            ->and(Tenant::query()->established()->whereKey($tenant->id)->exists())->toBeTrue()
            ->and($this->getJson('/api/v1/trusted-by')->json('data.*.name'))->toContain('Brand New PLC');
    });

    it('names a paying organisation whatever its size', function () {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::ACTIVE]);

        expect($tenant->isEstablished())->toBeTrue();
    });

    it('tells the administrator why it is not shown yet', function () {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->addMonth()]);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->getJson(reviewHost($tenant).'/settings/showcase')
            ->assertOk()
            ->assertJsonPath('listed', true)
            ->assertJsonPath('active', true)
            ->assertJsonPath('established', false)
            ->assertJsonPath('shown', false)
            ->assertJsonPath('min_employees', Tenant::PUBLIC_MIN_EMPLOYEES);
    });
});

describe('the people step', function () {
    it('carries the hire date and the department of a pasted row', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $finance = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance', 'code' => 'FIN']);

        $staged = $this->postJson(reviewHost($tenant).'/onboarding/migration/rows', [
            'rows' => [
                ['name' => 'Long Server', 'employee_code' => 'LS1', 'hire_date' => '2016-07-08', 'department' => 'finance'],
                ['name' => 'By Code', 'employee_code' => 'BC1', 'department' => 'FIN'],
            ],
        ])->assertCreated()->json();

        $this->postJson(reviewHost($tenant).'/onboarding/migration/batches/'.$staged['public_id'].'/commit')->assertOk();

        $long = Employee::where('tenant_id', $tenant->id)->where('employee_code', 'LS1')->firstOrFail();
        expect($long->hire_date->toDateString())->toBe('2016-07-08')
            ->and($long->department_id)->toBe($finance->id)
            ->and(Employee::where('tenant_id', $tenant->id)->where('employee_code', 'BC1')->value('department_id'))->toBe($finance->id);
    });

    it('refuses a department the organisation does not have, and a date it cannot read', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);

        $this->postJson(reviewHost($tenant).'/onboarding/migration/rows', [
            'rows' => [['name' => 'A', 'department' => 'Finanse']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['rows.0.department']);

        $this->postJson(reviewHost($tenant).'/onboarding/migration/rows', [
            'rows' => [['name' => 'A', 'hire_date' => '08/07/2016']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['rows.0.hire_date']);
    });

    it('does not match a department of another organisation', function () {
        $tenant = createTenant();
        $other = createTenant();
        Department::factory()->create(['tenant_id' => $other->id, 'name' => 'Elsewhere']);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson(reviewHost($tenant).'/onboarding/migration/rows', [
            'rows' => [['name' => 'A', 'department' => 'Elsewhere']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['rows.0.department']);
    });
});

describe('readiness', function () {
    it('is not ready on the day of sign-up, whatever sign-up created', function () {
        $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Day One PLC',
            'subdomain' => 'day-one',
            'admin_name' => 'Day One Admin',
            'admin_email' => 'admin@dayone.et',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ])->assertCreated();

        $tenant = Tenant::query()->where('subdomain', 'day-one')->firstOrFail();
        app(CurrentTenant::class)->set($tenant);
        $report = app(ReadinessScorer::class)->score($tenant->id);

        expect($report['level'])->toBe('not_ready')
            ->and($report['overall_score'])->toBeLessThan(40)
            ->and(array_column($report['gaps'], 'id'))->toContain('configuration_applied', 'admins_use_mfa');
    });

    it('asks that every employee is in a department, not one', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $department = Department::factory()->create(['tenant_id' => $tenant->id]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $department->id, 'status' => EmployeeStatus::CONFIRMED]);
        $loose = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => null, 'status' => EmployeeStatus::CONFIRMED]);

        expect(reviewCheck($tenant, 'employees_have_department')['passed'])->toBeFalse();

        $loose->update(['department_id' => $department->id]);
        expect(reviewCheck($tenant, 'employees_have_department')['passed'])->toBeTrue();
    });

    it('scores payroll: the employer TIN and every salary', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 0, 'status' => EmployeeStatus::CONFIRMED]);

        expect(reviewCheck($tenant, 'employer_tin_set')['passed'])->toBeFalse()
            ->and(reviewCheck($tenant, 'employees_have_salary')['passed'])->toBeFalse();

        $tenant->update(['settings' => [...($tenant->settings ?? []), 'employer_tin' => '0012345678']]);
        $employee->update(['salary_cents' => 1_500_000]);

        expect(reviewCheck($tenant->refresh(), 'employer_tin_set')['passed'])->toBeTrue()
            ->and(reviewCheck($tenant, 'employees_have_salary')['passed'])->toBeTrue();
    });

    it('asks every administrator to use two-step sign-in', function () {
        $tenant = createTenant();
        $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => false], $tenant);

        expect(reviewCheck($tenant, 'admins_use_mfa')['passed'])->toBeFalse();

        $admin->forceFill(['mfa_enabled' => true])->save();
        expect(reviewCheck($tenant, 'admins_use_mfa')['passed'])->toBeTrue();
    });
});

describe('the configuration step', function () {
    it('holds plan settings to what the Settings page accepts', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->postJson(reviewHost($tenant).'/onboarding/configuration/apply', [
            'plan' => ['settings' => ['retirement_age' => 5]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['plan.settings.retirement_age']);

        $this->postJson(reviewHost($tenant).'/onboarding/configuration/apply', [
            'plan' => ['settings' => ['password_policy' => ['min_length' => 1]]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['plan.settings']);

        expect($tenant->refresh()->settings['retirement_age'] ?? null)->toBeNull();
    });

    it('applies every catalogue industry as previewed', function (string $industry) {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $preview = $this->postJson(reviewHost($tenant).'/onboarding/configuration/preview', ['industry' => $industry])
            ->assertOk()->json();

        $this->postJson(reviewHost($tenant).'/onboarding/configuration/apply', [
            'industry' => $industry,
            'plan' => $preview['plan'],
        ])->assertOk();
    })->with(fn () => app(IndustryCatalog::class)->keys());
});

describe('onboarding progress', function () {
    it('reads without writing', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->getJson(reviewHost($tenant).'/onboarding/progress')
            ->assertOk()
            ->assertJsonPath('current_step', 1)
            ->assertJsonPath('completed_at', null);

        expect(OnboardingProgress::where('tenant_id', $tenant->id)->exists())->toBeFalse();
    });
});
