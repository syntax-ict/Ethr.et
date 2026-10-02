<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\ScanMissingPunchesJob;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\NotificationPreference;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\MissingPunchNotification;
use App\Services\Attendance\AttendanceIntelligence;
use App\Services\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

/*
 * MissingPunchNotification is sent from exactly one place,
 * ScanMissingPunchesJob — a queued, per-tenant job with no HTTP request behind
 * it. So "who receives it" is a question about tenant context in a worker, and
 * "what does the link say" is a question about a host no request supplied.
 */

/**
 * An employee (with a login) who checked in on 2026-10-05 and never checked
 * out, and their supervisor (with a login).
 *
 * @return array{employee: Employee, employeeUser: User, supervisor: Employee, supervisorUser: User}
 */
function missingPunchOpenDay(Tenant $tenant, string $name = 'Abebe Kebede'): array
{
    app(CurrentTenant::class)->set($tenant);

    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $supervisorUser = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => $name,
        'supervisor_id' => $supervisor->id,
    ]);
    $employeeUser = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    AttendanceRecord::factory()->checkInOnly()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => '2026-10-05',
        'check_in' => Carbon::parse('2026-10-05 08:30'),
    ]);

    return compact('employee', 'employeeUser', 'supervisor', 'supervisorUser');
}

function missingPunchRunScan(Tenant $tenant, string $date = '2026-10-05'): void
{
    (new ScanMissingPunchesJob($tenant->id, $date))
        ->handle(app(AttendanceIntelligence::class), app(CurrentTenant::class));
}

// ── Payload ─────────────────────────────────────────────────────────────────

it('describes a missing check-out by the employee\'s public id, name and the day', function () {
    $tenant = createTenant();
    ['employee' => $employee, 'supervisorUser' => $to] = missingPunchOpenDay($tenant);

    expect((new MissingPunchNotification($employee, '2026-10-05', 'missing_check_out'))->toArray($to))
        ->toBe([
            'employee_id' => $employee->public_id,
            'employee_name' => 'Abebe Kebede',
            'date' => '2026-10-05',
            'type' => 'missing_check_out',
            'message' => 'Abebe Kebede did not check out on 2026-10-05',
        ]);
});

it('words a missing check-in differently from a missing check-out', function () {
    $tenant = createTenant();
    ['employee' => $employee, 'supervisorUser' => $to] = missingPunchOpenDay($tenant);

    $notification = new MissingPunchNotification($employee, '2026-10-05', 'missing_check_in');

    expect($notification->toArray($to)['message'])->toBe('Abebe Kebede did not check in on 2026-10-05')
        ->and(implode(' ', $notification->toMail($to)->introLines))->toContain('missing a check-in for 2026-10-05');
});

// ── Channels and preferences ────────────────────────────────────────────────

it('goes in-app and by email by default', function () {
    $tenant = createTenant();
    ['employee' => $employee, 'employeeUser' => $user] = missingPunchOpenDay($tenant);

    expect((new MissingPunchNotification($employee, '2026-10-05', 'missing_check_out'))->via($user))
        ->toBe(['database', 'mail']);
});

it('stops emailing a user who switched attendance-anomaly email off, and keeps the in-app record', function () {
    $tenant = createTenant();
    ['employee' => $employee, 'employeeUser' => $user] = missingPunchOpenDay($tenant);

    NotificationPreference::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'notification_type' => 'attendance_anomaly',
        'channel' => 'email',
        'enabled' => false,
    ]);

    expect((new MissingPunchNotification($employee, '2026-10-05', 'missing_check_out'))->via($user))
        ->toBe(['database']);
});

it('ignores an email opt-out filed under a different notification type', function () {
    $tenant = createTenant();
    ['employee' => $employee, 'employeeUser' => $user] = missingPunchOpenDay($tenant);

    NotificationPreference::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'notification_type' => 'attendance_correction',
        'channel' => 'email',
        'enabled' => false,
    ]);

    expect((new MissingPunchNotification($employee, '2026-10-05', 'missing_check_out'))->via($user))
        ->toContain('mail');
});

it('reads the recipient\'s own preferences, not those of another user with the same type', function () {
    $tenant = createTenant();
    ['employee' => $employee, 'employeeUser' => $user, 'supervisorUser' => $supervisorUser] = missingPunchOpenDay($tenant);

    NotificationPreference::create([
        'tenant_id' => $tenant->id,
        'user_id' => $supervisorUser->id,
        'notification_type' => 'attendance_anomaly',
        'channel' => 'email',
        'enabled' => false,
    ]);

    $notification = new MissingPunchNotification($employee, '2026-10-05', 'missing_check_out');

    expect($notification->via($user))->toContain('mail')
        ->and($notification->via($supervisorUser))->not->toContain('mail');
});

it('honours the opt-out on the queue-worker path, where no tenant is resolved', function () {
    $tenant = createTenant();
    ['employee' => $employee, 'employeeUser' => $user] = missingPunchOpenDay($tenant);

    NotificationPreference::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'notification_type' => 'attendance_anomaly',
        'channel' => 'email',
        'enabled' => false,
    ]);

    app(CurrentTenant::class)->forget();

    expect((new MissingPunchNotification($employee, '2026-10-05', 'missing_check_out'))->via($user))
        ->toBe(['database']);
});

// ── The email ───────────────────────────────────────────────────────────────

it('links the email to the frontend, not to the API host', function () {
    // toMail() built its button with url('/attendance'): the API's own host,
    // from APP_URL or whatever request is current — `http://localhost` in a
    // queue worker with no request. The reset and activation emails were
    // already built from config('app.frontend_url'); FrontendUrl::to() is now
    // the one place every notification link is built
    // (Auth/MailLinkFrontendUrlTest pins that no notification uses url()).
    config([
        'app.url' => 'https://api.ethr.test',
        'app.frontend_url' => 'https://app.ethr.test',
    ]);
    $tenant = createTenant();
    ['employee' => $employee, 'employeeUser' => $user] = missingPunchOpenDay($tenant);

    $mail = (new MissingPunchNotification($employee, '2026-10-05', 'missing_check_out'))->toMail($user);

    expect($mail->actionUrl)->toBe('https://app.ethr.test/attendance');
});

it('gives the email a real subject line in every locale', function () {
    // toMail() asked for notification.missing_punch_subject, which existed in
    // neither lang/en/notification.php nor lang/am/notification.php, so every
    // one of these emails went out with the raw key as its subject. The subject
    // now comes from App\Support\NotificationTemplates (audit N7).
    $tenant = createTenant();
    ['employee' => $employee, 'employeeUser' => $user] = missingPunchOpenDay($tenant);

    foreach (['en', 'am'] as $locale) {
        app()->setLocale($locale);

        foreach (['missing_check_out', 'missing_check_in'] as $type) {
            $mail = (new MissingPunchNotification($employee, '2026-10-05', $type))->toMail($user);

            expect($mail->subject)->not->toStartWith('notification.')
                ->and($mail->subject)->toContain('2026-10-05')
                ->and(implode(' ', $mail->introLines))->toContain('Abebe Kebede')
                ->and(implode(' ', $mail->introLines))->toContain('2026-10-05');
        }
    }

    app()->setLocale('en');
    expect((new MissingPunchNotification($employee, '2026-10-05', 'missing_check_in'))->toMail($user)->subject)
        ->toBe('Missing check-in — 2026-10-05');
});

it('uses only translation keys that exist in both locales, in every notification', function () {
    // Was limited to notification.* keys; notifications also read dashboard.*,
    // report.*, user.* and payroll.* lines, and a key missing from both
    // locales is invisible to an en/am parity check. Dotted keys are walked
    // into nested arrays, as __() does.
    $missing = [];

    foreach (File::allFiles(app_path('Notifications')) as $file) {
        preg_match_all("/__\\('([a-z_]+)\\.([a-z_.]+)'/", $file->getContents(), $m, PREG_SET_ORDER);

        foreach ($m as [, $group, $key]) {
            foreach (['en', 'am'] as $locale) {
                $path = lang_path("{$locale}/{$group}.php");
                $lines = is_file($path) ? require $path : [];

                if (data_get($lines, $key) === null) {
                    $missing[] = "{$file->getFilename()}: {$group}.{$key} ({$locale})";
                }
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});

it('passes a replacement for every placeholder a notification line carries', function () {
    // LeaveRequested and PayslipAvailable called __() with no replacements on
    // lines carrying :name and :period, so the subjects read "New Leave
    // Request — :name" and "Your Payslip Is Ready — :period". A call with no
    // second argument must resolve to a line with no placeholder.
    $unreplaced = [];

    foreach (File::allFiles(app_path('Notifications')) as $file) {
        preg_match_all("/__\\('([a-z_]+)\\.([a-z_.]+)'\\s*\\)/", $file->getContents(), $m, PREG_SET_ORDER);

        foreach ($m as [, $group, $key]) {
            foreach (['en', 'am'] as $locale) {
                $path = lang_path("{$locale}/{$group}.php");
                $line = data_get(is_file($path) ? require $path : [], $key);

                if (is_string($line) && preg_match('/:[a-z_]+/', $line) === 1) {
                    $unreplaced[] = "{$file->getFilename()}: {$group}.{$key} ({$locale})";
                }
            }
        }
    }

    expect($unreplaced)->toBe([]);
});

// ── The scan job: recipients and tenant context ─────────────────────────────

it('tells the employee and their supervisor once each', function () {
    Notification::fake();
    $tenant = createTenant();
    $day = missingPunchOpenDay($tenant);

    missingPunchRunScan($tenant);

    Notification::assertSentToTimes($day['employeeUser'], MissingPunchNotification::class, 1);
    Notification::assertSentToTimes($day['supervisorUser'], MissingPunchNotification::class, 1);
    Notification::assertSentTo(
        $day['supervisorUser'],
        MissingPunchNotification::class,
        fn ($n) => $n->toArray($day['supervisorUser'])['employee_id'] === $day['employee']->public_id
    );
});

it('never reaches another tenant\'s staff with an open day on the same date', function () {
    Notification::fake();
    $tenant = createTenant();
    $mine = missingPunchOpenDay($tenant);
    $other = createTenant();
    $theirs = missingPunchOpenDay($other, 'Other Tenant Person');

    missingPunchRunScan($tenant);

    Notification::assertSentTo($mine['employeeUser'], MissingPunchNotification::class);
    Notification::assertNothingSentTo($theirs['employeeUser']);
    Notification::assertNothingSentTo($theirs['supervisorUser']);
});

it('sets its own tenant rather than trusting one a previous job left behind', function () {
    // CurrentTenant is a scoped binding, flushed between jobs by the worker,
    // but a job that needs tenant context must set it itself (root CLAUDE.md
    // §4). Simulate the worst case: the previous job left the OTHER tenant
    // resolved.
    Notification::fake();
    $tenant = createTenant();
    $mine = missingPunchOpenDay($tenant);
    $other = createTenant();
    $theirs = missingPunchOpenDay($other, 'Other Tenant Person');

    app(CurrentTenant::class)->set($other);

    missingPunchRunScan($tenant);

    Notification::assertSentTo($mine['employeeUser'], MissingPunchNotification::class);
    Notification::assertNothingSentTo($theirs['employeeUser']);
    expect(app(CurrentTenant::class)->id())->toBe($tenant->id);
});

it('does nothing for a tenant that no longer exists', function () {
    Notification::fake();

    (new ScanMissingPunchesJob(999_999, '2026-10-05'))
        ->handle(app(AttendanceIntelligence::class), app(CurrentTenant::class));

    Notification::assertNothingSent();
});

it('sends nothing for a day where everyone checked out', function () {
    Notification::fake();
    $tenant = createTenant();
    $day = missingPunchOpenDay($tenant);
    AttendanceRecord::where('employee_id', $day['employee']->id)
        ->update(['check_out' => Carbon::parse('2026-10-05 17:00')]);

    missingPunchRunScan($tenant);

    Notification::assertNothingSent();
});

it('does not notify a "supervisor" that belongs to another tenant', function () {
    // ScanMissingPunchesJob.php:83 looks the supervisor up with the tenant
    // scope removed and only `id = supervisor_id` — no tenant_id predicate —
    // so a legacy cross-tenant supervisor_id (the API refuses new ones since
    // §11f; older rows were never re-validated) DOES load the foreign
    // Employee. What stops the notification today is one hop later:
    // `$supervisor->user` goes through User's BelongsToTenant scope, which the
    // job has set to its own tenant, and finds nothing. This pins that outcome
    // so the hop cannot be "simplified" into a second bypass.
    Notification::fake();
    $tenant = createTenant();
    $day = missingPunchOpenDay($tenant);

    $other = createTenant();
    $foreignBoss = Employee::factory()->create(['tenant_id' => $other->id]);
    $foreignBossUser = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $foreignBoss->id], $other);

    app(CurrentTenant::class)->set($tenant);
    $day['employee']->forceFill(['supervisor_id' => $foreignBoss->id])->saveQuietly();

    missingPunchRunScan($tenant);

    Notification::assertSentTo($day['employeeUser'], MissingPunchNotification::class);
    Notification::assertNothingSentTo($foreignBossUser);
});
