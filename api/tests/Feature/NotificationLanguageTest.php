<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Notifications\LeaveApprovedNotification;

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
