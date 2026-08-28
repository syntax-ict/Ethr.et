<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\FileStorageService;
use Illuminate\Support\Facades\Storage;

test('mobile check-in stores the selfie and records its object key', function () {
    Storage::fake('minio');

    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/check-in", [
        'idempotency_key' => (string) Str::uuid(),
        'latitude' => 9.0192,
        'longitude' => 38.7525,
        'photo' => selfieDataUrl(),
    ]);

    $response->assertStatus(201);

    $record = AttendanceRecord::query()->where('employee_id', $employee->id)->firstOrFail();

    expect($record->photo_path)->toBeString()
        ->and($record->photo_path)->toContain('/selfies/')
        ->and($record->photo_path)->not->toStartWith('data:');

    Storage::disk('minio')->assertExists($record->photo_path);
});

test('a check-out selfie is kept without overwriting the check-in one', function () {
    Storage::fake('minio');

    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/check-in", [
        'idempotency_key' => (string) Str::uuid(),
        'latitude' => 9.0192,
        'longitude' => 38.7525,
        'photo' => selfieDataUrl(),
    ])->assertStatus(201);

    $checkInPhoto = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->value('photo_path');

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/check-out", [
        'idempotency_key' => (string) Str::uuid(),
        'latitude' => 9.0192,
        'longitude' => 38.7525,
        'photo' => selfieDataUrl(),
    ])->assertStatus(200);

    $record = AttendanceRecord::query()->where('employee_id', $employee->id)->firstOrFail();
    $checkOutPhoto = $record->metadata['checkout_photo_path'] ?? null;

    expect($record->photo_path)->toBe($checkInPhoto)
        ->and($checkOutPhoto)->toBeString()
        ->and($checkOutPhoto)->not->toBe($checkInPhoto);

    Storage::disk('minio')->assertExists($checkInPhoto);
    Storage::disk('minio')->assertExists($checkOutPhoto);
});

test('mobile check-in rejects a non-image payload', function () {
    Storage::fake('minio');

    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/check-in", [
        'idempotency_key' => (string) Str::uuid(),
        'latitude' => 9.0192,
        'longitude' => 38.7525,
        'photo' => 'data:image/jpeg;base64,'.base64_encode('not really a jpeg'),
    ])->assertStatus(422)->assertJsonPath('errors.photo.0', __('validation.file_magic_bytes_invalid'));

    expect(Storage::disk('minio')->allFiles())->toBeEmpty();
});

test('mobile check-in rejects an unsupported image type', function () {
    Storage::fake('minio');

    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/check-in", [
        'idempotency_key' => (string) Str::uuid(),
        'latitude' => 9.0192,
        'longitude' => 38.7525,
        'photo' => 'data:image/svg+xml;base64,'.base64_encode('<svg onload="alert(1)"/>'),
    ])->assertStatus(422)->assertJsonPath('errors.photo.0', __('validation.base64_image_unsupported_type'));
});

test('stored selfies are compressed within the mobile byte budget', function () {
    Storage::fake('minio');

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $stored = app(FileStorageService::class)
        ->uploadDataUrlImage(selfieDataUrl(1600, 1600), 'selfies');

    expect($stored['size'])->toBeLessThanOrEqual(FileStorageService::SELFIE_MAX_BYTES)
        ->and($stored['mime_type'])->toBe('image/jpeg');

    $image = imagecreatefromstring(Storage::disk('minio')->get($stored['path']));

    expect(max(imagesx($image), imagesy($image)))
        ->toBeLessThanOrEqual(FileStorageService::SELFIE_MAX_DIMENSION);
});
