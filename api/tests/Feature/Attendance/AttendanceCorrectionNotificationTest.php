<?php

declare(strict_types=1);

use App\Enums\CorrectionStatus;
use App\Enums\UserRole;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\NotificationPreference;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AttendanceCorrectionApprovedNotification;
use App\Notifications\AttendanceCorrectionRequestedNotification;
use App\Services\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

/*
 * AttendanceCorrectionRequestedNotification and
 * AttendanceCorrectionApprovedNotification (which also carries the rejection)
 * had no test of their own. The controller tests in IntelligenceCorrectionTest
 * pin status codes; these pin who is told, over which channel, and what the
 * message says — and the authorization holes found while reading the
 * controller that sends them.
 */

/**
 * A supervisor with a login, one direct report with a login, and one of the
 * report's attendance records dated 2026-10-05.
 *
 * @return array{supervisor: Employee, supervisorUser: User, staff: Employee, staffUser: User, record: AttendanceRecord}
 */
function correctionNotifyTeam(Tenant $tenant): array
{
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Selam Bekele']);
    $supervisorUser = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);

    $staff = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Abebe Kebede',
        'supervisor_id' => $supervisor->id,
    ]);
    $staffUser = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $staff->id], $tenant);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $staff->id,
        'date' => '2026-10-05',
        'check_in' => Carbon::parse('2026-10-05 09:10'),
        'check_out' => Carbon::parse('2026-10-05 17:00'),
    ]);

    return compact('supervisor', 'supervisorUser', 'staff', 'staffUser', 'record');
}

function correctionNotifyPending(Tenant $tenant, Employee $employee, AttendanceRecord $record): AttendanceCorrection
{
    return AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $employee->id,
        'proposed_check_in' => Carbon::parse('2026-10-05 08:30'),
        'proposed_check_out' => null,
        'status' => CorrectionStatus::PENDING,
    ]);
}

function correctionNotifyUrl(Tenant $tenant, string $path = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections{$path}";
}

// ── Who is told when a correction is submitted ──────────────────────────────

it('tells the submitter\'s supervisor, and nobody else, that a correction is waiting', function () {
    Notification::fake();
    $tenant = createTenant();
    $team = correctionNotifyTeam($tenant);

    // A second tenant with the same shape, so "nobody else" is tested against
    // someone who would be a plausible wrong recipient.
    $other = createTenant();
    $otherTeam = correctionNotifyTeam($other);
    app(CurrentTenant::class)->set($tenant);

    $this->actingAs($team['staffUser'])
        ->postJson(correctionNotifyUrl($tenant), [
            'attendance_record_public_id' => $team['record']->public_id,
            'reason' => 'I arrived at 08:30; the reader was down.',
            'proposed_check_in' => '2026-10-05T08:30:00+03:00',
        ])
        ->assertCreated();

    Notification::assertSentToTimes($team['supervisorUser'], AttendanceCorrectionRequestedNotification::class, 1);
    Notification::assertNotSentTo($team['staffUser'], AttendanceCorrectionRequestedNotification::class);
    Notification::assertNothingSentTo($otherTeam['supervisorUser']);
    Notification::assertNothingSentTo($otherTeam['staffUser']);
});

it('still files the correction when the submitter has no supervisor to tell', function () {
    Notification::fake();
    $tenant = createTenant();
    $loner = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => null]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $loner->id], $tenant);
    $record = AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $loner->id]);

    $this->actingAs($user)
        ->postJson(correctionNotifyUrl($tenant), [
            'attendance_record_public_id' => $record->public_id,
            'reason' => 'Forgot to check out.',
        ])
        ->assertCreated();

    Notification::assertNothingSent();
    expect(AttendanceCorrection::where('attendance_record_id', $record->id)->count())->toBe(1);
});

it('does not tell a supervisor who has no login', function () {
    Notification::fake();
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $staff = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $staff->id], $tenant);
    $record = AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $staff->id]);

    $this->actingAs($user)
        ->postJson(correctionNotifyUrl($tenant), [
            'attendance_record_public_id' => $record->public_id,
            'reason' => 'Forgot to check out.',
        ])
        ->assertCreated();

    Notification::assertNothingSent();
});

// ── What the request notice says ────────────────────────────────────────────

it('describes the request by public id, name and the corrected day — never an internal id', function () {
    $tenant = createTenant();
    $team = correctionNotifyTeam($tenant);
    $correction = correctionNotifyPending($tenant, $team['staff'], $team['record']);

    $payload = (new AttendanceCorrectionRequestedNotification($correction->fresh()))
        ->toArray($team['supervisorUser']);

    expect($payload)->toBe([
        'correction_id' => $correction->public_id,
        'employee_name' => 'Abebe Kebede',
        'date' => '2026-10-05',
        'message' => 'Attendance correction request pending your review',
    ]);
});

// ── Who is told about the outcome ───────────────────────────────────────────

it('tells the employee, and only the employee, that their correction was approved', function () {
    Notification::fake();
    $tenant = createTenant();
    $team = correctionNotifyTeam($tenant);
    $correction = correctionNotifyPending($tenant, $team['staff'], $team['record']);

    $this->actingAs($team['supervisorUser'])
        ->putJson(correctionNotifyUrl($tenant, "/{$correction->public_id}/approve"))
        ->assertOk();

    Notification::assertSentToTimes($team['staffUser'], AttendanceCorrectionApprovedNotification::class, 1);
    Notification::assertSentTo(
        $team['staffUser'],
        AttendanceCorrectionApprovedNotification::class,
        fn ($n) => $n->toArray($team['staffUser'])['action'] === 'approved'
    );
    Notification::assertNothingSentTo($team['supervisorUser']);
});

it('tells the employee their correction was rejected, with the same notification class', function () {
    Notification::fake();
    $tenant = createTenant();
    $team = correctionNotifyTeam($tenant);
    $correction = correctionNotifyPending($tenant, $team['staff'], $team['record']);

    $this->actingAs($team['supervisorUser'])
        ->putJson(correctionNotifyUrl($tenant, "/{$correction->public_id}/reject"), ['reason' => 'No evidence'])
        ->assertOk();

    Notification::assertSentTo(
        $team['staffUser'],
        AttendanceCorrectionApprovedNotification::class,
        function ($n) use ($team) {
            $payload = $n->toArray($team['staffUser']);

            return $payload['action'] === 'rejected'
                && $payload['message'] === 'Your attendance correction for Oct 05 has been rejected';
        }
    );
});

it('sends nothing when a decided correction is approved again', function () {
    Notification::fake();
    $tenant = createTenant();
    $team = correctionNotifyTeam($tenant);
    $correction = correctionNotifyPending($tenant, $team['staff'], $team['record']);
    $correction->update(['status' => CorrectionStatus::APPROVED]);

    $this->actingAs($team['supervisorUser'])
        ->putJson(correctionNotifyUrl($tenant, "/{$correction->public_id}/approve"))
        ->assertStatus(422);

    Notification::assertNothingSent();
});

it('names the corrected day in the outcome message', function () {
    $tenant = createTenant();
    $team = correctionNotifyTeam($tenant);
    $correction = correctionNotifyPending($tenant, $team['staff'], $team['record']);

    $payload = (new AttendanceCorrectionApprovedNotification($correction->fresh(), true))
        ->toArray($team['staffUser']);

    expect($payload)->toBe([
        'correction_id' => $correction->public_id,
        'date' => '2026-10-05',
        'action' => 'approved',
        'message' => 'Your attendance correction for Oct 05 has been approved',
    ]);
});

// ── Channels ────────────────────────────────────────────────────────────────

it('delivers both correction notices in-app only while broadcasting is off', function () {
    $tenant = createTenant();
    $team = correctionNotifyTeam($tenant);
    $correction = correctionNotifyPending($tenant, $team['staff'], $team['record']);

    // BROADCAST_CONNECTION=null in phpunit.xml, as in production on shared
    // hosting. Neither class defines toMail(), so 'mail' must never appear —
    // a channel with no renderer throws at send time.
    expect((new AttendanceCorrectionRequestedNotification($correction))->via($team['supervisorUser']))
        ->toBe(['database'])
        ->and((new AttendanceCorrectionApprovedNotification($correction))->via($team['staffUser']))
        ->toBe(['database']);
});

it('adds the broadcast channel only when Reverb is the broadcaster', function () {
    config(['broadcasting.default' => 'reverb']);
    $tenant = createTenant();
    $team = correctionNotifyTeam($tenant);
    $correction = correctionNotifyPending($tenant, $team['staff'], $team['record']);

    expect((new AttendanceCorrectionRequestedNotification($correction))->via($team['supervisorUser']))
        ->toBe(['database', 'broadcast']);
});

it('keeps the in-app record even when the user has switched the correction type off', function () {
    $tenant = createTenant();
    $team = correctionNotifyTeam($tenant);
    $correction = correctionNotifyPending($tenant, $team['staff'], $team['record']);

    foreach (['in_app', 'email', 'sms'] as $channel) {
        NotificationPreference::create([
            'tenant_id' => $tenant->id,
            'user_id' => $team['staffUser']->id,
            'notification_type' => 'attendance_correction',
            'channel' => $channel,
            'enabled' => false,
        ]);
    }

    // in_app is the record of what happened, not a delivery preference — see
    // RespectsNotificationPreferences.
    expect((new AttendanceCorrectionApprovedNotification($correction))->via($team['staffUser']))
        ->toBe(['database']);
});

// ── Defects found in the controller that sends these ────────────────────────
//
// Written as todos against the old controller; all four were fixed by audit
// N3 (AttendanceCorrectionPolicy, CorrectionDecisionService) and fail on the
// code before it (20fde1d~1).

it('refuses to file a correction against a colleague\'s attendance record', function () {
    // store() used to look the record up by public_id alone and file the
    // correction under the CALLER's employee_id, so any employee could propose
    // new times for anyone's record in the tenant — and approval rewrote the
    // colleague's check-in/out. AttendanceCorrectionPolicy::file now ties it
    // to the caller's own records (or HR within org scope).
    $tenant = createTenant();
    $team = correctionNotifyTeam($tenant);
    $colleague = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $theirRecord = AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $colleague->id]);

    $response = $this->actingAs($team['staffUser'])
        ->postJson(correctionNotifyUrl($tenant), [
            'attendance_record_public_id' => $theirRecord->public_id,
            'reason' => 'Clocking my friend in.',
            'proposed_check_in' => '2026-10-05T06:00:00+03:00',
        ]);

    expect($response->status())->toBeIn([403, 404, 422]);
    expect(AttendanceCorrection::where('attendance_record_id', $theirRecord->id)->exists())->toBeFalse();
});

it('answers another tenant\'s attendance record exactly as it answers a nonexistent one', function () {
    // StoreCorrectionRequest's `exists:attendance_records,public_id` is
    // unscoped, so another tenant's real id passed it and 404'd at the
    // controller's scoped lookup while a nonexistent one got 422 — the
    // difference confirmed the id exists somewhere. Its withValidator() hook
    // now answers both with the same 422.
    $tenant = createTenant();
    $team = correctionNotifyTeam($tenant);

    $other = createTenant();
    $foreign = correctionNotifyTeam($other);
    app(CurrentTenant::class)->set($tenant);

    $post = fn (string $publicId) => $this->actingAs($team['staffUser'])
        ->postJson(correctionNotifyUrl($tenant), [
            'attendance_record_public_id' => $publicId,
            'reason' => 'Probe',
        ]);

    $nonexistent = $post('01JNOTAREALRECORD000000000');
    $crossTenant = $post($foreign['record']->public_id);

    expect($crossTenant->status())->toBe($nonexistent->status());
});

it('does not let a supervisor approve their own correction', function () {
    // Leave approval refused self-approval; correction approval did not, so a
    // supervisor could raise a correction on their own record and approve it,
    // rewriting their own hours. CorrectionDecisionService::refusal() now
    // refuses it with a 403.
    $tenant = createTenant();
    $team = correctionNotifyTeam($tenant);
    $ownRecord = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $team['supervisor']->id,
        'check_in' => Carbon::parse('2026-10-05 10:30'),
    ]);
    $own = correctionNotifyPending($tenant, $team['supervisor'], $ownRecord);

    $this->actingAs($team['supervisorUser'])
        ->putJson(correctionNotifyUrl($tenant, "/{$own->public_id}/approve"))
        ->assertForbidden();

    expect($own->fresh()->status)->toBe(CorrectionStatus::PENDING);
});

it('does not let a supervisor approve a correction for someone outside their reports', function () {
    // correction.approve was a bare permission check. AttendanceCorrectionPolicy
    // ::decide now also requires canAccessEmployee(), which limits a
    // SUPERVISOR to direct reports.
    $tenant = createTenant();
    $team = correctionNotifyTeam($tenant);
    $stranger = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => null]);
    $strangerRecord = AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $stranger->id]);
    $correction = correctionNotifyPending($tenant, $stranger, $strangerRecord);

    $this->actingAs($team['supervisorUser'])
        ->putJson(correctionNotifyUrl($tenant, "/{$correction->public_id}/approve"))
        ->assertForbidden();
});
