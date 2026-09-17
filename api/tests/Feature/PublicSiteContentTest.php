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

/**
 * The testimonial, and the rule that a quote needs a person behind it.
 *
 * The landing page carried an invented one — five filled stars and "Abebe
 * Kebede, HR Director, Addis Manufacturing PLC", a person who does not exist,
 * attributed a claim about a product they have not used. It survived this
 * branch's audit because two documents said it had been deleted while it was
 * still rendering in the built HTML.
 *
 * These tests are the reason it cannot come back through the admin screen
 * instead of through JSX.
 */
it('publishes no testimonial until an operator enters one', function () {
    $content = $this->getJson('/api/v1/site-content')->assertOk()->json('data');

    expect($content['testimonial_quote'])->toBeNull()
        ->and($content['testimonial_author'])->toBeNull()
        ->and($content['testimonial_organisation'])->toBeNull();

    expect(PlatformSetting::current()->hasPublishedTestimonial())->toBeFalse();
});

it('refuses to publish a quote with nobody attached to it', function () {
    // The quote is stored — an operator part-way through entering one has not
    // lost their work — but it is not published, because an unattributed
    // quote on a public page is an anonymous claim.
    PlatformSetting::current()->update([
        'testimonial_quote' => 'It replaced three separate systems for us.',
    ]);

    $content = $this->getJson('/api/v1/site-content')->assertOk()->json('data');

    expect($content['testimonial_quote'])->toBeNull();
    expect(PlatformSetting::current()->testimonial_quote)->not->toBeNull();
});

it('refuses to publish a quote nobody recorded consent for', function () {
    // The column that makes the difference between a real testimonial and an
    // invented one. Anyone can type a name; writing down a date the customer
    // agreed is the part that cannot be done absent-mindedly.
    PlatformSetting::current()->update([
        'testimonial_quote' => 'It replaced three separate systems for us.',
        'testimonial_author' => 'A real customer',
    ]);

    $content = $this->getJson('/api/v1/site-content')->assertOk()->json('data');

    expect($content['testimonial_quote'])->toBeNull()
        ->and($content['testimonial_author'])->toBeNull();
});

it('publishes the quote once it has an author and recorded consent', function () {
    PlatformSetting::current()->update([
        'testimonial_quote' => 'It replaced three separate systems for us.',
        'testimonial_quote_am' => 'ሦስት የተለያዩ ሥርዓቶችን ተክቶልናል።',
        'testimonial_author' => 'A real customer',
        'testimonial_role' => 'HR Director',
        'testimonial_organisation' => 'A real organisation',
        'testimonial_consented_on' => '2026-09-01',
    ]);

    $content = $this->getJson('/api/v1/site-content')->assertOk()->json('data');

    expect($content['testimonial_quote'])->toBe('It replaced three separate systems for us.')
        ->and($content['testimonial_quote_am'])->toBe('ሦስት የተለያዩ ሥርዓቶችን ተክቶልናል።')
        ->and($content['testimonial_author'])->toBe('A real customer')
        ->and($content['testimonial_organisation'])->toBe('A real organisation');
});

it('keeps the consent date off the unauthenticated endpoint', function () {
    PlatformSetting::current()->update([
        'testimonial_quote' => 'It replaced three separate systems for us.',
        'testimonial_author' => 'A real customer',
        'testimonial_consented_on' => '2026-09-01',
    ]);

    $body = $this->getJson('/api/v1/site-content')->assertOk()->getContent();

    // Provenance, not content. A visitor has no use for it, and the whitelist
    // rule for this endpoint is that anything which does not need to be public
    // is not.
    expect($body)->not->toContain('testimonial_consented_on')
        ->and($body)->not->toContain('2026-09-01');
});
