<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Services\Letters\EmploymentLetters;
use App\Support\ClockPreference;
use App\Support\Locales;
use App\Support\NotificationTemplates;
use App\Support\ReaderDates;

/*
 * Afaan Oromoo, shipped 2026-11-02. LangParityTest holds lang/om/ to the same
 * rules as lang/am/; this pins what a parity check cannot see — that the
 * server actually answers in Oromo, and writes dates, times, e-mails and
 * letters the way an Oromo reader writes them rather than in English.
 */

it('answers a request in Oromo when asked', function () {
    $this->withHeader('Accept-Language', 'om')->getJson('/api/v1/ping')->assertOk();

    expect(app()->getLocale())->toBe('om');
});

it('still answers a language it does not ship in English', function () {
    $this->withHeader('Accept-Language', 'ti')->getJson('/api/v1/ping')->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('ships Oromo, and treats the stub languages as English', function () {
    expect(Locales::SHIPPED)->toContain('om')
        ->and(Locales::orDefault('om'))->toBe('om')
        ->and(Locales::orDefault('ti'))->toBe('en')
        ->and(Locales::orDefault(null))->toBe('en');
});

it('writes to a user in Oromo when Oromo is their language', function () {
    $tenant = createTenant(['default_locale' => 'am']);
    $user = createUser(['locale' => 'om'], $tenant);

    expect($user->preferredLocale())->toBe('om');
});

it('falls back to the organisation\'s Oromo for a user who never chose', function () {
    $tenant = createTenant(['default_locale' => 'om']);
    $user = createUser(['locale' => 'en'], $tenant);

    expect($user->preferredLocale())->toBe('om');
});

test('dates and times are written in Oromo', function () {
    $reader = new ReaderDates('ethiopian', 'ethiopian', 'om', 'Africa/Addis_Ababa');

    expect($reader->date('2026-11-01'))->toBe('Onkololeessa 22, 2019')
        ->and($reader->time('2026-10-20T04:30:00Z'))->toBe('1:30 ganama')
        ->and($reader->time('2026-10-20T09:00:00Z'))->toBe('6:00 waaree booda')
        ->and($reader->time('2026-10-20T15:00:00Z'))->toBe('12:00 galgala')
        ->and($reader->time('2026-10-20T21:00:00Z'))->toBe('6:00 halkan')
        ->and($reader->period('2026-10-11', '2026-11-09'))->toBe('Onkololeessa 2019');
});

test('a Gregorian date is written with Oromo month names', function () {
    $reader = new ReaderDates('gregorian', '24h', 'om', 'Africa/Addis_Ababa');

    expect($reader->date('2026-11-01'))->not->toBe('01 Nov 2026')
        ->and($reader->period('2026-10-01', '2026-10-31'))->toContain('2026')
        ->and($reader->period('2026-10-01', '2026-10-31'))->not->toContain('October');
});

test('an Oromo reader on the Ethiopian calendar reads the Ethiopian clock by default', function () {
    expect(ClockPreference::resolve('auto', 'ethiopian', 'om'))->toBe('ethiopian')
        ->and(ClockPreference::resolve('auto', 'gregorian', 'om'))->toBe('24h')
        ->and(ClockPreference::resolve('24h', 'ethiopian', 'om'))->toBe('24h');
});

it('sends the built-in Oromo e-mail to an Oromo reader', function () {
    app()->setLocale('om');

    $rendered = NotificationTemplates::render('leave_approved', null, [
        'leave_type' => 'boqonnaa waggaa', 'start_date' => 'Onkololeessa 22', 'end_date' => 'Onkololeessa 24',
    ]);

    expect($rendered['subject'])->toBe(NotificationTemplates::DEFAULTS['leave_approved']['subject_om'])
        ->and($rendered['body'])->toContain('boqonnaa waggaa')->toContain('raggaasifameera');
});

it('has an Oromo subject and body for every e-mail, with the English variables', function (string $type) {
    $placeholders = static function (string $text): array {
        preg_match_all('/\{([a-z_]+)\}/', $text, $m);
        sort($m[1]);

        return $m[1];
    };
    $default = NotificationTemplates::DEFAULTS[$type];

    expect($default)->toHaveKeys(['subject_om', 'body_om'])
        ->and($placeholders($default['subject_om']))->toBe($placeholders($default['subject_en']))
        ->and($placeholders($default['body_om']))->toBe($placeholders($default['body_en']));
})->with(array_keys(NotificationTemplates::DEFAULTS));

it('writes an employment letter in Oromo when asked', function () {
    $tenant = createTenant(['name' => 'Syntax Technology']);
    $department = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Engineering']);
    $position = Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Software Engineer']);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'name' => 'Caalaa Guddataa', 'employee_code' => 'E-008',
        'hire_date' => '2022-03-01', 'department_id' => $department->id, 'position_id' => $position->id,
    ]);
    $me = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    $this->actingAs($me)
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile/letters", [
            'purpose' => 'Liqii baankii', 'language' => 'om',
        ])
        ->assertCreated()
        ->assertJsonPath('language', 'om');

    $letters = app(EmploymentLetters::class);
    $letter = $letters->issue($letters->request($employee, ['purpose' => 'Viizaa', 'language' => 'om']), $hr);
    $data = $letters->data($letter);

    expect($letter->language)->toBe('om')
        ->and($data['locale'])->toBe('om')
        ->and(view('letters.employment', $data)->render())->toContain(__('letters.subject', [], 'om'))
        ->and(__('letters.subject', [], 'om'))->not->toBe(__('letters.subject', [], 'en'))
        ->and(strlen($letters->pdf($letter)))->toBeGreaterThan(1000);
});

it('accepts Oromo as an organisation\'s and a user\'s language', function () {
    $tenant = createTenant();
    $admin = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    $this->actingAs($admin)
        ->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/profile/preferences", ['locale' => 'om'])
        ->assertOk();

    expect($admin->fresh()->locale)->toBe('om');
});
