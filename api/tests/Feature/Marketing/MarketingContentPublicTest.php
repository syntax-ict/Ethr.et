<?php

declare(strict_types=1);

use App\Models\MarketingItem;
use App\Models\MarketingSection;
use Database\Seeders\MarketingContentSeeder;
use Illuminate\Support\Facades\Cache;

/**
 * `GET /api/v1/marketing-content`: the landing page's sections and rows.
 *
 * Unauthenticated, read on every host, and the first thing a crawler sees
 * through the committed snapshot, so what it publishes is pinned exactly:
 * published rows only, a whitelist of fields, and a cache that any writer
 * clears.
 */
function seedMarketing(): void
{
    test()->seed(MarketingContentSeeder::class);
}

it('publishes every seeded section in order, with its rows', function () {
    seedMarketing();

    $sections = $this->getJson('/api/v1/marketing-content')->assertOk()->json('data.sections');

    expect(array_column($sections, 'kind'))->toBe(['hero', 'problems', 'capabilities', 'how_it_works', 'industries', 'trust', 'cta'])
        ->and(count($sections[1]['items']))->toBe(6)
        ->and(count($sections[2]['items']))->toBe(5)
        ->and($sections[1]['items'][0]['slug'])->toBe('paper_attendance');
});

it('needs no authentication and no resolved tenant, on a tenant host too', function () {
    seedMarketing();
    createTenant(['subdomain' => 'acme']);

    $this->getJson('http://acme.ethr.test/api/v1/marketing-content')->assertOk();
    $this->getJson('/api/v1/marketing-content')->assertOk();
});

it('publishes a whitelist, and nothing a visitor has no use for', function () {
    seedMarketing();

    $response = $this->getJson('/api/v1/marketing-content')->assertOk();
    $section = $response->json('data.sections.1');
    $item = $section['items'][0];

    expect(array_keys($section))->toBe(['kind', 'heading', 'heading_am', 'subheading', 'subheading_am', 'image', 'items'])
        ->and(array_keys($item))->toBe(['public_id', 'slug', 'icon', 'title', 'title_am', 'body', 'body_am', 'detail', 'detail_am', 'link_path', 'image'])
        ->and($item['image'])->toBeNull();

    $response->assertJsonMissingPath('data.sections.0.id')
        ->assertJsonMissingPath('data.sections.0.is_published')
        ->assertJsonMissingPath('data.sections.0.sort_order')
        ->assertJsonMissingPath('data.sections.1.items.0.id')
        ->assertJsonMissingPath('data.sections.1.items.0.is_published');
});

it('leaves out an unpublished section and an unpublished row', function () {
    seedMarketing();
    MarketingSection::query()->where('kind', 'industries')->update(['is_published' => false]);
    MarketingItem::query()->where('slug', 'manual_payroll')->update(['is_published' => false]);

    $sections = $this->getJson('/api/v1/marketing-content')->assertOk()->json('data.sections');

    expect(array_column($sections, 'kind'))->not->toContain('industries')
        ->and(array_column($sections[1]['items'], 'slug'))->not->toContain('manual_payroll')
        ->and(count($sections[1]['items']))->toBe(5);
});

it('serves an edit immediately: every writer clears the cache', function () {
    seedMarketing();
    $this->getJson('/api/v1/marketing-content')->assertOk();
    expect(Cache::has(MarketingSection::PUBLIC_CACHE_KEY))->toBeTrue();

    MarketingSection::query()->where('kind', 'hero')->first()?->update(['heading' => 'Edited heading']);
    expect(Cache::has(MarketingSection::PUBLIC_CACHE_KEY))->toBeFalse();
    expect($this->getJson('/api/v1/marketing-content')->json('data.sections.0.heading'))->toBe('Edited heading');

    $item = MarketingItem::query()->where('slug', 'records')->firstOrFail();
    $item->update(['title' => 'Edited row']);
    expect(Cache::has(MarketingSection::PUBLIC_CACHE_KEY))->toBeFalse();

    $this->getJson('/api/v1/marketing-content')->assertOk();
    $item->delete();
    expect(Cache::has(MarketingSection::PUBLIC_CACHE_KEY))->toBeFalse();
    expect(array_column($this->getJson('/api/v1/marketing-content')->json('data.sections.2.items'), 'slug'))->not->toContain('records');
});

it('publishes a picture as a srcset with its size, under the public image route', function () {
    seedMarketing();
    $item = MarketingItem::query()->where('slug', 'records')->firstOrFail();
    $item->forceFill([
        'image_path' => 'platform/marketing/x.jpg',
        'image_version' => '0123456789abcdef',
        'image_width' => 1600,
        'image_height' => 1000,
        'image_variants' => [1280 => 'platform/marketing/x-1280.jpg', 640 => 'platform/marketing/x-640.jpg'],
        'image_alt' => 'An HR officer with a complete employee file',
        'image_alt_am' => 'ሙሉ የሠራተኛ ፋይል የያዘች የሰው ሀብት ባለሙያ',
    ])->save();

    $image = collect($this->getJson('/api/v1/marketing-content')->json('data.sections.2.items'))->firstWhere('slug', 'records')['image'];

    expect($image['url'])->toBe("/api/v1/marketing-images/{$item->public_id}/image/0123456789abcdef")
        ->and($image['srcset'])->toBe(
            "/api/v1/marketing-images/{$item->public_id}/image-640/0123456789abcdef 640w, "
            ."/api/v1/marketing-images/{$item->public_id}/image-1280/0123456789abcdef 1280w, "
            ."/api/v1/marketing-images/{$item->public_id}/image/0123456789abcdef 1600w"
        )
        ->and($image['width'])->toBe(1600)
        ->and($image['height'])->toBe(1000)
        ->and($image['alt_am'])->toBe('ሙሉ የሠራተኛ ፋይል የያዘች የሰው ሀብት ባለሙያ');
});
