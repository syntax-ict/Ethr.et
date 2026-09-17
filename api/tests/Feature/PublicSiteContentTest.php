<?php

declare(strict_types=1);

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * `GET /api/v1/site-content` — the contact details, brand and figures the
 * public marketing site renders.
 *
 * It reads the same row as the billing page's payment details, which is the
 * whole reason the first test here exists: this endpoint is unauthenticated,
 * and `platform_settings` holds the bank account every tenant pays ETHR into.
 */
it('never publishes the bank account on an unauthenticated endpoint', function () {
    PlatformSetting::current()->update([
        'bank_name' => 'Commercial Bank of Ethiopia',
        'bank_account_number' => '1000 5555 6666 77',
        'bank_account_name' => 'ETHR Technologies PLC',
        'contact_email' => 'hello@ethr.et',
    ]);

    $body = $this->getJson('/api/v1/site-content')->assertOk()->getContent();

    // SiteContentResource publishes a whitelist rather than excluding fields,
    // because a whitelist fails closed when someone adds a column and a
    // blacklist does not. This is the test that makes that matter.
    expect($body)->not->toContain('Commercial Bank of Ethiopia')
        ->and($body)->not->toContain('1000 5555 6666 77')
        ->and($body)->not->toContain('bank_account')
        ->and($body)->toContain('hello@ethr.et');
});

it('needs no authentication and no resolved tenant', function () {
    // Deliberately not behind EnsurePlatformContext: that middleware 404s
    // whenever a tenant IS resolved, and the marketing pages are served from
    // tenant subdomains as well as the apex.
    $this->getJson('/api/v1/site-content')->assertOk()->assertJsonStructure([
        'data' => ['platform_name', 'tagline', 'contact_email', 'logo_url'],
    ]);
});

it('reports no metrics until an operator publishes one', function () {
    $content = $this->getJson('/api/v1/site-content')->assertOk()->json('data');

    // The landing page states "500+ organisations", "50,000+ employees" and
    // "99.9% uptime" and nothing can substantiate any of it — there is no
    // production deployment. Null renders nothing, which is the honest state
    // and the reason these became columns rather than staying literals.
    expect($content['metric_organisations'])->toBeNull()
        ->and($content['metric_employees'])->toBeNull()
        ->and($content['metric_uptime_note'])->toBeNull();

    expect(PlatformSetting::current()->hasPublishedMetrics())->toBeFalse();
});

it('serves an edit immediately rather than after the cache expires', function () {
    Cache::forget(PlatformSetting::PUBLIC_CACHE_KEY);

    $this->getJson('/api/v1/site-content')->assertOk();

    PlatformSetting::current()->update(['tagline' => 'Changed by the operator']);

    // The model's saved hook forgets the key, so any writer invalidates —
    // including a future admin screen and a tinker session. Without it an
    // operator would fix a typo on the public site and be told to wait five
    // minutes, which is how a cache becomes a bug report.
    expect($this->getJson('/api/v1/site-content')->json('data.tagline'))
        ->toBe('Changed by the operator');
});
