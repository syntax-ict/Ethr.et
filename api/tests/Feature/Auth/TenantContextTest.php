<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Auth\OtpService;

/**
 * GET /auth/tenant-context is the pre-auth endpoint the login page reads to
 * render "Sign in to {Organization}" and the tenant logo. It adds no resolution
 * logic of its own — ResolveTenant resolves the tenant from the host first — so
 * these tests pin two things: the host-resolved tenant is echoed back as the
 * safe display subset, and an apex/no-tenant host yields an explicit null
 * rather than leaking anything.
 */
it('returns the host-resolved tenant display info', function () {
    Tenant::factory()->create([
        'name' => 'Acme Corporation',
        'subdomain' => 'acme',
        'logo_path' => 'tenants/acme/logo.png',
    ]);

    $this->getJson('http://acme.ethr.et/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertExactJson([
            'tenant' => [
                'name' => 'Acme Corporation',
                'subdomain' => 'acme',
                // A logo is the uploaded one or nothing (2026-10-15: the typed
                // link in logo_path is retired, and the column is not read), so
                // the stored path is not handed to the public page; an
                // organisation with no colours set has none.
                'logo_path' => null,
                'brand' => [
                    'logo_url' => null,
                    'logo_dark_url' => null,
                    'mark_url' => null,
                    'cover' => null,
                    'primary_color' => null,
                    'secondary_color' => null,
                    'accent_color' => null,
                ],
                // Its own words on the page, none set: ETHR's defaults.
                'landing' => [
                    'headline' => null,
                    'headline_am' => null,
                    'message' => null,
                    'message_am' => null,
                    'support_email' => null,
                    'support_phone' => null,
                    'background' => 'brand',
                    'about' => null,
                    'about_am' => null,
                    'founded_year' => null,
                    'sector' => null,
                    'staff_band' => null,
                    'show_branch_count' => false,
                    'address' => null,
                    'address_am' => null,
                    'hours' => null,
                    'hours_am' => null,
                    'latitude' => null,
                    'longitude' => null,
                    'show_branches' => false,
                    'links' => [
                        'website' => null, 'linkedin' => null, 'facebook' => null, 'x' => null,
                        'telegram' => null, 'youtube' => null, 'privacy' => null, 'terms' => null,
                    ],
                    'faqs' => [],
                    'services' => [],
                    'sections' => ['about' => true, 'services' => true, 'announcements' => true, 'vacancies' => true, 'holidays' => false, 'faq' => true, 'contact' => true],
                    'order' => ['about', 'services', 'announcements', 'vacancies', 'holidays', 'faq', 'contact'],
                    'listed' => false,
                ],
                // Which ways in to offer: SSO only once configured.
                'sign_in' => [
                    'sso' => false,
                    'otp' => app(OtpService::class)->isAvailable(),
                    'email_code' => app(OtpService::class)->isAvailable(OtpService::CHANNEL_EMAIL),
                ],
                'default_locale' => 'en',
            ],
        ]);
});

it('never exposes the internal numeric id', function () {
    Tenant::factory()->create(['subdomain' => 'acme']);

    $this->getJson('http://acme.ethr.et/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonMissingPath('tenant.id');
});

it('returns a null tenant on a host with no subdomain', function () {
    $this->getJson('http://localhost/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertExactJson(['tenant' => null]);
});

it('returns the tenant-not-found problem for an unknown subdomain', function () {
    $this->getJson('http://ghost.ethr.et/api/v1/auth/tenant-context')
        ->assertNotFound()
        ->assertJsonPath('type', 'https://ethr.et/errors/tenant-not-found');
});

/*
 * Production QA 2026-10-11, G3: every organisation sign-in page asks for this
 * once, and it rode the shared `api` limiter — 60 a minute per guest IP. A
 * workplace behind one NAT address passed that at shift change and the page
 * lost its branding.
 */
it('has its own limiter, sized for a workplace behind one address', function () {
    Tenant::factory()->create(['subdomain' => 'acme']);

    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'api/v1/auth/tenant-context');
    expect($route->gatherMiddleware())->toContain('throttle:tenant-context');

    for ($i = 1; $i <= 61; $i++) {
        $response = $this->getJson('http://acme.ethr.et/api/v1/auth/tenant-context');
    }

    $response->assertOk();
    expect((int) $response->headers->get('X-RateLimit-Limit'))->toBe(300);
});

it('lets the browser reuse the answer briefly, never a shared cache', function () {
    Tenant::factory()->create(['subdomain' => 'acme']);

    $response = $this->getJson('http://acme.ethr.et/api/v1/auth/tenant-context')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=60')
        ->and($response->headers->get('Cache-Control'))->not->toContain('public')
        ->and($response->headers->get('Vary'))->toContain('X-Tenant');
});
