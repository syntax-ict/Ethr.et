<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use App\Services\CurrentTenant;

/**
 * Which language a stranger sees.
 *
 * The ordering is the interesting part and it is not the API's. An API client
 * has already chosen a language and says so in `Accept-Language`; a landing
 * page is reached by someone following a link, and the organisation's own
 * default is usually the better guess. So the tenant outranks the browser here,
 * and only an explicit `?lang=` outranks the tenant.
 */
beforeEach(function () {
    config(['app.domain' => 'ethr.et']);
});

function localisedTenant(string $defaultLocale = 'en'): Tenant
{
    $tenant = Tenant::factory()->create([
        'subdomain' => 'habru',
        'default_locale' => $defaultLocale,
    ]);

    app(CurrentTenant::class)->set($tenant);
    TenantPublicProfile::factory()->published()->create(['tenant_id' => $tenant->id]);
    app(CurrentTenant::class)->forget();

    return $tenant;
}

it('uses the tenants own default language', function () {
    localisedTenant('am');

    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertSee('<html lang="am"', false)
        ->assertSee(__('public.employee_sign_in', [], 'am'));
});

it('lets a visitor override the tenant default', function () {
    localisedTenant('am');

    $this->get('http://habru.ethr.et/?lang=en')
        ->assertOk()
        ->assertSee('<html lang="en"', false)
        ->assertSee('Employee sign in');
});

it('prefers the tenant default over the browser language', function () {
    localisedTenant('am');

    // An Ethiopian school's page should open in Amharic for a visitor whose
    // browser happens to be configured in English — while still honouring
    // anyone who asks otherwise with ?lang=.
    $this->withHeaders(['Accept-Language' => 'en-GB,en;q=0.9'])
        ->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertSee('<html lang="am"', false);
});

it('falls back to the browser language when the tenant set none it knows', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'habru', 'default_locale' => 'om']);
    app(CurrentTenant::class)->set($tenant);
    TenantPublicProfile::factory()->published()->create(['tenant_id' => $tenant->id]);
    app(CurrentTenant::class)->forget();

    // `om` is a declared future locale with no backend translations yet, so it
    // must fall through rather than render a page of raw translation keys.
    $this->withHeaders(['Accept-Language' => 'am'])
        ->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertSee('<html lang="am"', false);
});

it('ignores a language it does not support rather than failing', function (string $lang) {
    localisedTenant('en');

    $this->get("http://habru.ethr.et/?lang={$lang}")
        ->assertOk()
        ->assertSee('<html lang="en"', false);
})->with(['fr', 'zz', '../../etc/passwd', '<script>', '']);

it('offers the other language as a plain link, needing no JavaScript', function () {
    localisedTenant('en');

    // The page ships no script, so the language switch has to be a link. That
    // is also what makes both versions crawlable.
    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertSee('href="?lang=am"', false);
});

it('declares the right Open Graph locale', function () {
    localisedTenant('am');

    $this->get('http://habru.ethr.et/')
        ->assertOk()
        ->assertSee('<meta property="og:locale" content="am_ET">', false);
});
