<?php

declare(strict_types=1);

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
        actingAsUser([], $tenant);

        $response = $this->getJson('http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/progress');

        $response->assertOk()
            ->assertJsonPath('current_step', 1)
            ->assertJsonPath('completed_steps', []);
    });

    it('updates step progress', function () {
        $tenant = createTenant();
        actingAsUser([], $tenant);

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
        actingAsUser([], $tenant);

        $response = $this->putJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/progress/9',
            []
        );

        $response->assertUnprocessable();
    });

    it('applies a template', function () {
        DB::table('organization_templates')->insert([
            'public_id' => (string) Str::ulid(),
            'name' => 'Government',
            'slug' => 'government',
            'template_data' => json_encode([
                'departments' => ['Administration', 'Finance', 'HR'],
                'positions' => ['Director', 'Manager', 'Officer'],
            ]),
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenant = createTenant();
        actingAsUser([], $tenant);

        $response = $this->postJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/apply-template',
            ['template_slug' => 'government']
        );

        $response->assertOk()
            ->assertJsonPath('template.slug', 'government')
            ->assertJsonStructure(['data' => ['departments', 'positions']]);
    });

    it('completes onboarding', function () {
        $tenant = createTenant();
        actingAsUser([], $tenant);

        $this->getJson('http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/progress');

        $response = $this->postJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/complete'
        );

        $response->assertOk()
            ->assertJsonPath('redirect', '/dashboard');

        $progress = OnboardingProgress::where('tenant_id', $tenant->id)->first();
        expect($progress->completed_at)->not->toBeNull();
    });
});
