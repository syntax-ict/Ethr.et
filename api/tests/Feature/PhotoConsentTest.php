<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PhotoConsent;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Consent to the attendance selfie (2026-10-17). A selfie is a facial image,
 * which Proclamation 1321/2024 counts as biometric and so sensitive (Art.
 * 2(5), 2(6)); it is processed only with the employee's prior consent to that
 * purpose. These pin that the employee gives and withdraws it themselves,
 * that both are audited, that the server refuses a selfie without it, that an
 * offline punch keeps its record but not a photo taken without it, and that
 * the phone is told where the employee stands.
 */

beforeEach(function () {
    Storage::fake('local');
});

/** @return array{0: Tenant, 1: Employee, 2: User} */
function consentEmployee(): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    return [$tenant, $employee, $user];
}

function consentUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

function selfieCheckIn(Tenant $tenant, array $extra = []): TestResponse
{
    return test()->postJson(consentUrl($tenant, '/attendance/mobile/check-in'), [
        'idempotency_key' => (string) Str::uuid(),
        'latitude' => 9.0192,
        'longitude' => 38.7525,
        ...$extra,
    ]);
}

it('is not given until the employee gives it, and is recorded when they do and when they withdraw', function () {
    [$tenant, , $user] = consentEmployee();

    test()->getJson(consentUrl($tenant, '/profile/photo-consent'))
        ->assertOk()
        ->assertJsonPath('given', false)
        ->assertJsonPath('current_version', PhotoConsent::VERSION);

    test()->postJson(consentUrl($tenant, '/profile/photo-consent'))
        ->assertOk()
        ->assertJsonPath('given', true)
        ->assertJsonPath('version', PhotoConsent::VERSION);
    expect(AuditLog::where('action', 'profile.photo_consent_given')->count())->toBe(1);

    test()->deleteJson(consentUrl($tenant, '/profile/photo-consent'))
        ->assertOk()
        ->assertJsonPath('given', false);
    expect(AuditLog::where('action', 'profile.photo_consent_withdrawn')->count())->toBe(1)
        ->and(PhotoConsent::given($user->refresh()))->toBeFalse();
});

it('refuses a selfie from someone who has not agreed, and takes it once they have', function () {
    [$tenant, $employee, $user] = consentEmployee();

    selfieCheckIn($tenant, ['photo' => selfieDataUrl()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('photo');
    expect(AttendanceRecord::query()->where('employee_id', $employee->id)->exists())->toBeFalse();

    PhotoConsent::give($user);
    selfieCheckIn($tenant, ['photo' => selfieDataUrl()])->assertCreated();
    expect(AttendanceRecord::query()->where('employee_id', $employee->id)->value('photo_path'))->toBeString();
});

it('counts only consent to the current notice', function () {
    [$tenant, , $user] = consentEmployee();
    $user->forceFill(['preferences' => ['attendance_photo_consent' => ['version' => '2020-01-01', 'given_at' => now()->toIso8601String()]]])->save();

    expect(PhotoConsent::given($user->refresh()))->toBeFalse();
    selfieCheckIn($tenant, ['photo' => selfieDataUrl()])->assertUnprocessable();
});

it('keeps an offline punch but not a photo taken without consent', function () {
    [$tenant, $employee] = consentEmployee();

    test()->postJson(consentUrl($tenant, '/attendance/sync'), [
        'records' => [[
            'idempotency_key' => 'offline-consent-1',
            'employee_public_id' => $employee->public_id,
            'type' => 'check_in',
            'timestamp' => now()->subHour()->toIso8601String(),
            'latitude' => 9.0,
            'longitude' => 38.0,
            'offline_token' => hash('sha256', 'offline-consent-1'),
            'photo' => selfieDataUrl(),
        ]],
    ])->assertOk()->assertJsonPath('summary.created', 1);

    $record = AttendanceRecord::query()->where('employee_id', $employee->id)->firstOrFail();
    expect($record->photo_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('tells the phone whether the employee has agreed', function () {
    [$tenant, , $user] = consentEmployee();
    AttendanceSetting::create(['tenant_id' => $tenant->id, ...AttendanceSetting::defaults(), 'mobile_photo_required' => true]);

    test()->getJson(consentUrl($tenant, '/attendance/my-shift'))
        ->assertOk()
        ->assertJsonPath('policy.photo_required', true)
        ->assertJsonPath('policy.photo_consent', false);

    PhotoConsent::give($user);
    test()->getJson(consentUrl($tenant, '/attendance/my-shift'))
        ->assertOk()
        ->assertJsonPath('policy.photo_consent', true);
});

it('survives a change of display preferences, which never touch it', function () {
    [$tenant, , $user] = consentEmployee();
    PhotoConsent::give($user);

    test()->putJson(consentUrl($tenant, '/profile/preferences'), ['theme' => 'dark'])->assertOk();

    expect(PhotoConsent::given($user->refresh()))->toBeTrue();
});
