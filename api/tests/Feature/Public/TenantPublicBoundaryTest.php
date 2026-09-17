<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Support\PublicTenantPage;

/**
 * The public/private boundary — the test that matters most in this feature.
 *
 * Everything else here checks that the right page is served. This checks that
 * the page contains nothing it should not, by seeding a tenant with the data
 * ETHR exists to protect — employees, salaries, national IDs, operational
 * settings — publishing its page, and then asserting none of it appears.
 *
 * It is written against the rendered HTML rather than against the view-model,
 * deliberately. A test of PublicTenantPage would prove that object is careful;
 * only a test of the output proves the template did not reach around it.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
});

/**
 * A tenant carrying real private data, with its public page live.
 *
 * @return array{0: Tenant, 1: Employee}
 */
function tenantWithPrivateData(): array
{
    $tenant = Tenant::factory()->create([
        'subdomain' => 'habru',
        'name' => 'Habru Textiles',
        'settings' => [
            'login_identifiers' => ['email', 'employee_number'],
            'payroll_approval_threshold' => 500000,
        ],
    ]);

    app(CurrentTenant::class)->set($tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Zerihun Getachew',
        'name_am' => 'ዘርihun ጌታቸው',
        'national_id' => 'ETH-9911-2233',
    ]);

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'hr.manager@habru-internal.example',
    ]);

    TenantPublicProfile::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'headline' => 'Weaving since 1974',
        'description' => 'A textile cooperative in Amhara.',
    ]);

    app(CurrentTenant::class)->forget();

    return [$tenant, $employee];
}

it('exposes no employee identity on the public page', function () {
    [$tenant, $employee] = tenantWithPrivateData();

    $response = $this->get('http://habru.ethr.et/')->assertOk();

    $response
        ->assertDontSee('Zerihun')
        ->assertDontSee('Getachew')
        // The Amharic name too: a leak that only shows up in one script is
        // still a leak, and is exactly the kind an English-reading reviewer
        // scrolls past.
        ->assertDontSee('ጌታቸው')
        ->assertDontSee('ETH-9911-2233')
        ->assertDontSee($employee->public_id)
        ->assertDontSee('hr.manager@habru-internal.example');
});

it('exposes no operational settings on the public page', function () {
    tenantWithPrivateData();

    // `tenants.settings` carries `login_identifiers` — which tells an attacker
    // exactly what a login form will accept — and whatever else accumulates
    // there. PublicTenantPage never reads it; this asserts that stays true.
    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertDontSee('login_identifiers')
        ->assertDontSee('employee_number')
        ->assertDontSee('payroll_approval_threshold')
        ->assertDontSee('500000');
});

it('carries no primary key on the view-model the page renders from', function () {
    [$tenant] = tenantWithPrivateData();

    $profile = TenantPublicProfile::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->firstOrFail();

    $page = PublicTenantPage::from($tenant, $profile);

    // Convention 4: the internal BIGINT is never API- or page-facing.
    //
    // Asserted structurally rather than by scanning the HTML for the id's
    // digits. The scan was the first instinct and it is worthless: test ids
    // start at 1, and a page contains "1" in `<h1>`, `initial-scale=1` and a
    // dozen other innocent places, so the assertion either fails on correct
    // output or passes because the number is too common to be evidence.
    //
    // What actually guarantees the property is that no field of this object is
    // an id at all — and unlike a regex, this fails the moment someone adds one.
    $fields = array_map(
        static fn (ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass(PublicTenantPage::class))->getProperties(ReflectionProperty::IS_PUBLIC)
    );

    expect($fields)
        ->not->toContain('id')
        ->not->toContain('tenantId')
        ->not->toContain('tenant_id');

    // And the two shapes a leaked key would actually take if one were added.
    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    expect($html)
        ->not->toContain('"id":'.$profile->id)
        ->not->toContain('"tenant_id":'.$tenant->id);
});

it('serves the same page whether or not the visitor is signed in', function () {
    tenantWithPrivateData();

    $anonymous = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    // The public group carries no authentication middleware at all, so an
    // authenticated caller is simply an anonymous one with a spare cookie. If
    // this ever diverges, the public page has started reading the session.
    $tenant = Tenant::where('subdomain', 'habru')->firstOrFail();
    app(CurrentTenant::class)->set($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    app(CurrentTenant::class)->forget();

    $authenticated = $this->actingAs($user)->get('http://habru.ethr.et/')->assertOk()->getContent();

    expect($authenticated)->toBe($anonymous);
});

it('does not let one tenant page render another tenants content', function () {
    tenantWithPrivateData();

    $other = Tenant::factory()->create(['subdomain' => 'woldia', 'name' => 'Woldia Academy']);
    app(CurrentTenant::class)->set($other);
    TenantPublicProfile::factory()->published()->create([
        'tenant_id' => $other->id,
        'headline' => 'Woldia only headline',
        'contact_email' => 'woldia-only@example.com',
    ]);
    app(CurrentTenant::class)->forget();

    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertSee('Habru Textiles')
        ->assertDontSee('Woldia Academy')
        ->assertDontSee('Woldia only headline')
        ->assertDontSee('woldia-only@example.com');
});

it('reads nothing when no tenant is resolved, because the scope fails closed', function () {
    tenantWithPrivateData();

    app(CurrentTenant::class)->forget();

    // BelongsToTenant applies whereRaw('0 = 1') with no tenant context, so the
    // absence of a tenant yields no rows rather than the first row found. The
    // public route's 404 is produced by the same mechanism that protects
    // payroll, not by a separate check that could be forgotten.
    expect(TenantPublicProfile::query()->count())->toBe(0);
});
