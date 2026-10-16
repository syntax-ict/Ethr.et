<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * A news story's cover photo (2026-10-16): uploaded on the publishing desk,
 * re-encoded as the organisation's own cover is, and served publicly by
 * NewsCoverController at an address that changes with every upload. These pin
 * what is stored (bounded, narrower copies, only pixels), who may change it,
 * that a replaced, removed or deleted cover stops being served, and that the
 * address alone decides the file: no other organisation's story, and nothing
 * for an organisation that is not in use.
 */

beforeEach(function () {
    Storage::fake((string) config('filesystems.default'));
});

function coverStory(Tenant $tenant, array $attributes = []): Announcement
{
    return Announcement::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'published_by' => createUser([], $tenant)->id,
        'title' => 'Plant reopens Monday',
        'body' => 'All shifts resume.',
        'priority' => 'normal',
        'target_type' => 'all',
        'published_at' => now()->subHour(),
        'show_on_landing' => true,
        ...$attributes,
    ]);
}

function coverDesk(): array
{
    $tenant = createTenant(['subdomain' => 'acme']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    return [$tenant, coverStory($tenant)];
}

function uploadCover(Announcement $story, UploadedFile $file): TestResponse
{
    return test()->post("http://acme.ethr.test/api/v1/announcements/{$story->public_id}/cover", ['image' => $file], ['Accept' => 'application/json']);
}

function coverDisk(): Filesystem
{
    return Storage::disk((string) config('filesystems.default'));
}

it('bounds the cover to 1920 px, encodes narrower copies, keeps only the pixels and serves it publicly', function () {
    [$tenant, $story] = coverDesk();

    $jpeg = UploadedFile::fake()->image('opening.jpg', 3000, 1500);
    $bytes = (string) file_get_contents($jpeg->getPathname());
    $exif = "\xFF\xE1".pack('n', 18)."Exif\x00\x00GPS-SECRET";
    file_put_contents($jpeg->getPathname(), substr($bytes, 0, 2).$exif.substr($bytes, 2));

    $cover = uploadCover($story, $jpeg)->assertOk()->json('cover');

    $stored = $story->refresh()->coverImage();
    $version = $stored['version'];
    $base = "/api/v1/news-covers/{$tenant->public_id}/{$story->public_id}";

    expect($version)->toMatch('/^[0-9a-f]{16}$/')
        ->and(getimagesizefromstring(coverDisk()->get($stored['path']))[0])->toBe(1920)
        ->and(coverDisk()->get($stored['path']))->not->toContain('GPS-SECRET')
        ->and(array_keys($stored['variants']))->toBe([1280, 640])
        ->and($cover)->toBe([
            'url' => "{$base}/cover/{$version}",
            'srcset' => "{$base}/cover-1280/{$version} 1280w, {$base}/cover-640/{$version} 640w, {$base}/cover/{$version} 1920w",
            'width' => 1920,
            'height' => 960,
        ])
        ->and(AuditLog::where('action', 'announcement.cover_updated')->exists())->toBeTrue();

    app('auth')->forgetGuards();
    $response = test()->get("http://www.ethr.test{$base}/cover-640/{$version}")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(getimagesizefromstring((string) $response->getContent())[0])->toBe(640);
});

it('shows the cover on the public news list and the article', function () {
    [$tenant, $story] = coverDesk();
    $cover = uploadCover($story, UploadedFile::fake()->image('opening.jpg', 1200, 600))->assertOk()->json('cover');

    app('auth')->forgetGuards();
    test()->getJson('http://acme.ethr.test/api/v1/auth/tenant-landing/news')
        ->assertOk()
        ->assertJsonPath('data.0.cover', $cover);
    test()->getJson("http://acme.ethr.test/api/v1/auth/tenant-landing/announcements/{$story->public_id}")
        ->assertOk()
        ->assertJsonPath('cover.url', $cover['url'])
        ->assertJsonPath('cover.width', 1200);
    test()->getJson('http://acme.ethr.test/api/v1/auth/tenant-landing/feed')
        ->assertOk()
        ->assertJsonPath('announcements.0.cover.url', $cover['url']);
});

it('stops serving a cover once it is replaced, removed, or its story is deleted', function () {
    [$tenant, $story] = coverDesk();

    $first = uploadCover($story, UploadedFile::fake()->image('a.jpg', 1000, 500))->assertOk()->json('cover.url');
    $second = uploadCover($story, UploadedFile::fake()->image('b.jpg', 1100, 500))->assertOk()->json('cover.url');

    expect($second)->not->toBe($first);
    test()->get("http://www.ethr.test{$first}")->assertNotFound();
    test()->get("http://www.ethr.test{$second}")->assertOk();

    test()->deleteJson("http://acme.ethr.test/api/v1/announcements/{$story->public_id}/cover")
        ->assertOk()
        ->assertJsonPath('cover', null);
    test()->get("http://www.ethr.test{$second}")->assertNotFound();
    expect(AuditLog::where('action', 'announcement.cover_removed')->exists())->toBeTrue()
        ->and(coverDisk()->allFiles("tenants/{$tenant->public_id}/news"))->toBe([]);

    $third = uploadCover($story, UploadedFile::fake()->image('c.jpg', 1000, 500))->assertOk()->json('cover.url');
    test()->deleteJson("http://acme.ethr.test/api/v1/announcements/{$story->public_id}")->assertNoContent();
    test()->get("http://www.ethr.test{$third}")->assertNotFound();
    expect(coverDisk()->allFiles("tenants/{$tenant->public_id}/news"))->toBe([]);
});

it('takes a cover only from someone who manages announcements', function () {
    $tenant = createTenant(['subdomain' => 'acme']);
    $story = coverStory($tenant);
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    uploadCover($story, UploadedFile::fake()->image('a.jpg', 1000, 500))->assertForbidden();
    test()->deleteJson("http://acme.ethr.test/api/v1/announcements/{$story->public_id}/cover")->assertForbidden();
    expect($story->refresh()->cover)->toBeNull();
});

it('refuses a cover too narrow for the page, or one that is not a photo', function () {
    [, $story] = coverDesk();

    uploadCover($story, UploadedFile::fake()->image('small.jpg', 800, 400))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');
    uploadCover($story, UploadedFile::fake()->createWithContent('cover.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    expect($story->refresh()->cover)->toBeNull();
});

it('cannot give another organisation\'s story a cover', function () {
    [, $story] = coverDesk();
    $other = coverStory(createTenant(['subdomain' => 'habru']));

    uploadCover($other, UploadedFile::fake()->image('a.jpg', 1000, 500))->assertNotFound();
    expect($other->refresh()->cover)->toBeNull();
});

it('serves a cover only at the address its own organisation names, and only while that organisation is in use', function () {
    [$tenant, $story] = coverDesk();
    $url = uploadCover($story, UploadedFile::fake()->image('a.jpg', 1000, 500))->assertOk()->json('cover.url');
    $other = createTenant(['subdomain' => 'habru']);
    app('auth')->forgetGuards();

    test()->get('http://www.ethr.test'.str_replace($tenant->public_id, $other->public_id, $url))->assertNotFound();
    test()->get('http://www.ethr.test'.preg_replace('#/[0-9a-f]{16}$#', '/0000000000000000', $url))->assertNotFound();

    $tenant->update(['status' => TenantStatus::SUSPENDED]);
    test()->get("http://www.ethr.test{$url}")->assertNotFound();
});
