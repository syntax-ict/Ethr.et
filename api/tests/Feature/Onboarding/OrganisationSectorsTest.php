<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\OrganizationType;
use App\Models\Tenant;
use App\Services\Onboarding\IndustryCatalog;
use App\Support\OrganisationSectors;
use Database\Seeders\OrganizationTemplateSeeder;
use Illuminate\Testing\TestResponse;

/*
 * A sector suggests the kinds of organisation in law it is likely to be, and
 * never makes a kind unreachable: the industry and the kind are independent
 * answers and the mapping between them is not a function (a bank may be a
 * private company or a public enterprise). An unusual pair is recorded for
 * review, not refused.
 */

function registerAs(string $industry, ?string $kind = null): TestResponse
{
    return test()->postJson('/api/v1/auth/register', array_filter([
        'organization_name' => 'Abay Trading', 'subdomain' => 'abay-trading',
        'admin_name' => 'Admin User', 'admin_email' => 'admin@abay.example', 'admin_phone' => '+251911234567',
        'password' => 'SecurePass123!', 'password_confirmation' => 'SecurePass123!',
        'organization_type' => $industry,
        'organization_kind' => $kind,
    ], static fn ($value): bool => $value !== null));
}

describe('the map', function () {
    it('covers exactly the bases the industry catalogue has, in groups that exist', function () {
        $bases = app(IndustryCatalog::class)->bases();
        $mapped = array_keys(OrganisationSectors::GROUPS_BY_BASE);

        sort($bases);
        sort($mapped);

        expect($mapped)->toBe($bases);

        foreach (OrganisationSectors::GROUPS_BY_BASE as $groups) {
            expect($groups)->not->toBeEmpty()
                ->and(array_diff($groups, OrganizationType::GROUPS))->toBe([]);
        }
    });

    it('lets every sector reach at least one kind whose coverage is confirmed', function () {
        $confirmed = OrganizationType::query()->where('is_active', true)
            ->whereNotIn('code', OrganizationType::COVERAGE_UNCONFIRMED)->get();

        foreach (OrganisationSectors::GROUPS_BY_BASE as $base => $groups) {
            expect($confirmed->whereIn('group_code', $groups)->count())->toBeGreaterThan(0, "{$base} suggests only unconfirmed kinds");
        }
    });

    it('resolves an industry key to its base and suggests everything for an unknown answer', function () {
        $sectors = app(OrganisationSectors::class);

        expect($sectors->groupsFor('ministry'))->toBe(['public'])
            ->and($sectors->groupsFor('government'))->toBe(['public'])
            ->and($sectors->groupsFor('general'))->toBe(OrganizationType::GROUPS)
            ->and($sectors->groupsFor(null))->toBe(OrganizationType::GROUPS)
            ->and($sectors->groupsFor('a-trade-nobody-listed'))->toBe(OrganizationType::GROUPS);
    });

    it('reads a missing or unknown answer as plausible, so only a real clash is flagged', function () {
        $sectors = app(OrganisationSectors::class);

        expect($sectors->isPlausible('ministry', 'government'))->toBeTrue()
            ->and($sectors->isPlausible('ministry', 'sole_proprietor'))->toBeFalse()
            ->and($sectors->isPlausible('bank', 'public_enterprise'))->toBeTrue()
            ->and($sectors->isPlausible('bank', 'private_limited_company'))->toBeTrue()
            ->and($sectors->isPlausible(null, 'sole_proprietor'))->toBeTrue()
            ->and($sectors->isPlausible('ministry', null))->toBeTrue()
            ->and($sectors->isPlausible('ministry', 'a-kind-nobody-has'))->toBeTrue();
    });
});

describe('GET /organization-types', function () {
    it('says which sectors each kind is suggested for, and every kind has at least one', function () {
        $rows = collect($this->getJson('/api/v1/organization-types')->assertOk()->json('data'));

        expect($rows)->toHaveCount(16);

        foreach ($rows as $row) {
            expect($row['suggested_for'])->not->toBeEmpty("{$row['code']} is suggested for no sector");
        }

        $government = $rows->firstWhere('code', 'government');
        $sole = $rows->firstWhere('code', 'sole_proprietor');

        expect($government['suggested_for'])->toContain('government', 'general')
            ->and($sole['suggested_for'])->not->toContain('government')
            ->and($sole['suggested_for'])->toContain('general');
    });

    it('carries the policy profile each kind starts from', function () {
        $rows = collect($this->getJson('/api/v1/organization-types')->assertOk()->json('data'))->keyBy('code');

        foreach (['government', 'regional_government', 'defence_police'] as $code) {
            expect($rows[$code]['policy_profile'])->toBe('ethiopia_public', $code);
        }

        foreach (['public_enterprise', 'share_company', 'ngo'] as $code) {
            expect($rows[$code]['policy_profile'])->toBe('ethiopia_private', $code);
        }
    });
});

describe('sign-up', function () {
    it('gives an industry key the kind its base implies, not private', function (string $industry, string $kind) {
        registerAs($industry)->assertCreated();

        $tenant = Tenant::query()->where('subdomain', 'abay-trading')->sole();

        expect($tenant->organization_type)->toBe($kind)
            ->and($tenant->organization_type_needs_review)->toBeTrue();
    })->with([
        'a ministry' => ['ministry', 'government'],
        'a woreda' => ['woreda_administration', 'government'],
        'a bank' => ['bank', 'private'],
    ]);

    it('accepts an unusual pair and flags it for review instead of refusing it', function () {
        registerAs('ministry', 'sole_proprietor')->assertCreated();

        $tenant = Tenant::query()->where('subdomain', 'abay-trading')->sole();

        expect($tenant->organization_type)->toBe('sole_proprietor')
            ->and($tenant->organization_type_needs_review)->toBeTrue();
    });

    it('leaves nothing to confirm when the pair is the usual one', function (string $industry, string $kind) {
        registerAs($industry, $kind)->assertCreated();

        expect(Tenant::query()->where('subdomain', 'abay-trading')->sole()->organization_type_needs_review)->toBeFalse();
    })->with([
        'a ministry that is government' => ['ministry', 'government'],
        'a bank that is a public enterprise' => ['bank', 'public_enterprise'],
        'a bank that is a share company' => ['bank', 'share_company'],
        'a general company of any kind' => ['general', 'diplomatic_mission'],
    ]);
});

describe('the configuration step', function () {
    beforeEach(function () {
        $this->seed(OrganizationTemplateSeeder::class);
        $this->tenant = createTenant(['organization_type' => 'private', 'organization_type_needs_review' => true]);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $this->tenant);
        $this->base = "http://{$this->tenant->subdomain}.ethr.test/api/v1/onboarding/configuration";
    });

    it('clears the review flag only while the applied industry and kind still agree', function (string $kind, bool $stillFlagged) {
        $plan = $this->postJson($this->base.'/preview', ['industry' => 'ministry'])->assertOk()->json('plan');

        $this->postJson($this->base.'/apply', ['industry' => 'ministry', 'organization_type' => $kind, 'plan' => $plan])->assertOk();

        expect($this->tenant->fresh()->organization_type_needs_review)->toBe($stillFlagged)
            ->and($this->tenant->fresh()->organization_type)->toBe($kind);
    })->with([
        'a ministry that is government' => ['government', false],
        'a regional office' => ['regional_government', false],
        'a ministry that is a sole proprietor' => ['sole_proprietor', true],
    ]);
});

describe('Settings', function () {
    beforeEach(function () {
        $this->tenant = createTenant(['type' => 'private']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $this->tenant);
        $this->url = "http://{$this->tenant->subdomain}.ethr.test/api/v1/settings/organization";
    });

    it('refuses an industry the catalogue does not know when it is being changed', function () {
        $this->putJson($this->url, ['type' => 'cartel'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->putJson($this->url, ['type' => 'private-but-new'])->assertUnprocessable()->assertJsonValidationErrors('type');
    });

    it('accepts a catalogue industry or base, and the stored legacy value saved unchanged', function () {
        $this->putJson($this->url, ['name' => 'Legacy Trade', 'type' => 'private'])->assertOk();

        $this->putJson($this->url, ['type' => 'ministry'])->assertOk();
        $this->putJson($this->url, ['type' => 'bank'])->assertOk();

        expect($this->tenant->fresh()->type)->toBe('bank');
    });
});
