<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\LeaveType;
use App\Models\OnboardingProgress;
use App\Models\Position;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A template exercising every resource the provisioner handles, in the
 * shorthand shape the real seeder uses.
 */
function seedGovernmentTemplate(): void
{
    DB::table('organization_templates')->insert([
        'public_id' => (string) Str::ulid(),
        'name' => 'Government',
        'slug' => 'government',
        'template_data' => json_encode([
            'departments' => ['Administration', 'Finance', 'HR'],
            'positions' => ['Director', 'Manager', 'Officer'],
            'shifts' => [['name' => 'Regular', 'start' => '08:30', 'end' => '17:30', 'days' => '1,2,3,4,5']],
            'leave_types' => ['annual', 'sick'],
            'holidays' => false,
            'settings' => ['employee_number_format' => 'GOV-{SEQ:4}'],
        ]),
        'is_active' => true,
        'sort_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

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

    it('updates step progress', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $response = $this->putJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/progress/1',
            ['organization_name' => 'Acme Corp']
        );

        $response->assertOk()
            ->assertJsonPath('current_step', 2);

        expect(OnboardingProgress::where('tenant_id', $tenant->id)->first()->completed_steps)->toContain(1);
    });

    it('rejects invalid step numbers', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $response = $this->putJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/progress/9',
            []
        );

        $response->assertUnprocessable();
    });

    it('applies a template and creates the records it describes', function () {
        seedGovernmentTemplate();

        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $response = $this->postJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/apply-template',
            ['template_slug' => 'government']
        );

        $response->assertOk()
            ->assertJsonPath('template.slug', 'government')
            ->assertJsonPath('provisioned.resources.departments.created', 3)
            ->assertJsonPath('provisioned.resources.positions.created', 3)
            ->assertJsonStructure(['data' => ['departments', 'positions']]);

        expect(Department::where('tenant_id', $tenant->id)->pluck('name')->all())
            ->toEqualCanonicalizing(['Administration', 'Finance', 'HR']);
        expect(Position::where('tenant_id', $tenant->id)->count())->toBe(3);
        expect(Shift::where('tenant_id', $tenant->id)->count())->toBe(1);
        expect(LeaveType::where('tenant_id', $tenant->id)->count())->toBe(2);
    });

    it('re-applies a template without duplicating anything', function () {
        seedGovernmentTemplate();

        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $url = 'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/apply-template';

        $this->postJson($url, ['template_slug' => 'government'])->assertOk();

        $this->postJson($url, ['template_slug' => 'government'])
            ->assertOk()
            ->assertJsonPath('provisioned.total_created', 0)
            ->assertJsonPath('provisioned.resources.departments.skipped', 3);

        expect(Department::where('tenant_id', $tenant->id)->count())->toBe(3);
    });

    it('records what was provisioned in the audit log', function () {
        seedGovernmentTemplate();

        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->postJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/apply-template',
            ['template_slug' => 'government']
        )->assertOk();

        $entry = AuditLog::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'onboarding.template_applied')
            ->first();

        expect($entry)->not->toBeNull();
        expect($entry->payload['provisioned']['resources']['departments']['created'])->toBe(3);
    });

    it('completes onboarding', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->getJson('http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/progress');

        $response = $this->postJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/complete'
        );

        $response->assertOk()
            ->assertJsonPath('redirect', '/dashboard');

        $progress = OnboardingProgress::where('tenant_id', $tenant->id)->first();
        expect($progress->completed_at)->not->toBeNull();
    });

    it('invites team members and skips ones that already exist', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        createUser(['email' => 'existing@acme.com'], $tenant);

        $response = $this->postJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/invite',
            ['emails' => ['new@acme.com', 'existing@acme.com'], 'role' => 'supervisor']
        );

        $response->assertOk()
            ->assertJsonPath('created.0.email', 'new@acme.com')
            ->assertJsonPath('skipped.0', 'existing@acme.com');
    });

    it('rejects an invite payload with no emails', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->postJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/invite',
            ['emails' => []]
        )->assertUnprocessable()->assertJsonValidationErrors(['emails']);
    });

    it('rejects an invite with a malformed email', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->postJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/invite',
            ['emails' => ['not-an-email']]
        )->assertUnprocessable()->assertJsonValidationErrors(['emails.0']);
    });

    it('rejects an invite naming a role outside the allowed set', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->postJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/invite',
            ['emails' => ['new@acme.com'], 'role' => 'super_admin']
        )->assertUnprocessable()->assertJsonValidationErrors(['role']);
    });

    it('denies onboarding mutations to non-admin roles', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->putJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/progress/1',
            ['organization_name' => 'Acme Corp']
        )->assertForbidden();

        $this->postJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/complete'
        )->assertForbidden();
    });
});
