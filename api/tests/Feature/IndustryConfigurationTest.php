<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\OnboardingProgress;
use App\Models\OrganizationTemplate;
use App\Services\Onboarding\IndustryCatalog;
use App\Services\Onboarding\IndustryProfileResolver;
use Database\Seeders\OrganizationTemplateSeeder;

function resolver(): IndustryProfileResolver
{
    return app(IndustryProfileResolver::class);
}

describe('IndustryCatalog', function () {
    it('exposes all twenty-seven industries, each aliased to a seeded base', function () {
        $catalog = app(IndustryCatalog::class);

        expect($catalog->keys())->toHaveCount(27);

        $baseSlugs = collect($catalog->all())->pluck('base')->unique()->values();
        expect($baseSlugs->all())->each->toBeIn([
            'government', 'university', 'hospital', 'ngo', 'bank', 'manufacturing', 'hotel', 'general',
        ]);
    });
});

describe('IndustryProfileResolver', function () {
    beforeEach(function () {
        $this->seed(OrganizationTemplateSeeder::class);
    });

    it('inherits base-template sections as industry defaults', function () {
        createTenant();

        $plan = resolver()->resolve('federal_government')->toArray();

        expect($plan['industry']['base'])->toBe('government');
        expect($plan['sections']['departments']['source'])->toBe('industry_default');
        expect($plan['sections']['departments']['confidence'])->toBe(0.8);
        expect($plan['sections']['departments']['items'])->toContain('Administration');
    });

    it('marks industry-specific overrides as explicit with full confidence', function () {
        createTenant();

        $plan = resolver()->resolve('construction')->toArray();

        expect($plan['sections']['departments']['source'])->toBe('explicit');
        expect($plan['sections']['departments']['confidence'])->toBe(1.0);
        expect($plan['sections']['departments']['items'])->toContain('Site Operations');
    });

    it('synthesizes a region-aware headquarters branch as a heuristic', function () {
        createTenant();

        $plan = resolver()->resolve('technology_company', ['region' => 'Oromia'])->toArray();

        expect($plan['sections']['branches']['source'])->toBe('heuristic');
        expect($plan['sections']['branches']['confidence'])->toBe(0.6);
        expect($plan['sections']['branches']['items'][0]['city'])->toBe('Oromia');
    });

    it('lets a template-specified attendance method win over headcount', function () {
        createTenant();

        // The general base explicitly sets 'mobile', so a large headcount must
        // not flip it — templates are authoritative, the heuristic only fills gaps.
        $plan = resolver()->resolve('custom', ['employee_count' => 500])->toArray();

        expect($plan['plan']['settings']['attendance']['default_method'])->toBe('mobile');
        expect($plan['sections']['settings']['source'])->toBe('industry_default');
    });

    it('fills the attendance method from headcount only when the template is silent', function () {
        createTenant();

        // Strip the method from the base so the gap-fill heuristic has to act.
        $general = OrganizationTemplate::where('slug', 'general')->first();
        $data = $general->template_data;
        unset($data['settings']['attendance']);
        $general->update(['template_data' => $data]);

        $small = resolver()->resolve('custom', ['employee_count' => 8])->toArray();
        $large = resolver()->resolve('custom', ['employee_count' => 500])->toArray();

        expect($small['plan']['settings']['attendance']['default_method'])->toBe('mobile');
        expect($large['plan']['settings']['attendance']['default_method'])->toBe('biometric');
    });

    it('deep-merges override settings onto the base without dropping siblings', function () {
        createTenant();

        $plan = resolver()->resolve('ministry')->toArray();

        // Ministry overrides only the number format; base payroll settings survive.
        expect($plan['plan']['settings']['employee_number_format'])->toBe('MIN-{SEQ:4}');
        expect($plan['plan']['settings']['payroll']['pay_frequency'])->toBe('monthly');
        expect($plan['sections']['settings']['source'])->toBe('explicit');
    });

    it('produces a provisioner-ready plan and a bounded overall confidence', function () {
        createTenant();

        $plan = resolver()->resolve('hospital')->toArray();

        expect($plan['plan'])->toHaveKeys(['departments', 'positions', 'shifts', 'leave_types', 'branches', 'settings']);
        expect($plan['overall_confidence'])->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0);
    });

    it('falls back to the custom profile for an unknown industry', function () {
        createTenant();

        $plan = resolver()->resolve('nonexistent-industry')->toArray();

        expect($plan['industry']['key'])->toBe('custom');
        expect($plan['industry']['base'])->toBe('general');
    });
});

describe('onboarding configuration endpoints', function () {
    beforeEach(function () {
        $this->seed(OrganizationTemplateSeeder::class);
    });

    it('lists industries for the picker', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->getJson('http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/industries')
            ->assertOk()
            ->assertJsonCount(27, 'data')
            ->assertJsonPath('data.0.key', 'federal_government');
    });

    it('previews a scored plan without writing anything', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->postJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/configuration/preview',
            ['industry' => 'bank', 'employee_count' => 300]
        )
            ->assertOk()
            ->assertJsonPath('industry.base', 'bank')
            ->assertJsonStructure(['overall_confidence', 'sections', 'plan']);

        expect(Department::where('tenant_id', $tenant->id)->count())->toBe(0);
    });

    it('rejects an unknown industry on preview', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->postJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/configuration/preview',
            ['industry' => 'not-real']
        )->assertUnprocessable();
    });

    it('applies an edited plan and provisions exactly what was sent', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $response = $this->postJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/configuration/apply',
            [
                'industry' => 'technology_company',
                'plan' => [
                    'departments' => ['Engineering', 'Product', 'People Ops'],
                    'positions' => ['CTO', 'Engineer'],
                    'holidays' => false,
                ],
                'save' => true,
            ]
        );

        $response->assertOk()
            ->assertJsonPath('industry.base', 'general')
            ->assertJsonPath('provisioned.resources.departments.created', 3);

        expect(Department::where('tenant_id', $tenant->id)->pluck('name')->all())
            ->toEqualCanonicalizing(['Engineering', 'Product', 'People Ops']);

        $tenant->refresh();
        expect($tenant->type)->toBe('general');
        expect($tenant->settings['industry'])->toBe('technology_company');
        expect($tenant->settings['saved_configuration']['departments'])->toContain('People Ops');

        $progress = OnboardingProgress::where('tenant_id', $tenant->id)->first();
        expect($progress->completed_steps)->toContain(3);
    });

    it('previews then applies the previewed plan end to end', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $host = 'http://'.$tenant->subdomain.'.ethr.test/api/v1';

        $preview = $this->postJson($host.'/onboarding/configuration/preview', [
            'industry' => 'hotel',
            'employee_count' => 40,
        ])->assertOk()->json();

        $this->postJson($host.'/onboarding/configuration/apply', [
            'industry' => 'hotel',
            'plan' => $preview['plan'],
        ])
            ->assertOk()
            ->assertJsonPath('provisioned.resources.shifts.created', 3);
    });

    it('denies configuration apply to non-admin roles', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->postJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/onboarding/configuration/apply',
            ['industry' => 'custom', 'plan' => ['departments' => ['Ops'], 'holidays' => false]]
        )->assertForbidden();
    });
});
