<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Support\NotificationMessage;
use App\Support\ReaderDates;
use Illuminate\Support\Str;

/*
 * A notice is written for whoever reads it. Its dates are stored as values
 * and rendered in the reader's calendar, clock and language: when it is sent
 * (the push and the stored text) and again when it is read.
 */

test('a stored date is written in the recipient\'s calendar and language', function () {
    $amharic = new ReaderDates('ethiopian', 'ethiopian', 'am', 'Africa/Addis_Ababa');

    app()->setLocale('am');
    $data = NotificationMessage::make('notification.inapp.overtime_approved', ['date' => '2026-11-01'], dates: ['date' => 'date'], reader: $amharic);

    expect($data['message'])->toContain('ጥቅምት 22, 2019')
        ->and($data['message_params']['date'])->toBe('2026-11-01')
        ->and($data['message_dates'])->toBe(['date' => 'date']);
});

test('the same notice reads in another reader\'s calendar when they open it', function () {
    app()->setLocale('am');
    $data = NotificationMessage::make('notification.inapp.overtime_approved', ['date' => '2026-11-01'], dates: ['date' => 'date'],
        reader: new ReaderDates('ethiopian', 'ethiopian', 'am', 'Africa/Addis_Ababa'));

    app()->setLocale('en');
    $read = NotificationMessage::localise($data, new ReaderDates('gregorian', '24h', 'en', 'Africa/Addis_Ababa'));

    expect($read['message'])->toContain('01 Nov 2026');
});

test('a pay period is named in the reader\'s calendar', function () {
    app()->setLocale('am');
    $data = NotificationMessage::make('notification.inapp.payslip_available', ['period' => '2026-10-11/2026-11-09'], dates: ['period' => 'period'],
        reader: new ReaderDates('ethiopian', 'ethiopian', 'am', 'Africa/Addis_Ababa'));

    expect($data['message'])->toContain('ጥቅምት 2019');
});

test('an old notice with a written date and no date kinds is left as it was', function () {
    $data = ['message' => 'old', 'message_key' => 'notification.inapp.overtime_approved', 'message_params' => ['date' => '2026-11-01']];

    app()->setLocale('en');
    expect(NotificationMessage::localise($data, new ReaderDates('ethiopian', '24h', 'en', 'Africa/Addis_Ababa'))['message'])
        ->toContain('2026-11-01');
});

test('the notification list writes dates for the person reading it', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::EMPLOYEE, 'preferences' => ['calendar' => 'ethiopian']], $tenant);

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\OvertimeDecidedNotification',
        'data' => NotificationMessage::make('notification.inapp.overtime_approved', ['date' => '2026-11-01'], dates: ['date' => 'date'],
            reader: new ReaderDates('gregorian', '24h', 'en', 'Africa/Addis_Ababa')),
    ]);

    $message = test()->withHeader('Accept-Language', 'en')
        ->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/notifications")
        ->assertOk()
        ->json('data.0.data.message');

    expect($message)->toContain('Tikimt 22, 2019');
});

test('someone who never chose a language gets their organisation\'s', function (array $user, string $organisation, string $expected) {
    $tenant = createTenant(['default_locale' => $organisation]);
    $person = User::factory()->create(['tenant_id' => $tenant->id, ...$user]);

    expect($person->preferredLocale())->toBe($expected);
})->with([
    'never chose, default en' => [['locale' => 'en', 'preferences' => null], 'am', 'am'],
    'chose English' => [['locale' => 'en', 'preferences' => ['locale_chosen' => true]], 'am', 'en'],
    'Amharic saved for them' => [['locale' => 'am', 'preferences' => null], 'en', 'am'],
]);
