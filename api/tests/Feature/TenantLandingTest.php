<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\SsoSetting;
use App\Models\Tenant;
use App\Support\TenantLanding;

/**
 * The organisation's sign-in page settings (ethr.et/{slug}): its own words,
 * help contacts and background, and which ways in it offers. All of it is
 * shown before sign-in, so it is validated on the way in and filtered on the
 * way out.
 */
function landingUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

it('saves the sign-in page and shows it before sign-in', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(landingUrl($tenant, '/settings/landing'), [
        'headline' => 'Welcome to Acme',
        'message' => 'Check in when you arrive at the plant.',
        'support_email' => 'hr@acme.et',
        'support_phone' => '+251 11 555 0100',
        'background' => 'pattern',
    ])->assertOk()
        ->assertJsonPath('headline', 'Welcome to Acme')
        ->assertJsonPath('background', 'pattern');

    $this->assertDatabaseHas('audit_log', ['action' => 'settings.landing_updated']);

    app('auth')->forgetGuards();

    test()->getJson(landingUrl($tenant, '/auth/tenant-context'))
        ->assertOk()
        ->assertJsonPath('tenant.landing.headline', 'Welcome to Acme')
        ->assertJsonPath('tenant.landing.support_email', 'hr@acme.et')
        ->assertJsonPath('tenant.landing.background', 'pattern')
        ->assertJsonMissingPath('tenant.landing.background_image_url');
});

it('keeps what a save does not mention, section toggles included', function () {
    $tenant = createTenant();
    $tenant->update(['landing' => [
        'headline' => 'Old headline',
        'support_email' => 'hr@acme.et',
        'background' => 'gradient',
        'sections' => ['announcements' => false, 'vacancies' => true, 'holidays' => true],
    ]]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    // The form sends only the fields it edits. Before the merge fix this
    // write reset every absent key: the toggles went back to their defaults
    // and the support email vanished.
    test()->putJson(landingUrl($tenant, '/settings/landing'), [
        'headline' => 'New headline',
        'background' => 'brand',
    ])->assertOk();

    $landing = $tenant->fresh()->landing;
    expect($landing['headline'])->toBe('New headline')
        ->and($landing['support_email'])->toBe('hr@acme.et')
        ->and($landing['sections'])->toEqual(['announcements' => false, 'vacancies' => true, 'holidays' => true]);
});

it('merges section toggles per key', function () {
    $tenant = createTenant();
    $tenant->update(['landing' => [
        'background' => 'brand',
        'sections' => ['announcements' => false, 'holidays' => true],
    ]]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(landingUrl($tenant, '/settings/landing'), [
        'background' => 'brand',
        'sections' => ['vacancies' => false],
    ])->assertOk();

    expect($tenant->fresh()->landing['sections'])
        ->toEqual(['announcements' => false, 'holidays' => true, 'vacancies' => false]);
});

it('refuses what must not reach a public page', function (array $body, string $field) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(landingUrl($tenant, '/settings/landing'), ['background' => 'brand', ...$body])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    // The photo background is the uploaded cover; TenantBrandAssetTest
    // chooses it after an upload. Without one there is nothing to draw.
    'a photo background with no cover uploaded' => [['background' => 'image'], 'background'],
    'an unknown background' => [['background' => 'video'], 'background'],
    'a long headline' => [['headline' => str_repeat('a', 81)], 'headline'],
    'not an e-mail' => [['support_email' => 'hr at acme'], 'support_email'],
    'not a phone number' => [['support_phone' => 'call us'], 'support_phone'],
]);

it('filters a stored value that skipped the rules', function () {
    $tenant = createTenant();
    // A row from before 2026-10-14 may still carry a typed photo URL: it is
    // not read, and a photo background with no cover is the brand colour.
    $tenant->update(['landing' => [
        'headline' => str_repeat('x', 200),
        'background' => 'image',
        'background_image_url' => 'javascript:alert(1)',
        'support_email' => 'nope',
    ]]);

    test()->getJson(landingUrl($tenant, '/auth/tenant-context'))
        ->assertOk()
        ->assertJsonPath('tenant.landing.headline', str_repeat('x', 80))
        ->assertJsonPath('tenant.landing.background', 'brand')
        ->assertJsonMissingPath('tenant.landing.background_image_url')
        ->assertJsonPath('tenant.landing.support_email', null);
});

it('offers SSO on the sign-in page only once the organisation configured it', function () {
    $tenant = createTenant();

    test()->getJson(landingUrl($tenant, '/auth/tenant-context'))
        ->assertJsonPath('tenant.sign_in.sso', false);

    SsoSetting::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'saml',
        'is_enabled' => true,
        'idp_entity_id' => 'https://idp.acme.et',
        'idp_sso_url' => 'https://idp.acme.et/sso',
    ]);

    test()->getJson(landingUrl($tenant, '/auth/tenant-context'))
        ->assertJsonPath('tenant.sign_in.sso', true);
});

it('lets only an administrator change the sign-in page', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->putJson(landingUrl($tenant, '/settings/landing'), ['background' => 'brand'])
        ->assertForbidden();
});

// The portal's content (organisation portal, PR 3 of 4): words in both
// languages, what the organisation says about itself, how to reach it, its
// links, the sections and their order, and whether search engines may list
// the page.
it('saves the portal content and serves it, filtered, before sign-in', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(landingUrl($tenant, '/settings/landing'), [
        'background' => 'brand',
        'headline_am' => 'እንኳን ወደ አክሜ በደህና መጡ',
        'message_am' => 'ሲደርሱ ይግቡ።',
        'about' => '  Acme makes pumps in Adama since 1998.  ',
        'about_am' => 'አክሜ ከ1998 ጀምሮ በአዳማ ፓምፖችን ይሠራል።',
        'founded_year' => 1998,
        'sector' => 'manufacturing',
        'staff_band' => '201-500',
        'show_branch_count' => true,
        'address' => 'Industrial zone, Adama',
        'hours' => 'Mon to Sat, 08:00 to 17:00',
        'latitude' => 8.54,
        'longitude' => 39.27,
        'show_branches' => true,
        'links' => [
            'website' => 'https://acme.et',
            'linkedin' => 'https://www.linkedin.com/company/acme',
            'telegram' => 'https://t.me/acme',
            'privacy' => 'https://acme.et/privacy',
        ],
        'sections' => ['holidays' => true, 'about' => true],
        'order' => ['contact', 'about'],
        'listed' => true,
    ])->assertOk()
        ->assertJsonPath('about', 'Acme makes pumps in Adama since 1998.')
        ->assertJsonPath('order', ['contact', 'about', 'services', 'announcements', 'vacancies', 'holidays', 'faq'])
        ->assertJsonPath('links.linkedin', 'https://www.linkedin.com/company/acme')
        ->assertJsonPath('links.facebook', null)
        ->assertJsonPath('listed', true);

    app('auth')->forgetGuards();

    test()->getJson(landingUrl($tenant, '/auth/tenant-context'))
        ->assertOk()
        ->assertJsonPath('tenant.landing.headline_am', 'እንኳን ወደ አክሜ በደህና መጡ')
        ->assertJsonPath('tenant.landing.founded_year', 1998)
        ->assertJsonPath('tenant.landing.sector', 'manufacturing')
        ->assertJsonPath('tenant.landing.staff_band', '201-500')
        ->assertJsonPath('tenant.landing.latitude', 8.54)
        ->assertJsonPath('tenant.landing.longitude', 39.27)
        ->assertJsonPath('tenant.landing.show_branches', true)
        ->assertJsonPath('tenant.landing.links.telegram', 'https://t.me/acme')
        ->assertJsonPath('tenant.landing.sections.holidays', true)
        ->assertJsonPath('tenant.landing.order.0', 'contact');
});

it('refuses portal content that must not reach a public page', function (array $body, string $field) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(landingUrl($tenant, '/settings/landing'), ['background' => 'brand', ...$body])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'a long about' => [['about' => str_repeat('a', 601)], 'about'],
    'a sector off the list' => [['sector' => 'crypto'], 'sector'],
    'a staff band off the list' => [['staff_band' => '7'], 'staff_band'],
    'a year before 1800' => [['founded_year' => 1750], 'founded_year'],
    'a latitude with no longitude' => [['latitude' => 9.0], 'longitude'],
    'a latitude off the globe' => [['latitude' => 95, 'longitude' => 39], 'latitude'],
    'an http website' => [['links' => ['website' => 'http://acme.et']], 'links.website'],
    'a LinkedIn link on another host' => [['links' => ['linkedin' => 'https://evil.example/linkedin.com']], 'links.linkedin'],
    'a YouTube link on a look-alike host' => [['links' => ['youtube' => 'https://youtube.com.evil.example/x']], 'links.youtube'],
    'an unknown link' => [['links' => ['tiktok' => 'https://tiktok.com/@acme']], 'links'],
    'an unknown section in the order' => [['order' => ['about', 'map']], 'order.1'],
    'a repeated section in the order' => [['order' => ['about', 'about']], 'order.0'],
]);

it('serves nothing from a hand-edited portal row that skipped the rules', function () {
    $tenant = createTenant();
    $tenant->update(['landing' => [
        'about' => str_repeat('x', 700),
        'founded_year' => 'soon',
        'sector' => 'crypto',
        'latitude' => 9.0,
        'links' => ['website' => 'javascript:alert(1)', 'x' => 'https://x.com.evil.example/a', 'telegram' => 'https://t.me/acme'],
        'order' => ['map', 'contact', 'contact'],
        'listed' => 'yes',
    ]]);

    test()->getJson(landingUrl($tenant, '/auth/tenant-context'))
        ->assertOk()
        ->assertJsonPath('tenant.landing.about', str_repeat('x', 600))
        ->assertJsonPath('tenant.landing.founded_year', null)
        ->assertJsonPath('tenant.landing.sector', null)
        ->assertJsonPath('tenant.landing.latitude', null)
        ->assertJsonPath('tenant.landing.links.website', null)
        ->assertJsonPath('tenant.landing.links.x', null)
        ->assertJsonPath('tenant.landing.links.telegram', 'https://t.me/acme')
        ->assertJsonPath('tenant.landing.order', ['contact', 'about', 'services', 'announcements', 'vacancies', 'holidays', 'faq'])
        ->assertJsonPath('tenant.landing.listed', false);
});

it('saves questions and answers in order, and shows them before sign-in', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(landingUrl($tenant, '/settings/landing'), [
        'background' => 'brand',
        'faqs' => [
            ['question' => 'When is payday?', 'question_am' => 'ደመወዝ መቼ ነው?', 'answer' => "The 28th of each month.\n\nEarlier when it falls on a holiday.", 'answer_am' => null],
            ['question' => null, 'question_am' => 'የስራ ሰዓት?', 'answer' => null, 'answer_am' => 'ከ 2:30 እስከ 11:30።'],
        ],
    ])->assertOk()
        ->assertJsonCount(2, 'faqs')
        ->assertJsonPath('faqs.0.question', 'When is payday?')
        ->assertJsonPath('faqs.0.answer', "The 28th of each month.\n\nEarlier when it falls on a holiday.")
        ->assertJsonPath('sections.faq', true)
        ->assertJsonPath('order.5', 'faq');

    app('auth')->forgetGuards();

    test()->getJson(landingUrl($tenant, '/auth/tenant-context'))
        ->assertOk()
        ->assertJsonPath('tenant.landing.faqs.1.question_am', 'የስራ ሰዓት?')
        ->assertJsonPath('tenant.landing.faqs.1.question', null);
});

it('replaces the whole list on save, so a removed question is gone', function () {
    $tenant = createTenant();
    $tenant->update(['landing' => ['faqs' => [
        ['question' => 'Old?', 'answer' => 'Yes.'],
        ['question' => 'Kept?', 'answer' => 'Yes.'],
    ]]]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(landingUrl($tenant, '/settings/landing'), [
        'background' => 'brand',
        'faqs' => [['question' => 'Kept?', 'answer' => 'Yes.']],
    ])->assertOk()->assertJsonCount(1, 'faqs')->assertJsonPath('faqs.0.question', 'Kept?');

    // A save that does not mention the list leaves it alone.
    test()->putJson(landingUrl($tenant, '/settings/landing'), ['background' => 'brand', 'headline' => 'Hello'])
        ->assertOk()->assertJsonCount(1, 'faqs');
});

it('refuses a question without an answer, an answer without a question, and more than twelve', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $url = landingUrl($tenant, '/settings/landing');

    test()->putJson($url, ['background' => 'brand', 'faqs' => [['question' => 'Payday?', 'answer' => '  ']]])
        ->assertUnprocessable()->assertJsonValidationErrors('faqs.0.answer');
    test()->putJson($url, ['background' => 'brand', 'faqs' => [['question_am' => '', 'answer' => 'The 28th.']]])
        ->assertUnprocessable()->assertJsonValidationErrors('faqs.0.question');
    test()->putJson($url, ['background' => 'brand', 'faqs' => [['question' => 'Q', 'answer' => 'A', 'script' => 'x']]])
        ->assertUnprocessable()->assertJsonValidationErrors('faqs.0');
    test()->putJson($url, ['background' => 'brand', 'faqs' => array_fill(0, 13, ['question' => 'Q', 'answer' => 'A'])])
        ->assertUnprocessable()->assertJsonValidationErrors('faqs');
    test()->putJson($url, ['background' => 'brand', 'faqs' => [['question' => str_repeat('x', 161), 'answer' => 'A']]])
        ->assertUnprocessable()->assertJsonValidationErrors('faqs.0.question');
});

it('shows only complete, bounded pairs from what is stored, however it got there', function () {
    $tenant = createTenant();
    $tenant->update(['landing' => ['faqs' => [
        ['question' => 'Half?'],
        'not a pair',
        ['question' => '  ', 'answer' => 'No question.'],
        ['question' => str_repeat('q', 300), 'answer' => str_repeat('a', 2000), 'extra' => '<script>'],
        ...array_fill(0, 20, ['question' => 'Q', 'answer' => 'A']),
    ]]]);

    $faqs = TenantLanding::present($tenant->refresh())['faqs'];

    expect($faqs)->toHaveCount(12)
        ->and(mb_strlen((string) $faqs[0]['question']))->toBe(160)
        ->and(mb_strlen((string) $faqs[0]['answer']))->toBe(1000)
        ->and(array_keys($faqs[0]))->toBe(['question', 'question_am', 'answer', 'answer_am']);
});

it('places a section an organisation has not ordered yet after the one before it', function () {
    expect(TenantLanding::order(['about', 'announcements', 'vacancies', 'holidays', 'contact']))
        ->toBe(['about', 'services', 'announcements', 'vacancies', 'holidays', 'faq', 'contact'])
        ->and(TenantLanding::order(['contact', 'about']))
        ->toBe(['contact', 'about', 'services', 'announcements', 'vacancies', 'holidays', 'faq'])
        ->and(TenantLanding::order(['contact', 'faq', 'about']))
        ->toBe(['contact', 'faq', 'about', 'services', 'announcements', 'vacancies', 'holidays'])
        ->and(TenantLanding::order(null))
        ->toBe(TenantLanding::ORDER);
});

it('saves the services offered, in order, and shows them before sign-in', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(landingUrl($tenant, '/settings/landing'), [
        'background' => 'brand',
        'services' => [
            ['title' => 'Pump repair', 'title_am' => 'የፓምፕ ጥገና', 'description' => 'On site within 48 hours.', 'description_am' => null],
            ['title' => null, 'title_am' => 'ስልጠና', 'description' => null, 'description_am' => null],
        ],
    ])->assertOk()
        ->assertJsonCount(2, 'services')
        ->assertJsonPath('services.0.title', 'Pump repair')
        ->assertJsonPath('services.1.description', null)
        ->assertJsonPath('sections.services', true)
        ->assertJsonPath('order.1', 'services');

    app('auth')->forgetGuards();

    test()->getJson(landingUrl($tenant, '/auth/tenant-context'))
        ->assertOk()
        ->assertJsonPath('tenant.landing.services.1.title_am', 'ስልጠና');
});

it('refuses a service without a name, a stray key, and more than eight', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $url = landingUrl($tenant, '/settings/landing');

    test()->putJson($url, ['background' => 'brand', 'services' => [['title' => ' ', 'description' => 'Something.']]])
        ->assertUnprocessable()->assertJsonValidationErrors('services.0.title');
    test()->putJson($url, ['background' => 'brand', 'services' => [['title' => 'A', 'icon' => 'x']]])
        ->assertUnprocessable()->assertJsonValidationErrors('services.0');
    test()->putJson($url, ['background' => 'brand', 'services' => array_fill(0, 9, ['title' => 'A'])])
        ->assertUnprocessable()->assertJsonValidationErrors('services');
    test()->putJson($url, ['background' => 'brand', 'services' => [['title' => 'A', 'description' => str_repeat('d', 401)]]])
        ->assertUnprocessable()->assertJsonValidationErrors('services.0.description');
});

it('serves only named, bounded services from what is stored', function () {
    $tenant = createTenant();
    $tenant->update(['landing' => ['services' => [
        ['description' => 'No name.'],
        'not a service',
        ['title' => str_repeat('t', 200), 'description' => str_repeat('d', 900), 'icon' => '<script>'],
        ...array_fill(0, 12, ['title' => 'S']),
    ]]]);

    $services = TenantLanding::present($tenant->refresh())['services'];

    expect($services)->toHaveCount(8)
        ->and(mb_strlen((string) $services[0]['title']))->toBe(80)
        ->and(mb_strlen((string) $services[0]['description']))->toBe(400)
        ->and(array_keys($services[0]))->toBe(['title', 'title_am', 'description', 'description_am']);
});
