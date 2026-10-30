<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceDevice;
use App\Models\Concern;
use App\Models\Employee;
use App\Models\EmploymentLetterRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\Recognition;
use App\Models\TenantExport;
use App\Models\User;
use App\Notifications\ApprovalEscalatedNotification;
use App\Notifications\AttendanceDeviceDecidedNotification;
use App\Notifications\AttendancePhotosDueForDeletionNotification;
use App\Notifications\ConcernRaisedNotification;
use App\Notifications\ConcernUpdatedNotification;
use App\Notifications\ExpiryReminderNotification;
use App\Notifications\LeaveApprovedNotification;
use App\Notifications\LeaveRequestedNotification;
use App\Notifications\LetterDecidedNotification;
use App\Notifications\OvertimeDecidedNotification;
use App\Notifications\RecognitionReceivedNotification;
use App\Notifications\TenantExportFinishedNotification;
use App\Notifications\TrialExpiringNotification;
use App\Support\NotificationMessage;
use Illuminate\Support\Carbon;
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

// The ten that translated their text some other way kept it frozen in the
// sender's language: through a method, through `trans_choice`, by choosing
// between two keys, or with a parameter that is itself a translated label (a
// request type, a voice status). ConcernRaised, left out of F-02 for that last
// reason, is covered the same way (production QA 2026-10-29).
it('reads a notice again in the reader\'s language, labels and plurals included', function (Closure $notification) {
    $reader = (new User)->forceFill(['name' => 'Reader', 'locale' => 'en']);

    app()->setLocale('en');
    $stored = $notification()->toArray($reader);
    expect($stored)->toHaveKey('message_key');

    app()->setLocale('am');
    $amharic = NotificationMessage::localise($stored)['message'];
    $expected = $notification()->toArray($reader)['message'];

    expect($amharic)->toBe($expected)
        ->and($amharic)->not->toBe($stored['message']);
})->with([
    'approval escalated' => [fn () => new ApprovalEscalatedNotification('overtime', 'Abebe', 'Sara', 26)],
    'phone approved' => [fn () => new AttendanceDeviceDecidedNotification(languageDevice(), AttendanceDevice::ACTIVE, null)],
    'phone rejected with a reason' => [fn () => new AttendanceDeviceDecidedNotification(languageDevice(), AttendanceDevice::REJECTED, 'Shared phone')],
    'phone revoked' => [fn () => new AttendanceDeviceDecidedNotification(languageDevice(), AttendanceDevice::REVOKED, null)],
    'photos due for deletion, one' => [fn () => new AttendancePhotosDueForDeletionNotification(1, Carbon::parse('2026-11-02 09:00'))],
    'photos due for deletion, many' => [fn () => new AttendancePhotosDueForDeletionNotification(5, Carbon::parse('2026-11-02 09:00'))],
    'concern raised' => [fn () => new ConcernRaisedNotification(languageConcern())],
    'concern status changed' => [fn () => new ConcernUpdatedNotification(languageConcern(), 'status_changed')],
    'concern answered' => [fn () => new ConcernUpdatedNotification(languageConcern(), 'hr_replied')],
    'letter issued' => [fn () => new LetterDecidedNotification((new EmploymentLetterRequest)->forceFill(['public_id' => 'L1', 'status' => EmploymentLetterRequest::ISSUED]))],
    'letter rejected' => [fn () => new LetterDecidedNotification((new EmploymentLetterRequest)->forceFill(['public_id' => 'L1', 'status' => EmploymentLetterRequest::REJECTED, 'rejection_reason' => 'Missing details']))],
    'recognition' => [fn () => new RecognitionReceivedNotification(languageRecognition())],
    'export ready' => [fn () => new TenantExportFinishedNotification((new TenantExport)->forceFill(['public_id' => 'X1', 'status' => TenantExport::READY]))],
    'export failed' => [fn () => new TenantExportFinishedNotification((new TenantExport)->forceFill(['public_id' => 'X1', 'status' => TenantExport::FAILED]))],
    'expiry reminder, one' => [fn () => new ExpiryReminderNotification(['Passport of Abebe'])],
    'expiry reminder, many' => [fn () => new ExpiryReminderNotification(['Passport of Abebe', 'Contract of Sara'])],
    'trial expiring, one day' => [fn () => new TrialExpiringNotification(1, '2026-11-02')],
    'trial expiring, days' => [fn () => new TrialExpiringNotification(5, '2026-11-02')],
    'overtime approved' => [fn () => new OvertimeDecidedNotification(languageOvertime(), true)],
    'overtime rejected' => [fn () => new OvertimeDecidedNotification(languageOvertime(), false)],
]);

it('falls back to the stored code when a label has no translation', function () {
    app()->setLocale('am');

    $data = NotificationMessage::localise([
        'message' => 'stored',
        'message_key' => 'voice.notify.raised',
        'message_params' => ['category' => 'no_such_category'],
        'message_labels' => ['category' => 'voice.category.no_such_category'],
    ]);

    expect($data['message'])->toBe(trans('voice.notify.raised', ['category' => 'no_such_category'], 'am'));
});

it('keeps the stored text when the label map or count is malformed', function (array $extra) {
    $data = NotificationMessage::localise([
        'message' => 'Stored before the key was.',
        'message_key' => 'voice.notify.raised',
        'message_params' => ['category' => 'safety'],
        ...$extra,
    ]);

    expect($data['message'])->toBe('Stored before the key was.');
})->with([
    'labels not a list' => [['message_labels' => 'voice.category.safety']],
    'count not a number' => [['message_count' => 'three']],
]);

it('stores a plain notice exactly as before', function () {
    expect(array_keys(NotificationMessage::make('letters.issued')))
        ->toBe(['message', 'message_key', 'message_params']);
});

function languageDevice(): AttendanceDevice
{
    return (new AttendanceDevice)->forceFill(['public_id' => 'D1']);
}

function languageConcern(): Concern
{
    return (new Concern)->forceFill(['public_id' => 'C1', 'subject' => 'Broken ladder', 'category' => 'safety', 'status' => 'in_review']);
}

function languageRecognition(): Recognition
{
    $recognition = (new Recognition)->forceFill(['public_id' => 'R1', 'value' => 'teamwork', 'message' => 'Thanks']);

    return $recognition->setRelation('from', (new User)->forceFill(['name' => 'Sara']));
}

function languageOvertime(): OvertimeRequest
{
    return (new OvertimeRequest)->forceFill(['public_id' => 'O1', 'date' => '2026-11-02']);
}
