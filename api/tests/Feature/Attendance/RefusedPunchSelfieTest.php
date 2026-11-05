<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\Tenant;
use App\Support\PhotoConsent;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

// A-Z review D-02 (2026-11-04). The phone's selfie is stored before the punch
// is decided. A punch that was refused, or a retry the server answered with
// the record it already had, left that photo stored with no record pointing
// at it, so no retention schedule ever deleted it.

function refusedSelfieSetUp(array $settings = []): array
{
    Storage::fake('local');
    $tenant = createTenant();
    AttendanceSetting::create(['tenant_id' => $tenant->id, ...AttendanceSetting::defaults(), ...$settings]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    PhotoConsent::give($user);
    test()->actingAs($user);

    return [$tenant, $employee];
}

function refusedSelfiePunch(Tenant $tenant, string $type, string $key): TestResponse
{
    return test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/{$type}", [
        'idempotency_key' => $key,
        'latitude' => 9.0192,
        'longitude' => 38.7525,
        'photo' => selfieDataUrl(),
    ]);
}

/** @return list<string> */
function storedSelfies(): array
{
    return array_values(array_filter(Storage::disk('local')->allFiles(), fn (string $path) => str_contains($path, '/selfies/')));
}

it('keeps no photo of a refused check-in', function () {
    [$tenant] = refusedSelfieSetUp(['enabled_methods' => ['web']]);

    refusedSelfiePunch($tenant, 'check-in', (string) Str::uuid())->assertStatus(422);

    expect(storedSelfies())->toBe([]);
});

it('keeps no photo of a check-out with nothing to close', function () {
    [$tenant] = refusedSelfieSetUp();

    refusedSelfiePunch($tenant, 'check-out', (string) Str::uuid())->assertStatus(422);

    expect(storedSelfies())->toBe([]);
});

it('keeps only the first photo when a check-in is retried', function () {
    [$tenant, $employee] = refusedSelfieSetUp();
    $key = (string) Str::uuid();

    refusedSelfiePunch($tenant, 'check-in', $key)->assertStatus(201);
    refusedSelfiePunch($tenant, 'check-in', $key)->assertStatus(200);

    $record = AttendanceRecord::query()->where('employee_id', $employee->id)->sole();
    expect(storedSelfies())->toBe([$record->photo_path]);
});

it('keeps only the first photo when a check-out is retried', function () {
    [$tenant, $employee] = refusedSelfieSetUp();
    refusedSelfiePunch($tenant, 'check-in', (string) Str::uuid())->assertStatus(201);
    $key = (string) Str::uuid();

    refusedSelfiePunch($tenant, 'check-out', $key)->assertOk();
    refusedSelfiePunch($tenant, 'check-out', $key)->assertOk();

    $record = AttendanceRecord::query()->where('employee_id', $employee->id)->sole();
    expect(storedSelfies())->toEqualCanonicalizing(array_values($record->photoPaths()));
});
