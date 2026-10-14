<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * An organisation's other brand images (organisation portal, PR 2 of 4): a
 * logo for dark backgrounds, a square mark, a cover photo with a focal
 * point. These pin what is stored and served (re-encoded, bounded,
 * immutable per version), the shapes each kind is normalised to, who may
 * change them, and how the portal's context and the sign-in page's photo
 * background read them.
 */

beforeEach(function () {
    Storage::fake((string) config('filesystems.default'));
});

function assetAdmin(): Tenant
{
    $tenant = createTenant(['subdomain' => 'acme', 'logo_path' => null]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    return $tenant;
}

function uploadAsset(string $kind, UploadedFile $file): TestResponse
{
    return test()->post("http://acme.ethr.test/api/v1/settings/branding/assets/{$kind}", ['image' => $file], ['Accept' => 'application/json']);
}

function storedAsset(Tenant $tenant, string $kind): string
{
    return Storage::disk((string) config('filesystems.default'))->get($tenant->refresh()->brandAsset($kind)['path']);
}

it('stores a dark-background logo and serves it publicly, immutable per version', function () {
    $tenant = assetAdmin();

    $url = uploadAsset('logo_dark', UploadedFile::fake()->image('logo-dark.png', 300, 120))
        ->assertCreated()
        ->json('assets.logo_dark.url');

    $tenant->refresh();
    expect($tenant->brandAsset('logo_dark')['version'])->toMatch('/^[0-9a-f]{16}$/')
        ->and($url)->toBe("/api/v1/organisation-assets/{$tenant->public_id}/logo-dark/{$tenant->brandAsset('logo_dark')['version']}")
        ->and(AuditLog::where('action', 'settings.brand_asset_updated')->exists())->toBeTrue();

    app('auth')->forgetGuards();
    $response = test()->get("http://acme.ethr.test{$url}")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toStartWith("default-src 'none'")
        ->and(substr((string) $response->getContent(), 0, 8))->toBe("\x89PNG\r\n\x1a\n");
});

it('centres the mark on a 512 px square with a safe margin', function () {
    $tenant = assetAdmin();

    uploadAsset('mark', UploadedFile::fake()->image('mark.png', 120, 40))->assertCreated();

    $stored = storedAsset($tenant, 'mark');
    [$width, $height] = getimagesizefromstring($stored);
    $image = imagecreatefromstring($stored);

    // A wide source fills 80% of the width and sits in the vertical middle;
    // the corners stay transparent.
    expect([$width, $height])->toBe([512, 512])
        ->and(imagecolorsforindex($image, imagecolorat($image, 2, 2))['alpha'])->toBe(127)
        ->and(imagecolorsforindex($image, imagecolorat($image, 256, 256))['alpha'])->toBe(0)
        ->and(imagecolorsforindex($image, imagecolorat($image, 60, 256))['alpha'])->toBe(0)
        ->and(imagecolorsforindex($image, imagecolorat($image, 40, 256))['alpha'])->toBe(127);
});

it('bounds the cover to 1920 px, encodes narrower copies for srcset, and keeps only the pixels', function () {
    $tenant = assetAdmin();

    $jpeg = UploadedFile::fake()->image('plant.jpg', 3000, 1500);
    $bytes = (string) file_get_contents($jpeg->getPathname());
    $exif = "\xFF\xE1".pack('n', 18)."Exif\x00\x00GPS-SECRET";
    file_put_contents($jpeg->getPathname(), substr($bytes, 0, 2).$exif.substr($bytes, 2));

    $cover = uploadAsset('cover', $jpeg)->assertCreated()->json('assets.cover');

    $stored = storedAsset($tenant, 'cover');
    [$width, $height] = getimagesizefromstring($stored);
    $version = $tenant->brandAsset('cover')['version'];
    $base = "/api/v1/organisation-assets/{$tenant->public_id}";

    expect([$width, $height])->toBe([1920, 960])
        ->and($stored)->not->toContain('GPS-SECRET')
        ->and($cover)->toBe([
            'url' => "{$base}/cover/{$version}",
            'srcset' => "{$base}/cover-640/{$version} 640w, {$base}/cover-1280/{$version} 1280w, {$base}/cover/{$version} 1920w",
            'width' => 1920,
            'height' => 960,
            'focal_x' => 0.5,
            'focal_y' => 0.5,
        ]);

    app('auth')->forgetGuards();
    $narrow = test()->get("http://acme.ethr.test{$base}/cover-640/{$version}")->assertOk();
    expect($narrow->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and(getimagesizefromstring((string) $narrow->getContent())[0])->toBe(640);
});

it('encodes no copy wider than the cover itself', function () {
    $tenant = assetAdmin();

    $cover = uploadAsset('cover', UploadedFile::fake()->image('plant.jpg', 1000, 500))->assertCreated()->json('assets.cover');

    expect($cover['width'])->toBe(1000)
        ->and($cover['srcset'])->toContain('640w')
        ->and($cover['srcset'])->not->toContain('1280w')
        ->and(array_keys($tenant->refresh()->brandAsset('cover')['variants']))->toBe([640]);
});

it('keeps the focal point across a replacement and bounds it to the frame', function () {
    $tenant = assetAdmin();
    uploadAsset('cover', UploadedFile::fake()->image('a.jpg', 1000, 500))->assertCreated();

    test()->putJson('http://acme.ethr.test/api/v1/settings/branding/assets/cover/focal', ['x' => 0.25, 'y' => 0.8])
        ->assertOk()
        ->assertJsonPath('assets.cover.focal_x', 0.25)
        ->assertJsonPath('assets.cover.focal_y', 0.8);

    test()->putJson('http://acme.ethr.test/api/v1/settings/branding/assets/cover/focal', ['x' => 1.5, 'y' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('x');

    uploadAsset('cover', UploadedFile::fake()->image('b.jpg', 1200, 600))
        ->assertCreated()
        ->assertJsonPath('assets.cover.focal_x', 0.25);

    expect($tenant->refresh()->brandAsset('cover')['focal'])->toBe(['x' => 0.25, 'y' => 0.8]);
});

it('has no focal point to set without a cover', function () {
    assetAdmin();

    test()->putJson('http://acme.ethr.test/api/v1/settings/branding/assets/cover/focal', ['x' => 0.5, 'y' => 0.5])
        ->assertNotFound();
});

it('refuses what is not a usable raster image', function (string $kind, UploadedFile $file) {
    assetAdmin();

    uploadAsset($kind, $file)->assertUnprocessable()->assertJsonValidationErrors('image');
})->with([
    'svg mark' => ['mark', fn () => UploadedFile::fake()->createWithContent('mark.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
    'text named png' => ['logo_dark', fn () => UploadedFile::fake()->createWithContent('logo.png', 'not an image at all')],
    'too large' => ['cover', fn () => UploadedFile::fake()->image('a.jpg', 1200, 600)->size(6000)],
    'a narrow cover' => ['cover', fn () => UploadedFile::fake()->image('a.jpg', 600, 400)],
]);

it('answers nothing for an unknown kind', function () {
    assetAdmin();

    uploadAsset('favicon', UploadedFile::fake()->image('a.png', 100, 100))->assertNotFound();
});

it('gives a new upload a new URL and removes the old files, variants included', function () {
    $tenant = assetAdmin();
    $disk = Storage::disk((string) config('filesystems.default'));

    $first = uploadAsset('cover', UploadedFile::fake()->image('a.jpg', 1500, 700))->json('assets.cover.url');
    $firstAsset = $tenant->refresh()->brandAsset('cover');
    $second = uploadAsset('cover', UploadedFile::fake()->image('b.jpg', 1600, 700))->json('assets.cover.url');

    expect($second)->not->toBe($first)
        ->and($disk->exists($firstAsset['path']))->toBeFalse()
        ->and($disk->exists($firstAsset['variants']['1280']))->toBeFalse();

    app('auth')->forgetGuards();
    test()->get("http://acme.ethr.test{$first}")->assertNotFound();
    test()->get("http://acme.ethr.test{$second}")->assertOk();
});

it('serves nothing for a guessed version, a wrong kind or an organisation not in use', function () {
    $tenant = assetAdmin();
    $url = uploadAsset('mark', UploadedFile::fake()->image('a.png', 100, 100))->json('assets.mark.url');
    $version = $tenant->refresh()->brandAsset('mark')['version'];
    app('auth')->forgetGuards();

    $base = "http://acme.ethr.test/api/v1/organisation-assets/{$tenant->public_id}";
    test()->get("{$base}/mark/0000000000000000")->assertNotFound();
    // The mark's version does not open another kind, nor a cover variant.
    test()->get("{$base}/logo-dark/{$version}")->assertNotFound();
    test()->get("{$base}/cover-640/{$version}")->assertNotFound();

    $tenant->update(['status' => TenantStatus::SUSPENDED]);
    test()->get("http://localhost{$url}")->assertNotFound();
});

it('lets only an admin change them', function () {
    $tenant = createTenant(['subdomain' => 'acme']);
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    uploadAsset('mark', UploadedFile::fake()->image('a.png', 100, 100))->assertForbidden();
    test()->deleteJson('http://acme.ethr.test/api/v1/settings/branding/assets/mark')->assertForbidden();
    test()->putJson('http://acme.ethr.test/api/v1/settings/branding/assets/cover/focal', ['x' => 0.5, 'y' => 0.5])->assertForbidden();
});

it('hands the portal every image, and the settings screen the same', function () {
    $tenant = assetAdmin();

    uploadAsset('logo_dark', UploadedFile::fake()->image('d.png', 200, 80))->assertCreated();
    uploadAsset('mark', UploadedFile::fake()->image('m.png', 100, 100))->assertCreated();
    uploadAsset('cover', UploadedFile::fake()->image('c.jpg', 1400, 700))->assertCreated();

    $settings = test()->getJson('http://acme.ethr.test/api/v1/settings')->assertOk()->json('branding.assets');

    app('auth')->forgetGuards();
    $brand = test()->getJson('http://acme.ethr.test/api/v1/auth/tenant-context')->assertOk()->json('tenant.brand');

    expect($brand['logo_dark_url'])->toBe($settings['logo_dark']['url'])
        ->and($brand['mark_url'])->toBe($settings['mark']['url'])
        ->and($brand['cover'])->toBe($settings['cover'])
        ->and($brand['cover']['srcset'])->toContain('1280w');
});

it('offers the photo background once a cover exists, and withdraws it when the cover goes', function () {
    $tenant = assetAdmin();
    $landing = 'http://acme.ethr.test/api/v1/settings/landing';

    test()->putJson($landing, ['background' => 'image'])->assertUnprocessable()->assertJsonValidationErrors('background');

    uploadAsset('cover', UploadedFile::fake()->image('c.jpg', 1400, 700))->assertCreated();
    test()->putJson($landing, ['background' => 'image'])->assertOk()->assertJsonPath('background', 'image');

    test()->deleteJson('http://acme.ethr.test/api/v1/settings/branding/assets/cover')
        ->assertOk()
        ->assertJsonPath('assets.cover', null);
    expect(AuditLog::where('action', 'settings.brand_asset_removed')->exists())->toBeTrue()
        ->and($tenant->refresh()->brand_assets)->toBeNull();

    app('auth')->forgetGuards();
    test()->getJson('http://acme.ethr.test/api/v1/auth/tenant-context')
        ->assertJsonPath('tenant.landing.background', 'brand')
        ->assertJsonPath('tenant.brand.cover', null);
});

it('ignores a hand-edited entry that is not whole', function () {
    $tenant = createTenant(['subdomain' => 'acme', 'brand_assets' => ['mark' => ['path' => 'x.png']]]);

    expect($tenant->brandAsset('mark'))->toBeNull()
        ->and($tenant->brandAssetUrl('mark'))->toBeNull();

    test()->getJson('http://acme.ethr.test/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant.brand.mark_url', null);
});
