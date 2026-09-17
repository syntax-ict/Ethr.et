<?php

declare(strict_types=1);

use App\Enums\PublicSectionKind;
use App\Models\Announcement;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TenantPublicItem;
use App\Models\TenantPublicProfile;
use App\Models\TenantPublicSection;
use App\Models\User;
use App\Services\CurrentTenant;

/**
 * The public/private boundary, extended to the section builder.
 *
 * `TenantPublicBoundaryTest` already proves the four fixed content areas leak
 * nothing. The builder adds eleven more places a template could reach for
 * something it should not, and one of them — `stats` — is a block whose entire
 * purpose is to show numbers, on a product whose numbers are headcounts and
 * salaries.
 *
 * So the rule is absolute and worth stating as one sentence: **every word and
 * number on this page was typed by an administrator.** Nothing is derived from
 * an HR table. These tests seed the data ETHR exists to protect and assert none
 * of it appears, against the rendered HTML rather than against the view-models,
 * because only the output proves the templates did not reach around them.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
});

function tenantWithEverything(): Tenant
{
    $tenant = createTenant([
        'subdomain' => 'habru',
        'name' => 'Habru Textiles',
        'settings' => [
            'industry' => 'manufacturing',
            'login_identifiers' => ['email', 'employee_number'],
            'payroll_approval_threshold' => 500000,
        ],
    ]);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Zerihun Getachew',
        'name_am' => 'ዘሪሁን ጌታቸው',
        'national_id' => 'ETH-9911-2233',
    ]);

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'hr.manager@habru-internal.example',
    ]);

    TenantPublicProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'is_published' => true,
        'preset' => 'manufacturing',
        'description' => 'A textile cooperative in Amhara.',
    ]);

    // One section of every kind, all visible, each with an item so nothing is
    // skipped for being empty.
    $position = 0;
    foreach (PublicSectionKind::cases() as $kind) {
        $section = TenantPublicSection::factory()->create([
            'tenant_id' => $tenant->id,
            'kind' => $kind,
            'position' => $position++,
            'is_visible' => true,
        ]);

        if ($kind->hasItems()) {
            TenantPublicItem::factory()->create([
                'tenant_id' => $tenant->id,
                'section_id' => $section->id,
                'title' => 'A thing the organisation published',
            ]);
        }
    }

    app(CurrentTenant::class)->forget();

    return $tenant;
}

it('exposes no employee identity through any section kind', function () {
    tenantWithEverything();

    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertDontSee('Zerihun')
        ->assertDontSee('Getachew')
        // The Amharic name too. A leak that shows up in only one script is
        // still a leak, and is the kind an English-reading reviewer scrolls
        // past — doubly so on a page whose default locale is Amharic.
        ->assertDontSee('ጌታቸው')
        ->assertDontSee('ETH-9911-2233')
        ->assertDontSee('hr.manager@habru-internal.example');
});

it('exposes no operational settings, including the industry it reads for the preset', function () {
    tenantWithEverything();

    // `settings['industry']` is the one key this feature deliberately reads —
    // PresetResolver uses it to choose the layout. It must still never reach
    // the page: the resolver returns an enum case, and the blob stays behind.
    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertDontSee('login_identifiers')
        ->assertDontSee('employee_number')
        ->assertDontSee('payroll_approval_threshold')
        ->assertDontSee('500000')
        ->assertDontSee('industry');
});

it('never publishes an internal announcement as a public notice', function () {
    $tenant = tenantWithEverything();

    app(CurrentTenant::class)->set($tenant);
    Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Payroll cut-off moved to the 25th',
        'body' => 'Internal: managers must approve timesheets before Friday.',
    ]);
    app(CurrentTenant::class)->forget();

    // The `notices` section is admin-typed content that happens to share a
    // name with an internal feature. Wiring the two together would be the
    // single most damaging thing this builder could do, and it is the kind of
    // "obvious improvement" a later change might make without malice.
    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertDontSee('Payroll cut-off moved')
        ->assertDontSee('managers must approve timesheets');
});

it('carries no primary keys on anything the page renders', function () {
    $tenant = tenantWithEverything();

    $section = TenantPublicSection::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->firstOrFail();

    $html = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    // Convention 4: the internal BIGINT is never page-facing. Asserted as the
    // two shapes a leak would actually take, rather than by scanning for the
    // digits — a small integer appears in a dozen innocent places on any page.
    expect($html)
        ->not->toContain('"id":'.$section->id)
        ->not->toContain('"tenant_id":'.$tenant->id);
});

it('shows a signed in visitor exactly what it shows an anonymous one', function () {
    $tenant = tenantWithEverything();

    $anonymous = $this->get('http://habru.ethr.et/')->assertOk()->getContent();

    app(CurrentTenant::class)->set($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    app(CurrentTenant::class)->forget();

    // The public route group carries no authentication middleware at all, so
    // an authenticated caller is an anonymous one with a spare cookie. If this
    // ever diverges, some section has started reading the session.
    expect($this->actingAs($user)->get('http://habru.ethr.et/')->getContent())->toBe($anonymous);
});

it('reads nothing at all when no tenant is resolved', function () {
    tenantWithEverything();

    app(CurrentTenant::class)->forget();

    // BelongsToTenant applies whereRaw('0 = 1') with no tenant context, so the
    // absence of a tenant yields no rows rather than the first rows found. The
    // builder's tables are protected by the same mechanism as payroll.
    expect(TenantPublicSection::query()->count())->toBe(0);
    expect(TenantPublicItem::query()->count())->toBe(0);
});
