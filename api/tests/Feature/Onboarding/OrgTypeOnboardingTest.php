<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\StatutoryParameter;
use App\Models\Tenant;
use App\Models\TenantProfileAssignment;
use App\Services\Onboarding\IndustryProfileResolver;
use App\Services\Tenancy\ProfileContext;
use Carbon\Carbon;
use Database\Seeders\OrganizationTemplateSeeder;
use Illuminate\Testing\TestResponse;

/*
 * Org-type-aware onboarding (2026-11-09, Phase 2): sign-up asks the kind of
 * organisation in law, and the configuration step asks the industry (its
 * sector), a headcount band, the pay frequency and the calendar. The answers
 * set a profile the statutory rules resolve against; no statutory row is
 * written for the organisation.
 */

function signUp(array $extra = []): TestResponse
{
    return test()->postJson('/api/v1/auth/register', [
        'organization_name' => 'Abay Trading', 'subdomain' => 'abay-trading',
        'admin_name' => 'Admin User', 'admin_email' => 'admin@abay.example', 'admin_phone' => '+251911234567',
        'password' => 'SecurePass123!', 'password_confirmation' => 'SecurePass123!',
        'organization_type' => 'general',
        ...$extra,
    ]);
}

it('lists sixteen kinds of organisation, grouped, each naming its parent', function () {
    $this->getJson('/api/v1/organization-types')
        ->assertOk()
        ->assertJsonCount(16, 'data')
        ->assertJsonPath('data.0', fn (array $type) => $type['code'] === 'private' && $type['group'] === 'private' && $type['parent_code'] === null)
        ->assertJsonFragment(['code' => 'share_company', 'group' => 'private', 'parent_code' => 'private'])
        ->assertJsonFragment(['code' => 'diplomatic_mission', 'group' => 'international', 'parent_code' => 'international_organization']);
});

it('takes the kind of organisation asked at sign-up, with nothing left to confirm', function () {
    signUp(['organization_kind' => 'share_company'])->assertCreated();

    $tenant = Tenant::query()->where('subdomain', 'abay-trading')->sole();
    $assignment = TenantProfileAssignment::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->sole();

    expect($tenant->organization_type)->toBe('share_company')
        ->and($tenant->organization_type_needs_review)->toBeFalse()
        ->and($tenant->type)->toBe('general')
        ->and($assignment->organization_type)->toBe('share_company')
        ->and(app(ProfileContext::class)->for($tenant->id, Carbon::today())['lineage'])->toBe(['share_company', 'private']);
});

it('refuses a kind of organisation that is not in the catalogue', function () {
    signUp(['organization_kind' => 'cartel'])->assertUnprocessable()->assertJsonValidationErrors('organization_kind');

    expect(Tenant::query()->where('subdomain', 'abay-trading')->exists())->toBeFalse();
});

it('still infers the kind from the industry for a client that does not ask, and records a profile', function () {
    signUp(['organization_type' => 'government'])->assertCreated();

    $tenant = Tenant::query()->where('subdomain', 'abay-trading')->sole();

    expect($tenant->organization_type)->toBe('government')
        ->and($tenant->organization_type_needs_review)->toBeTrue()
        ->and(TenantProfileAssignment::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('reads a headcount band as the smallest count in it', function (string $band, string $method) {
    createTenant();
    $count = (int) $band;

    $plan = app(IndustryProfileResolver::class)->resolve('hotel', ['employee_count' => $count])->toArray();

    expect($plan['plan']['settings']['attendance']['default_method'])->toBe($method);
})->with([
    'a small organisation' => ['11-50', 'mobile'],
    'a large one' => ['201-500', 'biometric'],
]);

describe('the configuration step', function () {
    beforeEach(function () {
        $this->seed(OrganizationTemplateSeeder::class);
        $this->tenant = createTenant(['organization_type' => 'private', 'organization_type_needs_review' => true]);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $this->tenant);
        $this->base = "http://{$this->tenant->subdomain}.ethr.test/api/v1/onboarding/configuration";
    });

    it('previews from a headcount band', function () {
        $this->postJson($this->base.'/preview', ['industry' => 'hotel', 'staff_band' => '11-50'])
            ->assertOk()
            ->assertJsonPath('signals.employee_count', 11);

        $this->postJson($this->base.'/preview', ['industry' => 'hotel', 'staff_band' => '7-9'])
            ->assertUnprocessable()->assertJsonValidationErrors('staff_band');
    });

    it('records the five answers as a profile and writes no statutory row for the organisation', function () {
        $plan = $this->postJson($this->base.'/preview', ['industry' => 'bank'])->assertOk()->json('plan');

        $this->postJson($this->base.'/apply', [
            'industry' => 'bank',
            'organization_type' => 'share_company',
            'staff_band' => '51-200',
            'pay_period' => 'monthly',
            'calendar' => 'gregorian',
            'plan' => $plan,
        ])->assertOk();

        $tenant = $this->tenant->fresh();
        $profile = app(ProfileContext::class)->for($tenant->id, Carbon::today());

        expect($profile)->toMatchArray(['organization_type' => 'share_company', 'sector' => 'bank', 'lineage' => ['share_company', 'private']])
            ->and($tenant->organization_type)->toBe('share_company')
            ->and($tenant->organization_type_needs_review)->toBeFalse()
            ->and($tenant->settings['calendar'])->toBe('gregorian')
            ->and($tenant->settings['pay_period'])->toBe('monthly')
            ->and($tenant->settings['configuration_profile']['staff_band'])->toBe('51-200')
            ->and(StatutoryParameter::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(0);
    });

    it('asks about weekly pay but does not accept it until payroll runs it', function (string $period) {
        $plan = $this->postJson($this->base.'/preview', ['industry' => 'bank'])->json('plan');

        $this->postJson($this->base.'/apply', ['industry' => 'bank', 'pay_period' => $period, 'plan' => $plan])
            ->assertUnprocessable()->assertJsonValidationErrors('pay_period');
    })->with(['weekly', 'biweekly']);
});

it('starts the policies from the kind in law before the industry', function () {
    $resolver = app(IndustryProfileResolver::class);

    // A federal office in a private-sector industry is still civil service;
    // a regional office inherits that from `government`.
    expect($resolver->resolve('school', ['organization_type' => 'government'])->toArray()['profiles']['policy']['key'])->toBe('ethiopia_public')
        ->and($resolver->resolve('school', ['organization_type' => 'regional_government'])->toArray()['profiles']['policy']['key'])->toBe('ethiopia_public')
        // A private company in a public-sector industry is under the Labour Proclamation.
        ->and($resolver->resolve('federal_government', ['organization_type' => 'share_company'])->toArray()['profiles']['policy']['key'])->toBe('ethiopia_private')
        // An explicit choice still wins; with no kind, the industry's suggestion stands.
        ->and($resolver->resolve('school', ['organization_type' => 'government', 'policy_profile' => 'custom'])->toArray()['profiles']['policy']['key'])->toBe('custom')
        ->and($resolver->resolve('federal_government')->toArray()['profiles']['policy']['key'])->toBe('ethiopia_public');
});

it('previews the policies for the organisation\'s own kind when the wizard sends none', function () {
    $tenant = createTenant(['organization_type' => 'government']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/onboarding/configuration/preview", ['industry' => 'school'])
        ->assertOk()
        ->assertJsonPath('profiles.policy.key', 'ethiopia_public');

    $this->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/onboarding/configuration/preview", ['industry' => 'school', 'organization_type' => 'private_limited_company'])
        ->assertOk()
        ->assertJsonPath('profiles.policy.key', 'ethiopia_private');
});
