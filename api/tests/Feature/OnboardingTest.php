<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\OnboardingStep;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\OnboardingProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

describe('GET /api/v1/templates', function () {
    beforeEach(function () {
        DB::table('organization_templates')->insert([
            ['public_id' => (string) Str::ulid(), 'name' => 'Government', 'slug' => 'government', 'template_data' => json_encode(['departments' => ['Administration']]), 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['public_id' => (string) Str::ulid(), 'name' => 'Hospital', 'slug' => 'hospital', 'template_data' => json_encode(['departments' => ['Emergency']]), 'is_active' => true, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['public_id' => (string) Str::ulid(), 'name' => 'Inactive', 'slug' => 'inactive', 'template_data' => json_encode([]), 'is_active' => false, 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);
    });

    it('lists active templates', function () {
        $response = $this->getJson('/api/v1/templates');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Government')
            ->assertJsonPath('data.1.name', 'Hospital');
    });

    it('returns a single template by slug', function () {
        $response = $this->getJson('/api/v1/templates/government');

        $response->assertOk()
            ->assertJsonPath('name', 'Government')
            ->assertJsonPath('slug', 'government');
    });

    it('returns 404 for unknown template', function () {
        $response = $this->getJson('/api/v1/templates/nonexistent');

        $response->assertNotFound();
    });
});

describe('onboarding progress', function () {
    it('returns fresh progress for new tenant', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $response = $this->getJson('http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/progress');

        $response->assertOk()
            ->assertJsonPath('current_step', 1)
            ->assertJsonPath('completed_steps', []);
    });

    it('counts People as done once the organisation has a current employee, however it was added', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $url = 'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/progress';

        Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::TERMINATED]);
        $this->getJson($url)->assertOk()->assertJsonPath('completed_steps', []);

        // Added on the Employees page, not through the wizard's commit.
        Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::CONFIRMED]);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('completed_steps', [OnboardingStep::WORKFORCE_MIGRATION->value]);

        // A read stores nothing.
        expect(OnboardingProgress::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists())->toBeFalse();

        // Another organisation's staff do not count there.
        $other = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $other);
        $this->getJson('http://'.$other->subdomain.'.ethr.test/api/v1/onboarding/progress')
            ->assertOk()->assertJsonPath('completed_steps', []);
    });

    it('denies onboarding progress to non-admin roles', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->getJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/progress'
        )->assertForbidden();
    });

    it('no longer serves the four v1 endpoints the guided setup replaced', function (string $method, string $path) {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->json($method, 'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/'.$path)
            ->assertNotFound();
    })->with([
        'PUT progress/{step}' => ['PUT', 'progress/1'],
        'POST apply-template' => ['POST', 'apply-template'],
        'POST invite' => ['POST', 'invite'],
        'POST complete' => ['POST', 'complete'],
    ]);
});
