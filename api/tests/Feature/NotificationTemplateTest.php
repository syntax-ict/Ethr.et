<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LeaveApprovedNotification;
use App\Notifications\LeaveRejectedNotification;
use App\Notifications\LeaveRequestedNotification;
use App\Notifications\MissingPunchNotification;
use App\Notifications\PayslipAvailableNotification;
use App\Notifications\TrialExpiringNotification;
use App\Services\CurrentTenant;
use App\Support\NotificationTemplates;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/*
 * Audit N7: Settings → Notification Templates saved a tenant's subject and body
 * into `tenants.settings.notification_templates`, and every notification built
 * its mail from hard-coded lines without reading them. Editing a template
 * changed no e-mail. These pin that a saved template is what is sent, per
 * tenant, per locale, with the tenant-authored text and the values dropped
 * into it treated as plain text.
 */

function templateSave(Tenant $tenant, string $type, array $fields): void
{
    $settings = $tenant->fresh()->settings ?? [];
    $settings['notification_templates'][$type] = $fields;
    $tenant->forceFill(['settings' => $settings])->save();
}

/** @return array{leave: LeaveRequest, user: User, employee: Employee} */
function templateLeave(Tenant $tenant, array $employee = [], array $leave = []): array
{
    app(CurrentTenant::class)->set($tenant);

    $person = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Abebe Kebede', ...$employee]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $person->id], $tenant);
    $type = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Annual Leave']);

    $request = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $person->id,
        'leave_type_id' => $type->id,
        'start_date' => '2026-11-02',
        'end_date' => '2026-11-04',
        'days' => 3,
        ...$leave,
    ]);

    return ['leave' => $request, 'user' => $user, 'employee' => $person];
}

function templateMailText(MailMessage $mail): string
{
    return implode("\n", array_map('strval', $mail->introLines));
}

// ── A saved template is what is sent ───────────────────────────────────────

it('sends the subject and body a tenant admin saved in settings', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->putJson('/api/v1/settings/notification-templates/leave_approved', [
        'subject_en' => 'Enjoy your {leave_type}, {employee_name}',
        'body_en' => 'Approved: {start_date} to {end_date} ({days} days) at {organization_name}.',
    ])->assertOk();

    ['leave' => $leave, 'user' => $user] = templateLeave($tenant);
    $mail = (new LeaveApprovedNotification($leave))->toMail($user);

    expect($mail->subject)->toBe('Enjoy your Annual Leave, Abebe Kebede')
        ->and(templateMailText($mail))->toBe("Approved: 2026-11-02 to 2026-11-04 (3 days) at {$tenant->name}.");
});

it('sends the built-in text the settings page shows when nothing is customised', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $listed = collect($this->getJson('/api/v1/settings/notification-templates')->assertOk()->json('templates'))
        ->firstWhere('type', 'leave_approved');
    expect($listed['is_customized'])->toBeFalse();

    ['leave' => $leave, 'user' => $user] = templateLeave($tenant);
    $mail = (new LeaveApprovedNotification($leave))->toMail($user);

    expect($mail->subject)->toBe($listed['subject_en'])
        ->and(templateMailText($mail))->toBe('Your Annual Leave request from 2026-11-02 to 2026-11-04 has been approved.');
});

it('falls back field by field: a custom subject keeps the built-in body', function () {
    $tenant = createTenant();
    templateSave($tenant, 'leave_approved', ['subject_en' => 'Approved!']);
    ['leave' => $leave, 'user' => $user] = templateLeave($tenant);

    $mail = (new LeaveApprovedNotification($leave))->toMail($user);

    expect($mail->subject)->toBe('Approved!')
        ->and(templateMailText($mail))->toBe('Your Annual Leave request from 2026-11-02 to 2026-11-04 has been approved.');
});

it('uses the Amharic fields when the mail is rendered in Amharic', function () {
    $tenant = createTenant();
    templateSave($tenant, 'leave_approved', ['subject_en' => 'English subject', 'subject_am' => 'ፈቃድ ፀድቋል — {employee_name}']);
    ['leave' => $leave, 'user' => $user] = templateLeave($tenant);

    app()->setLocale('am');
    $mail = (new LeaveApprovedNotification($leave))->toMail($user);
    app()->setLocale('en');

    expect($mail->subject)->toBe('ፈቃድ ፀድቋል — Abebe Kebede')
        ->and(templateMailText($mail))->toBe(str_replace(
            ['{start_date}', '{end_date}', '{leave_type}'],
            ['2026-11-02', '2026-11-04', 'Annual Leave'],
            NotificationTemplates::DEFAULTS['leave_approved']['body_am'],
        ));
});

// ── Tenant isolation ───────────────────────────────────────────────────────

it('applies a tenant\'s template to its own mail and never to another tenant\'s', function () {
    $mine = createTenant();
    $theirs = createTenant();
    templateSave($theirs, 'leave_approved', ['subject_en' => 'Their wording', 'body_en' => 'Their body']);

    ['leave' => $myLeave, 'user' => $me] = templateLeave($mine);
    ['leave' => $theirLeave, 'user' => $them] = templateLeave($theirs);

    // Even with the other tenant resolved, my mail is rendered from my tenant.
    app(CurrentTenant::class)->set($theirs);
    $myMail = (new LeaveApprovedNotification($myLeave))->toMail($me);
    $theirMail = (new LeaveApprovedNotification($theirLeave))->toMail($them);

    expect($myMail->subject)->toBe(NotificationTemplates::DEFAULTS['leave_approved']['subject_en'])
        ->and(templateMailText($myMail))->not->toContain('Their body')
        ->and($theirMail->subject)->toBe('Their wording')
        ->and(templateMailText($theirMail))->toBe('Their body');
});

it('finds the template with no tenant resolved, as in a queue worker', function () {
    $tenant = createTenant();
    templateSave($tenant, 'missing_punch', [
        'subject_en' => 'Punch missing: {employee_name}',
        'body_en' => 'No {punch_type} on {date}.',
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sara Tesfaye']);
    $user = createUser(['employee_id' => $employee->id], $tenant);

    app(CurrentTenant::class)->forget();
    $mail = (new MissingPunchNotification($employee, '2026-10-05', 'missing_check_out'))->toMail($user);

    expect($mail->subject)->toBe('Punch missing: Sara Tesfaye')
        ->and(templateMailText($mail))->toBe('No check-out on 2026-10-05.');
});

// ── Escaping ───────────────────────────────────────────────────────────────

it('renders the template and substituted values as text, never as HTML or Markdown', function () {
    $tenant = createTenant();
    templateSave($tenant, 'leave_requested', [
        'subject_en' => "Request\r\nBcc: victim@example.com {employee_name}",
        'body_en' => '<b>Heads up</b> {employee_name} wants {leave_type}',
    ]);
    ['leave' => $leave] = templateLeave(
        $tenant,
        ['name' => '<script>alert(1)</script>[click me](https://evil.example)'],
    );
    $approver = createUser(['role' => UserRole::SUPERVISOR], $tenant);

    $mail = (new LeaveRequestedNotification($leave))->toMail($approver);
    $html = (string) $mail->render();

    expect($html)->not->toContain('<script>')
        ->and($html)->not->toContain('<b>')
        ->and($html)->not->toContain('https://evil.example"')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->toContain('&lt;b&gt;Heads up&lt;/b&gt;')
        ->and($html)->toContain('[click me](https://evil.example)')
        ->and($mail->subject)->not->toContain("\n")
        ->and($mail->subject)->not->toContain("\r")
        ->and($mail->subject)->toStartWith('Request Bcc: victim@example.com <script>');
});

it('does not substitute a placeholder that arrives inside a value', function () {
    $tenant = createTenant();
    templateSave($tenant, 'leave_approved', ['body_en' => '{employee_name} / {start_date}']);
    ['leave' => $leave, 'user' => $user] = templateLeave($tenant, ['name' => 'Eve {start_date}']);

    expect(templateMailText((new LeaveApprovedNotification($leave))->toMail($user)))
        ->toBe('Eve {start_date} / 2026-11-02');
});

// ── Placeholders ───────────────────────────────────────────────────────────

it('leaves an unknown placeholder exactly as written and accepts the double-brace form', function () {
    $tenant = createTenant();
    // Written straight to settings: the endpoint refuses unknown placeholders,
    // so only a template stored before that check can carry one.
    templateSave($tenant, 'leave_approved', ['body_en' => 'Hi {{ employee_name }}, see {manager_name} and {}']);
    ['leave' => $leave, 'user' => $user] = templateLeave($tenant);

    expect(templateMailText((new LeaveApprovedNotification($leave))->toMail($user)))
        ->toBe('Hi Abebe Kebede, see {manager_name} and {}');
});

it('refuses to save a placeholder the template does not provide', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->putJson('/api/v1/settings/notification-templates/payslip_available', [
        'subject_en' => 'Payslip for {period}',
        'body_en' => 'Dear {employe_name}, your pay is {net_amount}.',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['body_en'])
        ->assertJsonMissingValidationErrors(['subject_en']);

    expect($tenant->fresh()->settings['notification_templates']['payslip_available'] ?? null)->toBeNull();
});

it('does not store a field saved unchanged from the built-in text', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $defaults = NotificationTemplates::DEFAULTS['leave_rejected'];

    $this->putJson('/api/v1/settings/notification-templates/leave_rejected', [
        ...$defaults,
        'subject_en' => 'Not this time',
    ])->assertOk();

    expect($tenant->fresh()->settings['notification_templates']['leave_rejected'])
        ->toBe(['subject_en' => 'Not this time']);
});

// ── Never throws ───────────────────────────────────────────────────────────

it('falls back to the built-in text when the stored template is malformed', function (mixed $stored) {
    $tenant = createTenant();
    $settings = $tenant->settings ?? [];
    $settings['notification_templates']['leave_approved'] = $stored;
    $tenant->forceFill(['settings' => $settings])->save();
    ['leave' => $leave, 'user' => $user] = templateLeave($tenant);

    $mail = (new LeaveApprovedNotification($leave))->toMail($user);

    expect($mail->subject)->toBe(NotificationTemplates::DEFAULTS['leave_approved']['subject_en']);
})->with([
    'a string' => ['not a template'],
    'nested arrays' => [['subject_en' => ['x'], 'body_en' => ['y']]],
    'blank fields' => [['subject_en' => '   ', 'body_en' => '']],
]);

it('falls back to the built-in text when the tenant cannot be found', function () {
    expect(NotificationTemplates::render('trial_expiring', 999_999, ['days_remaining' => 7, 'trial_ends_at' => '2026-11-01']))
        ->toBe([
            'subject' => 'Your ETHR trial is expiring soon',
            'body' => "Your ETHR trial will expire in 7 day(s) on 2026-11-01.\nUpgrade now to ensure uninterrupted access for your team.",
        ]);
});

// ── Every templated notification, every variable ──────────────────────────

it('renders each of the six e-mails from its template, with every listed variable supplied', function (string $type) {
    $tenant = createTenant();
    $variables = NotificationTemplates::VARIABLES[$type];
    templateSave($tenant, $type, [
        'subject_en' => "Custom {$type}",
        'body_en' => implode(' ', array_map(fn (string $v): string => "{$v}={{$v}}", $variables)),
    ]);

    ['leave' => $leave, 'user' => $user, 'employee' => $employee] = templateLeave(
        $tenant,
        [],
        ['rejected_reason' => 'Busy season'],
    );
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
    $entry = PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'net_cents' => 1234567,
    ]);

    /** @var Notification $notification */
    $notification = match ($type) {
        'leave_requested' => new LeaveRequestedNotification($leave),
        'leave_approved' => new LeaveApprovedNotification($leave),
        'leave_rejected' => new LeaveRejectedNotification($leave),
        'payslip_available' => new PayslipAvailableNotification($entry),
        'missing_punch' => new MissingPunchNotification($employee, '2026-10-05', 'missing_check_in'),
        'trial_expiring' => new TrialExpiringNotification(7, '2026-11-01'),
    };

    $mail = $notification->toMail($user);
    $body = templateMailText($mail);

    expect($mail->subject)->toBe("Custom {$type}");
    foreach ($variables as $variable) {
        expect($body)->toMatch('/'.$variable.'=\S/');
    }
    expect($body)->not->toContain('{');

    if ($type === 'payslip_available') {
        expect($body)->toContain('net_amount=12,345.67 ETB');
    }
})->with(NotificationTemplates::types());

it('lists every variable each e-mail supplies on the settings page', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $listed = collect($this->getJson('/api/v1/settings/notification-templates')->assertOk()->json('templates'))
        ->mapWithKeys(fn (array $t) => [$t['type'] => $t['variables']])
        ->all();

    expect($listed)->toBe(NotificationTemplates::VARIABLES)
        ->and($listed['payslip_available'])->toContain('employee_name', 'net_amount')
        ->and($listed['leave_rejected'])->toContain('employee_name', 'reason');
});
