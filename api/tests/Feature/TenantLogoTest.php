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
 * An organisation's uploaded logo (organisation-landing follow-up G1).
 *
 * The logo could only be an https link, and most organisations have nowhere to
 * host one, so their sign-in page could not carry their mark. These pin the
 * upload, what is stored and served (re-encoded PNG only, bounded, immutable
 * per version), who may change it, and that every consumer — the public
 * sign-in page, the dashboard, the kiosk — shows the same one.
 */

beforeEach(function () {
    Storage::fake((string) config('filesystems.default'));
});

function logoAdmin(): Tenant
{
    $tenant = createTenant(['subdomain' => 'acme', 'logo_path' => null]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    return $tenant;
}

function uploadLogo(UploadedFile $file): TestResponse
{
    return test()->post('http://acme.ethr.test/api/v1/settings/branding/logo', ['logo' => $file], ['Accept' => 'application/json']);
}

it('stores an uploaded logo and serves it publicly, immutable per version', function () {
    $tenant = logoAdmin();

    $url = uploadLogo(UploadedFile::fake()->image('logo.png', 300, 120))
        ->assertCreated()
        ->json('uploaded_logo_url');

    $tenant->refresh();
    expect($tenant->logo_version)->toMatch('/^[0-9a-f]{16}$/')
        ->and($url)->toBe("/api/v1/organisation-logos/{$tenant->public_id}/{$tenant->logo_version}")
        ->and(AuditLog::where('action', 'settings.logo_updated')->exists())->toBeTrue();

    app('auth')->forgetGuards();
    $response = test()->get("http://acme.ethr.test{$url}")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toStartWith("default-src 'none'")
        ->and(substr((string) $response->getContent(), 0, 8))->toBe("\x89PNG\r\n\x1a\n");
});

it('bounds the logo to 512 px and keeps only the pixels', function () {
    $tenant = logoAdmin();

    // A JPEG carrying an EXIF segment, as a phone camera writes one.
    $jpeg = UploadedFile::fake()->image('photo.jpg', 2000, 1000);
    $bytes = (string) file_get_contents($jpeg->getPathname());
    $exif = "\xFF\xE1".pack('n', 18)."Exif\x00\x00GPS-SECRET";
    file_put_contents($jpeg->getPathname(), substr($bytes, 0, 2).$exif.substr($bytes, 2));

    uploadLogo($jpeg)->assertCreated();

    $stored = Storage::disk((string) config('filesystems.default'))->get($tenant->refresh()->logo_storage_path);
    [$width, $height] = getimagesizefromstring($stored);

    expect(max($width, $height))->toBe(512)
        ->and($stored)->not->toContain('Exif')
        ->and($stored)->not->toContain('GPS-SECRET');
});

it('refuses what is not a raster image', function (UploadedFile $file) {
    logoAdmin();

    uploadLogo($file)->assertUnprocessable()->assertJsonValidationErrors('logo');
})->with([
    'svg' => fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'text named png' => fn () => UploadedFile::fake()->createWithContent('logo.png', 'not an image at all'),
    'too large' => fn () => UploadedFile::fake()->image('logo.png', 200, 200)->size(2048),
    'too small' => fn () => UploadedFile::fake()->image('logo.png', 16, 16),
]);

it('gives a new upload a new URL, and the old one stops answering', function () {
    $tenant = logoAdmin();

    $first = uploadLogo(UploadedFile::fake()->image('a.png', 100, 100))->json('uploaded_logo_url');
    $firstPath = $tenant->refresh()->logo_storage_path;
    $second = uploadLogo(UploadedFile::fake()->image('b.png', 300, 100))->json('uploaded_logo_url');

    expect($second)->not->toBe($first)
        ->and(Storage::disk((string) config('filesystems.default'))->exists($firstPath))->toBeFalse();

    app('auth')->forgetGuards();
    test()->get("http://acme.ethr.test{$first}")->assertNotFound();
    test()->get("http://acme.ethr.test{$second}")->assertOk();
});

it('serves nothing for a guessed version or an organisation not in use', function () {
    $tenant = logoAdmin();
    $url = uploadLogo(UploadedFile::fake()->image('a.png', 100, 100))->json('uploaded_logo_url');
    app('auth')->forgetGuards();

    test()->get("http://acme.ethr.test/api/v1/organisation-logos/{$tenant->public_id}/0000000000000000")->assertNotFound();

    $tenant->update(['status' => TenantStatus::SUSPENDED]);
    // On its own host ResolveTenant already refuses a suspended organisation;
    // through a host that names none, the controller's own check answers.
    test()->get("http://acme.ethr.test{$url}")->assertForbidden();
    test()->get("http://localhost{$url}")->assertNotFound();
});

it('lets only an admin change it', function () {
    $tenant = createTenant(['subdomain' => 'acme']);
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    uploadLogo(UploadedFile::fake()->image('a.png', 100, 100))->assertForbidden();
    test()->deleteJson('http://acme.ethr.test/api/v1/settings/branding/logo')->assertForbidden();
});

it('shows the same logo on the sign-in page, the dashboard and the kiosk, and falls back to the link when removed', function () {
    $tenant = logoAdmin();
    $tenant->update(['logo_path' => 'https://cdn.acme.test/logo.png']);

    $url = uploadLogo(UploadedFile::fake()->image('a.png', 100, 100))->json('uploaded_logo_url');

    test()->getJson('http://acme.ethr.test/api/v1/auth/me')->assertJsonPath('tenant.logo_path', $url);
    test()->getJson('http://acme.ethr.test/api/v1/settings')
        ->assertJsonPath('branding.uploaded_logo_url', $url)
        ->assertJsonPath('branding.logo_url', 'https://cdn.acme.test/logo.png');

    test()->deleteJson('http://acme.ethr.test/api/v1/settings/branding/logo')
        ->assertOk()
        ->assertJsonPath('uploaded_logo_url', null)
        ->assertJsonPath('logo_url', 'https://cdn.acme.test/logo.png');

    app('auth')->forgetGuards();
    test()->getJson('http://acme.ethr.test/api/v1/auth/tenant-context')
        ->assertJsonPath('tenant.brand.logo_url', 'https://cdn.acme.test/logo.png');
});
