<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlatformSetting;
use Database\Seeders\PlanSeeder;

/*
 * The platform's own wording in Afaan Oromoo (2026-11-03): the site-wide
 * labels in `platform_settings` and the plan catalogue. Each has an `_om`
 * twin beside its `_am` one, edited in the console, published where the
 * Amharic is, and empty until written; an empty Oromo field shows English.
 */

it('lets a super admin write the site-wide labels in Oromo', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    $this->putJson('/api/v1/admin/platform-settings', [
        'platform_name_om' => 'ETHR',
        'tagline_om' => 'Qabeenya Namaa dhaabbilee Itoophiyaatiif',
        'office_address_om' => 'Finfinnee, Itoophiyaa',
        'product_mark_label_om' => 'ETHR irratti hundaa\'e',
        'payment_instructions_om' => 'Lakkoofsa nagahee keessanii akka wabiitti caqasaa.',
    ])->assertOk()
        ->assertJsonPath('tagline_om', 'Qabeenya Namaa dhaabbilee Itoophiyaatiif')
        ->assertJsonPath('payment_instructions_om', 'Lakkoofsa nagahee keessanii akka wabiitti caqasaa.');

    $settings = PlatformSetting::current();
    expect($settings->office_address_om)->toBe('Finfinnee, Itoophiyaa')
        ->and($settings->product_mark_label_om)->toBe('ETHR irratti hundaa\'e');
});

it('publishes the Oromo labels on the public site content', function () {
    PlatformSetting::current()->update([
        'tagline_om' => 'Qabeenya Namaa dhaabbilee Itoophiyaatiif',
        'office_address_om' => 'Finfinnee, Itoophiyaa',
        'metric_uptime_note_om' => 'Ji\'a darbe keessatti',
        'product_mark_label_om' => 'ETHR irratti hundaa\'e',
    ]);

    $content = $this->getJson('/api/v1/site-content')->assertOk()->json('data');

    expect($content['tagline_om'])->toBe('Qabeenya Namaa dhaabbilee Itoophiyaatiif')
        ->and($content['office_address_om'])->toBe('Finfinnee, Itoophiyaa')
        ->and($content['metric_uptime_note_om'])->toBe('Ji\'a darbe keessatti')
        ->and($content['product_mark_label_om'])->toBe('ETHR irratti hundaa\'e');
});

it('holds the Oromo testimonial back exactly as it holds the English', function () {
    PlatformSetting::current()->update([
        'testimonial_quote' => 'It replaced three separate systems for us.',
        'testimonial_quote_om' => 'Sirnoota adda addaa sadii bakka bu\'eera.',
        'testimonial_role_om' => 'Hoogganaa Qabeenya Namaa',
        'testimonial_author' => 'A real customer',
    ]);

    $unconsented = $this->getJson('/api/v1/site-content')->assertOk()->json('data');
    expect($unconsented['testimonial_quote_om'])->toBeNull()
        ->and($unconsented['testimonial_role_om'])->toBeNull();

    PlatformSetting::current()->update([
        'testimonial_role' => 'HR Director',
        'testimonial_organisation' => 'A real organisation',
        'testimonial_consented_on' => '2026-09-01',
    ]);

    $published = $this->getJson('/api/v1/site-content')->assertOk()->json('data');
    expect($published['testimonial_quote_om'])->toBe('Sirnoota adda addaa sadii bakka bu\'eera.')
        ->and($published['testimonial_role_om'])->toBe('Hoogganaa Qabeenya Namaa');
});

it('gives a tenant the Oromo payment instructions on the billing page', function () {
    $tenant = createTenant();
    PlatformSetting::current()->update([
        'bank_name' => 'Commercial Bank of Ethiopia',
        'bank_account_number' => '1000 5555 6666 77',
        'bank_account_name' => 'ETHR Technologies PLC',
        'payment_instructions' => 'Quote your invoice number.',
        'payment_instructions_om' => 'Lakkoofsa nagahee keessanii caqasaa.',
    ]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->getJson('/api/v1/billing/dashboard')
        ->assertOk()
        ->assertJsonPath('payment_details.instructions_om', 'Lakkoofsa nagahee keessanii caqasaa.');
});

it('seeds the plans with Oromo, and publishes it on the public catalogue', function () {
    $this->seed(PlanSeeder::class);

    $starter = Plan::query()->where('slug', 'starter')->firstOrFail();
    expect($starter->description_om)->not->toBeEmpty()
        ->and($starter->marketing_features_om)->toHaveCount(count($starter->marketing_features));

    $plans = collect($this->getJson('/api/v1/plans')->assertOk()->json('data'))->keyBy('slug');
    expect($plans['starter']['description_om'])->toBe($starter->description_om)
        ->and($plans['enterprise']['marketing_features_om'])->toHaveCount(3);
});

it('gives a plan from before Oromo its Oromo, but not where the operator rewrote the English', function () {
    $this->seed(PlanSeeder::class);
    // As production stood before the Oromo columns existed.
    Plan::query()->update(['description_om' => null, 'marketing_features_om' => null]);
    Plan::query()->where('slug', 'professional')->update(['description' => 'For growing organisations.']);

    $this->seed(PlanSeeder::class);

    $professional = Plan::query()->where('slug', 'professional')->firstOrFail();
    expect($professional->description_om)->toBeNull()
        ->and($professional->marketing_features_om)->not->toBeEmpty()
        ->and(Plan::query()->where('slug', 'starter')->value('description_om'))->not->toBeNull();
});

it('never replaces Oromo the operator wrote for a plan', function () {
    $this->seed(PlanSeeder::class);
    Plan::query()->where('slug', 'starter')->update(['description_om' => 'Ibsa operaataraa']);

    $this->seed(PlanSeeder::class);

    expect(Plan::query()->where('slug', 'starter')->value('description_om'))->toBe('Ibsa operaataraa');
});

it('lets a super admin write a plan\'s selling points in Oromo', function () {
    $this->seed(PlanSeeder::class);
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    $plan = Plan::query()->where('slug', 'starter')->firstOrFail();

    $this->putJson("/api/v1/admin/plans/{$plan->public_id}", [
        'description_om' => 'Ibsa haaraa',
        'marketing_features_om' => ['Tokko', 'Lama'],
    ])->assertOk();

    $plan->refresh();
    expect($plan->description_om)->toBe('Ibsa haaraa')
        ->and($plan->marketing_features_om)->toBe(['Tokko', 'Lama']);
});
