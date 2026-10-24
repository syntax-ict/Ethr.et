<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\MarketingItem;
use App\Models\MarketingSection;
use App\Services\FileStorageService;
use Database\Seeders\MarketingContentSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The console's editing of the public landing page.
 *
 * Every write here reaches a page a crawler indexes, so the tests pin three
 * things beyond the happy path: the registry (a field a kind does not use is
 * refused, not stored and ignored), the claim rule (a sentence the audit
 * took off the site cannot come back through a form, in either language),
 * and the picture pipeline (only re-encoded pixels are served, at a URL that
 * changes with every upload).
 */
beforeEach(function () {
    Storage::fake((string) config('filesystems.default'));
    $this->seed(MarketingContentSeeder::class);
});

function marketingOperator(): void
{
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
}

function marketingItem(string $slug): MarketingItem
{
    return MarketingItem::query()->where('slug', $slug)->firstOrFail();
}

it('lists every section and row, published or not, with the registry and the icon list', function () {
    marketingOperator();
    MarketingItem::query()->where('slug', 'general')->update(['is_published' => false]);

    $data = $this->getJson('/api/v1/admin/marketing')->assertOk()->json('data');

    expect(array_column($data['sections'], 'kind'))->toHaveCount(7)
        ->and($data['icons'])->toContain('clock')
        ->and($data['sections'][1]['spec'])->toBe(['fields' => ['icon', 'title', 'body', 'detail', 'link_path'], 'max_items' => 6, 'images' => true, 'section_image' => false])
        ->and(collect($data['sections'][4]['items'])->firstWhere('slug', 'general')['is_published'])->toBeFalse();
});

it('refuses a tenant admin and a tenant host', function () {
    $tenant = createTenant(['subdomain' => 'acme']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->getJson('/api/v1/admin/marketing')->assertStatus(403);
});

it('edits a section and records what changed', function () {
    marketingOperator();

    $this->putJson('/api/v1/admin/marketing/sections/problems', [
        'heading' => 'What goes wrong today',
        'heading_am' => 'ዛሬ የሚበላሸው',
        'is_published' => false,
    ])->assertOk()->assertJsonPath('data.heading', 'What goes wrong today')->assertJsonPath('data.is_published', false);

    $entry = AuditLog::query()->where('action', 'platform.marketing.section_updated')->latest('id')->firstOrFail();
    expect($entry->payload['before']['heading'])->toBe('The work that fills an HR office, and what ETHR does with it')
        ->and($entry->payload['after']['heading'])->toBe('What goes wrong today');

    expect(array_column($this->getJson('/api/v1/marketing-content')->json('data.sections'), 'kind'))->not->toContain('problems');
});

it('refuses a claim the audit took off the site, in English and in Amharic', function () {
    marketingOperator();

    $this->putJson('/api/v1/admin/marketing/sections/trust', ['heading' => 'Full compliance with Proclamation 1321/2024'])
        ->assertStatus(422)->assertJsonValidationErrors(['heading']);

    $item = marketingItem('time');
    $this->putJson("/api/v1/admin/marketing/items/{$item->public_id}", ['title' => 'Works with ZKTeco readers'])
        ->assertStatus(422)->assertJsonValidationErrors(['title']);
    $this->putJson("/api/v1/admin/marketing/items/{$item->public_id}", ['title_am' => 'Biometric መግቢያ'])
        ->assertStatus(422)->assertJsonValidationErrors(['title_am']);

    expect(marketingItem('time')->title)->toBe('Attendance, shifts and leave');
});

it('creates a row in a section, with the next position, and audits it', function () {
    marketingOperator();
    MarketingItem::query()->where('section_kind', 'trust')->where('slug', 'audit')->delete();

    $this->postJson('/api/v1/admin/marketing/sections/trust/items', [
        'slug' => 'backups',
        'icon' => 'shield',
        'title' => 'Daily backups',
        'title_am' => 'ዕለታዊ ምትኬ',
        'body' => 'A copy of every organisation is taken each night.',
    ])->assertCreated()->assertJsonPath('data.slug', 'backups')->assertJsonPath('data.sort_order', 4);

    $this->assertDatabaseHas('audit_log', ['action' => 'platform.marketing.item_created']);
});

it('refuses a row the hero cannot hold, a field a kind does not use, and a seventh problem', function () {
    marketingOperator();

    $this->postJson('/api/v1/admin/marketing/sections/hero/items', ['slug' => 'extra', 'title' => 'Extra'])
        ->assertStatus(422)->assertJsonValidationErrors(['slug']);

    $this->postJson('/api/v1/admin/marketing/sections/industries/items', ['slug' => 'mining', 'title' => 'Mining', 'body' => 'Not a field here'])
        ->assertStatus(422)->assertJsonValidationErrors(['body']);

    $this->postJson('/api/v1/admin/marketing/sections/problems/items', ['slug' => 'seventh', 'title' => 'One too many'])
        ->assertStatus(422)->assertJsonValidationErrors(['slug']);

    $item = marketingItem('government');
    $this->putJson("/api/v1/admin/marketing/items/{$item->public_id}", ['detail' => 'Industries have no detail'])
        ->assertStatus(422)->assertJsonValidationErrors(['detail']);
});

it('refuses an unknown icon, an external link and a duplicate slug', function () {
    marketingOperator();
    MarketingItem::query()->where('section_kind', 'trust')->where('slug', 'audit')->delete();

    $this->postJson('/api/v1/admin/marketing/sections/trust/items', ['slug' => 'x', 'title' => 'X', 'icon' => 'fingerprint'])
        ->assertStatus(422)->assertJsonValidationErrors(['icon']);

    $item = marketingItem('time');
    $this->putJson("/api/v1/admin/marketing/items/{$item->public_id}", ['link_path' => 'https://example.com'])
        ->assertStatus(422)->assertJsonValidationErrors(['link_path']);

    $this->postJson('/api/v1/admin/marketing/sections/trust/items', ['slug' => 'encryption', 'title' => 'Again'])
        ->assertStatus(422)->assertJsonValidationErrors(['slug']);
});

it('reorders a section and keeps a row the list left out', function () {
    marketingOperator();
    $ids = MarketingItem::query()->where('section_kind', 'how_it_works')->orderBy('sort_order')->pluck('public_id', 'slug');

    $this->putJson('/api/v1/admin/marketing/sections/how_it_works/order', ['order' => [$ids['go_live'], $ids['register']]])
        ->assertOk();

    expect(MarketingItem::query()->where('section_kind', 'how_it_works')->orderBy('sort_order')->pluck('slug')->all())
        ->toBe(['go_live', 'register', 'configure']);
    expect(array_column($this->getJson('/api/v1/marketing-content')->json('data.sections.3.items'), 'slug'))
        ->toBe(['go_live', 'register', 'configure']);

    $foreign = marketingItem('records')->public_id;
    $this->putJson('/api/v1/admin/marketing/sections/how_it_works/order', ['order' => [$foreign]])
        ->assertStatus(422);
    $this->assertDatabaseHas('audit_log', ['action' => 'platform.marketing.item_reordered']);
});

it('stores a picture re-encoded under platform/, serves it immutable, and replaces it on the next upload', function () {
    marketingOperator();
    $item = marketingItem('records');

    $first = $this->post("/api/v1/admin/marketing/items/{$item->public_id}/image", [
        'image' => UploadedFile::fake()->image('file.jpg', 1600, 1000),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.image');

    $item->refresh();
    expect($item->image_version)->toMatch('/^[0-9a-f]{16}$/')
        ->and($item->image_path)->toBe("platform/marketing/{$item->public_id}-{$item->image_version}.jpg")
        ->and(array_keys($item->image_variants))->toBe([1280, 640])
        ->and($first['url'])->toBe("/api/v1/marketing-images/{$item->public_id}/image/{$item->image_version}")
        ->and($first['width'])->toBe(1600);

    $disk = Storage::disk((string) config('filesystems.default'));
    $disk->assertExists($item->image_path);
    $firstPath = $item->image_path;
    $firstVersion = $item->image_version;
    $this->assertDatabaseHas('audit_log', ['action' => 'platform.marketing.image_updated']);

    app('auth')->forgetGuards();
    $response = $this->get($first['url'])->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and(substr($response->getContent(), 0, 3))->toBe("\xFF\xD8\xFF");
    $this->get("/api/v1/marketing-images/{$item->public_id}/image-640/{$firstVersion}")->assertOk();
    $this->get("/api/v1/marketing-images/{$item->public_id}/image/0123456789abcdef")->assertNotFound();

    marketingOperator();
    $this->post("/api/v1/admin/marketing/items/{$item->public_id}/image", [
        'image' => UploadedFile::fake()->image('other.png', 900, 600),
    ], ['Accept' => 'application/json'])->assertCreated();

    $item->refresh();
    expect($item->image_version)->not->toBe($firstVersion)
        ->and(array_keys($item->image_variants))->toBe([640]);
    $disk->assertMissing($firstPath);
    $this->get("/api/v1/marketing-images/{$item->public_id}/image-1280/{$item->image_version}")->assertNotFound();
});

it('refuses a picture that is too small, and one for a kind that has none', function () {
    marketingOperator();

    $this->post('/api/v1/admin/marketing/items/'.marketingItem('records')->public_id.'/image', [
        'image' => UploadedFile::fake()->image('tiny.jpg', 300, 200),
    ], ['Accept' => 'application/json'])->assertStatus(422);

    $this->post('/api/v1/admin/marketing/items/'.marketingItem('government')->public_id.'/image', [
        'image' => UploadedFile::fake()->image('ok.jpg', 1280, 800),
    ], ['Accept' => 'application/json'])->assertStatus(422);

    $this->post('/api/v1/admin/marketing/sections/trust/image', [
        'image' => UploadedFile::fake()->image('ok.jpg', 1280, 800),
    ], ['Accept' => 'application/json'])->assertStatus(422);
});

it('gives the hero a picture, removes it, and deletes a row with its files', function () {
    marketingOperator();

    $hero = $this->post('/api/v1/admin/marketing/sections/hero/image', [
        'image' => UploadedFile::fake()->image('hero.jpg', 1920, 1080),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data');
    $section = MarketingSection::query()->where('kind', 'hero')->firstOrFail();
    expect($hero['image']['width'])->toBe(1920)
        ->and($this->getJson('/api/v1/marketing-content')->json('data.sections.0.image.url'))->toBe($hero['image']['url']);

    $disk = Storage::disk((string) config('filesystems.default'));
    $path = $section->image_path;
    $this->deleteJson('/api/v1/admin/marketing/sections/hero/image')->assertOk()->assertJsonPath('data.image', null);
    $disk->assertMissing($path);
    $this->assertDatabaseHas('audit_log', ['action' => 'platform.marketing.image_removed']);

    $item = marketingItem('time');
    $this->post("/api/v1/admin/marketing/items/{$item->public_id}/image", [
        'image' => UploadedFile::fake()->image('row.jpg', 1280, 800),
    ], ['Accept' => 'application/json'])->assertCreated();
    $item->refresh();
    $rowPath = $item->image_path;

    $this->getJson('/api/v1/marketing-content')->assertOk();
    $this->deleteJson("/api/v1/admin/marketing/items/{$item->public_id}")->assertOk();
    expect(MarketingItem::query()->where('slug', 'time')->exists())->toBeFalse()
        ->and(Cache::has(MarketingSection::PUBLIC_CACHE_KEY))->toBeFalse();
    $disk->assertMissing($rowPath);
    $this->assertDatabaseHas('audit_log', ['action' => 'platform.marketing.item_deleted']);
});

it('keeps the storage path out of tenant space', function () {
    expect(FileStorageService::marketingImagePath('01ABC', 'deadbeefdeadbeef'))->toBe('platform/marketing/01ABC-deadbeefdeadbeef.jpg')
        ->and(FileStorageService::marketingImagePath('01ABC', 'deadbeefdeadbeef', 640))->toBe('platform/marketing/01ABC-deadbeefdeadbeef-640.jpg');
});
