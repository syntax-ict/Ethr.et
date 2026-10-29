<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Notifications\LeaveApprovedNotification;
use App\Notifications\LeaveRequestedNotification;
use Illuminate\Support\Str;

// Every notification is written in the recipient's language, and opens what
// it is about. Until 2026-10-20 User did not implement HasLocalePreference, so
// a queued e-mail rendered in APP_LOCALE, and most in-app messages were English
// literals; clicking one only marked it read (blueprint assessment N8, N11).

function languageLeave(int $tenantId): LeaveRequest
{
    $employee = Employee::factory()->create(['tenant_id' => $tenantId]);

    return LeaveRequest::factory()->create([
        'tenant_id' => $tenantId,
        'employee_id' => $employee->id,
        'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenantId])->id,
        'start_date' => '2026-11-02',
        'end_date' => '2026-11-04',
    ]);
}

function storedMessage(object $user): array
{
    return $user->notifications()->latest()->firstOrFail()->data;
}

it('writes an Amharic reader\'s notice in Amharic, with a link to open', function () {
    $tenant = createTenant();
    $reader = createUser(['role' => UserRole::EMPLOYEE, 'locale' => 'am'], $tenant);

    $reader->notify(new LeaveApprovedNotification(languageLeave($tenant->id)));

    expect(storedMessage($reader))->toMatchArray([
        'message' => 'የፈቃድ ጥያቄዎ ጸድቋል።',
        'url' => '/leave',
    ]);
});

// users.locale is NOT NULL (default `en`), so the organisation's language is
// reached only when the stored value is one the product does not ship.
it('falls back to the organisation\'s language when the reader\'s is not shipped', function () {
    $tenant = createTenant(['default_locale' => 'am']);
    $reader = createUser(['role' => UserRole::EMPLOYEE, 'locale' => 'om'], $tenant);

    expect($reader->preferredLocale())->toBe('am');

    $reader->notify(new LeaveApprovedNotification(languageLeave($tenant->id)));

    expect(storedMessage($reader)['message'])->toBe('የፈቃድ ጥያቄዎ ጸድቋል።');
});

it('writes an English reader\'s notice in English', function () {
    $tenant = createTenant(['default_locale' => 'am']);
    $reader = createUser(['role' => UserRole::EMPLOYEE, 'locale' => 'en'], $tenant);

    $reader->notify(new LeaveApprovedNotification(languageLeave($tenant->id)));

    expect(storedMessage($reader)['message'])->toBe('Your leave request was approved.');
});

it('ignores a stored language the product does not ship', function () {
    $tenant = createTenant(['default_locale' => 'en']);
    $reader = createUser(['role' => UserRole::EMPLOYEE, 'locale' => 'om'], $tenant);

    expect($reader->preferredLocale())->toBe('en');
});

// The language switch in the header changes only the browser's locale, so a
// reader whose saved locale is `en` used to see English notices stored in
// English in an Amharic dashboard. The list translates them again in the
// language the request asks for (production QA 2026-10-28, F-02).
it('lists a notice in the language the reader is using now', function () {
    $tenant = createTenant();
    $leave = languageLeave($tenant->id);
    $name = (string) $leave->employee?->name;
    $reader = createUser(['role' => UserRole::HR_ADMIN, 'locale' => 'en'], $tenant);

    $reader->notify(new LeaveRequestedNotification($leave));

    $stored = storedMessage($reader);
    expect($stored['message'])->toBe(trans('notification.inapp.leave_requested', ['name' => $name], 'en'))
        ->and($stored['message_key'])->toBe('notification.inapp.leave_requested')
        ->and($stored['message_params'])->toBe(['name' => $name]);

    $amharic = trans('notification.inapp.leave_requested', ['name' => $name], 'am');
    expect($amharic)->not->toBe($stored['message']);

    test()->actingAs($reader)
        ->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/notifications", ['Accept-Language' => 'am'])
        ->assertOk()
        ->assertJsonPath('data.0.data.message', $amharic);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/notifications", ['Accept-Language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.0.data.message', $stored['message']);
});

it('keeps the stored text of a notice saved without its key', function (array $data) {
    $tenant = createTenant();
    $reader = createUser(['role' => UserRole::EMPLOYEE], $tenant);
    $reader->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => LeaveApprovedNotification::class,
        'data' => $data,
    ]);

    test()->actingAs($reader)
        ->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/notifications", ['Accept-Language' => 'am'])
        ->assertOk()
        ->assertJsonPath('data.0.data.message', 'Stored before the key was.');
})->with([
    'no key' => [['message' => 'Stored before the key was.']],
    'unknown key' => [['message' => 'Stored before the key was.', 'message_key' => 'notification.no_such_line', 'message_params' => []]],
    'key that is not a string' => [['message' => 'Stored before the key was.', 'message_key' => ['x'], 'message_params' => []]],
]);
