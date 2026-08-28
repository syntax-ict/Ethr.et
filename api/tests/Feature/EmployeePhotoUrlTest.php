<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Services\FileStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * `photo_path` is an object key a browser cannot render. Clients need signed
 * URLs — and the 150px thumbnail for list and avatar contexts.
 */
test('employee detail exposes signed photo urls', function () {
    Storage::fake('minio');

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'photo_path' => "tenants/{$tenant->public_id}/photos/abc.jpg",
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$employee->public_id}");

    $response->assertOk();

    expect($response->json('photo_url'))->toBeString()
        ->and($response->json('photo_thumb_url'))->toBeString()
        ->and($response->json('photo_thumb_url'))->toContain('thumbs/abc_150.jpg');
});

test('an employee without a photo reports null urls rather than a broken link', function () {
    Storage::fake('minio');

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'photo_path' => null,
    ]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$employee->public_id}")
        ->assertOk()
        ->assertJsonPath('photo_url', null)
        ->assertJsonPath('photo_thumb_url', null);
});

test('uploading a profile photo generates both thumbnail sizes', function () {
    Storage::fake('minio');

    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    $storage = app(FileStorageService::class);

    $binary = base64_decode(explode(',', selfieDataUrl(900, 900))[1], true);
    $file = UploadedFile::fake()->createWithContent('photo.jpg', (string) $binary);

    $stored = $storage->upload($file, 'photos');

    expect($stored['size'])->toBeLessThanOrEqual(FileStorageService::PHOTO_MAX_BYTES)
        ->and($stored['variants'])->toHaveKeys(FileStorageService::THUMBNAIL_SIZES);

    foreach (FileStorageService::THUMBNAIL_SIZES as $size) {
        Storage::disk('minio')->assertExists($stored['variants'][$size]);

        $thumb = imagecreatefromstring(Storage::disk('minio')->get($stored['variants'][$size]));
        expect(max(imagesx($thumb), imagesy($thumb)))->toBeLessThanOrEqual($size);
    }
});

test('signed urls are reused within the cache window instead of re-signed', function () {
    Storage::fake('minio');

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $storage = app(FileStorageService::class);
    $path = "tenants/{$tenant->public_id}/photos/cached.jpg";

    expect($storage->temporaryUrl($path))->toBe($storage->temporaryUrl($path));
});
