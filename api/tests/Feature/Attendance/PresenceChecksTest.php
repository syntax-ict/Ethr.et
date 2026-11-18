<?php

declare(strict_types=1);

use App\Enums\AttendanceMode;
use App\Enums\AttendanceStatus;
use App\Enums\Feature;
use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendancePresenceCheck;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\BranchAttendanceOverride;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PushSubscription;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attendance\AttendancePolicy;
use App\Services\Attendance\PresenceChecks;
use App\Services\CurrentTenant;
use App\Services\Push\WebPushSender;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SoftAuthenticator;

/**
 * Presence checks: a few unannounced "are you at work?" pushes during a
 * shift, answered from the phone with its position (PresenceChecks). Off by
 * default; sent from the punch-reminder pass; an answer from outside the
 * workplace is a finding at once, a run of misses is a pattern.
 */
const PC_LAT = 9.0108;
const PC_LNG = 38.7613;
const PC_ZONE = 'Africa/Addis_Ababa';

afterEach(fn () => Carbon::setTestNow());

/** A sender whose pushes are recorded instead of sent. */
class PresenceRecordingSender extends WebPushSender
{
    /** @var list<array{user: int, payload: array<string, mixed>}> */
    public array $sent = [];

    /** How many pushes the services accept; 0 is a dead subscription. */
    public int $accepts = 1;

    public function toUser(int $userId, array $payload): int
    {
        $this->sent[] = ['user' => $userId, 'payload' => $payload];

        return $this->accepts;
    }

    /** @return list<array{user: int, payload: array<string, mixed>}> */
    public function presence(): array
    {
        return array_values(array_filter($this->sent, fn (array $s): bool => ($s['payload']['tag'] ?? null) === 'presence-check'));
    }
}

function pcUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

function pcLocal(string $time): Carbon
{
    return Carbon::parse("2026-10-12 {$time}", PC_ZONE)->utc();
}

/**
 * An organisation with presence checks on, one office employee checked in at
 * 08:25 on a Monday, a phone subscribed to push, and a recording sender.
 *
 * @return array{tenant: Tenant, branch: Branch, shift: Shift, employee: Employee, user: User, record: AttendanceRecord, sender: PresenceRecordingSender}
 */
function pcWorld(int $perShift = 2, array $settings = []): array
{
    config(['webpush.public_key' => 'BPublicKeyForTests', 'webpush.private_key' => 'PrivateKeyForTests']);
    $sender = new PresenceRecordingSender;
    app()->instance(WebPushSender::class, $sender);

    Carbon::setTestNow(pcLocal('08:25'));
    $tenant = createTenant(['timezone' => PC_ZONE]);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'latitude' => PC_LAT, 'longitude' => PC_LNG, 'geofence_radius_meters' => 150]);
    $shift = Shift::factory()->default()->create(['tenant_id' => $tenant->id, 'start_time' => '08:30', 'end_time' => '17:30', 'break_minutes' => 60, 'working_days' => '1,2,3,4,5']);
    AttendanceSetting::create(['tenant_id' => $tenant->id, ...AttendanceSetting::defaults(), 'presence_checks_per_shift' => $perShift, ...$settings]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $endpoint = 'https://fcm.googleapis.com/fcm/send/'.$user->id;
    PushSubscription::create([
        'tenant_id' => $tenant->id, 'user_id' => $user->id, 'endpoint' => $endpoint,
        'endpoint_hash' => PushSubscription::hashEndpoint($endpoint), 'public_key' => 'p256dh', 'auth_token' => 'auth',
    ]);
    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'shift_id' => $shift->id,
        'date' => '2026-10-12', 'check_in' => pcLocal('08:25'), 'check_out' => null, 'status' => AttendanceStatus::PRESENT,
    ]);

    return compact('tenant', 'branch', 'shift', 'employee', 'user', 'record', 'sender');
}

/** @return array<int, Carbon> */
function pcTimes(AttendanceRecord $record, int $perShift = 3): array
{
    return app(PresenceChecks::class)->times($record->fresh(['shift']), $perShift, PC_ZONE);
}

function pcTick(Carbon $at): void
{
    $tenant = app(CurrentTenant::class)->get();
    Carbon::setTestNow($at);
    test()->artisan('attendance:push-reminders')->assertSuccessful();

    // The command leaves no organisation resolved, as a real pass does; the
    // test goes back to the one it was reading.
    app(CurrentTenant::class)->set($tenant);
}

function pcScan(): void
{
    $tenant = app(CurrentTenant::class)->get();
    test()->artisan('attendance:pattern-scan')->assertSuccessful();
    app(CurrentTenant::class)->set($tenant);
}

/** A check already sent, waiting for an answer. */
function pcSent(array $w, ?Carbon $sentAt = null, int $slot = 1): AttendancePresenceCheck
{
    $sentAt ??= Carbon::now('UTC');

    return app(PresenceChecks::class)->send($w['record'], $w['employee'], $w['user'], $slot, $sentAt, $w['sender'], 'en');
}

function pcConfirm(Tenant $tenant, array $body = [], string $key = 'pc-1')
{
    return test()->postJson(pcUrl($tenant, 'attendance/presence/confirm'), [
        'idempotency_key' => $key, 'latitude' => PC_LAT, 'longitude' => PC_LNG, 'accuracy' => 10, ...$body,
    ]);
}

// ── When ────────────────────────────────────────────────────────────────────

test('the times of a record repeat, sit inside the window and keep clear of the middle of the day', function () {
    $w = pcWorld();
    $service = app(PresenceChecks::class);
    $counts = [];

    // Many records, so the spread of the seed is exercised and not one lucky id.
    for ($i = 0; $i < 20; $i++) {
        $record = AttendanceRecord::factory()->create([
            'tenant_id' => $w['tenant']->id, 'employee_id' => $w['employee']->id, 'shift_id' => $w['shift']->id,
            'date' => '2026-10-12', 'check_in' => pcLocal('08:25'), 'check_out' => null,
        ])->load('shift');

        $times = $service->times($record, 3, PC_ZONE);
        expect($service->times($record, 3, PC_ZONE))->toEqual($times);
        $counts[count($times)] = true;
        expect(count($times))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(3);

        $previous = null;
        foreach ($times as $slot => $at) {
            expect($slot)->toBeGreaterThanOrEqual(1);
            // 08:30 start, in at 08:25: from 09:00. Shift ends 17:30: until 16:45.
            expect($at->gte(pcLocal('09:00')))->toBeTrue()
                ->and($at->lte(pcLocal('16:45')))->toBeTrue()
                // Middle 13:00, half the 60 minute break plus 15: 12:15 to 13:45 is clear.
                ->and($at->gt(pcLocal('12:15')) && $at->lt(pcLocal('13:45')))->toBeFalse();
            if ($previous !== null) {
                expect($at->gt($previous))->toBeTrue();
            }
            $previous = $at;
        }
    }

    expect(array_keys($counts))->toContain(1)->toContain(2)->toContain(3);
});

test('the times never exceed what the setting allows', function () {
    $w = pcWorld(1);

    expect(pcTimes($w['record'], 1))->toHaveCount(1)
        ->and(app(PresenceChecks::class)->times($w['record']->fresh(['shift']), 0, PC_ZONE))->toBe([]);
});

test('a shift too short to leave an hour usable has no checks', function () {
    $w = pcWorld();
    $short = Shift::factory()->create(['tenant_id' => $w['tenant']->id, 'start_time' => '08:30', 'end_time' => '10:30', 'break_minutes' => 0]);
    $w['record']->update(['shift_id' => $short->id]);

    expect(pcTimes($w['record']))->toBe([]);
});

test('a record without a shift or a check-in has no checks', function () {
    $w = pcWorld();
    $w['record']->update(['shift_id' => null]);

    expect(pcTimes($w['record']))->toBe([]);
});

// ── Send ────────────────────────────────────────────────────────────────────

test('a check no push service accepted leaves no row, is not counted as sent, and cannot be missed', function () {
    $w = pcWorld(3);
    $w['sender']->accepts = 0;
    $first = pcTimes($w['record'])[1];

    $tenant = app(CurrentTenant::class)->get();
    Carbon::setTestNow($first);
    $this->artisan('attendance:push-reminders')->expectsOutputToContain('Push reminders sent: 0.')->assertSuccessful();
    app(CurrentTenant::class)->set($tenant);

    expect(AttendancePresenceCheck::query()->count())->toBe(0);

    // Nothing to expire into a miss, so the pattern scan has nothing to count.
    pcTick($first->copy()->addMinutes(40));
    pcScan();
    expect(AttendancePresenceCheck::query()->count())->toBe(0)
        ->and(AttendanceIntegrityFlag::query()->where('type', 'presence_pattern')->count())->toBe(0);
});

test('a check is pushed once the derived time is reached, and only once across two passes', function () {
    $w = pcWorld(3);
    $first = pcTimes($w['record'])[1];

    pcTick($first->copy()->subMinute());
    expect($w['sender']->presence())->toHaveCount(0);

    pcTick($first);
    pcTick($first->copy()->addMinutes(2));

    $sent = $w['sender']->presence();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['user'])->toBe($w['user']->id)
        ->and($sent[0]['payload']['body'])->toBe('Confirm you are at work. You have 15 minutes.')
        ->and($sent[0]['payload']['url'])->toBe('/attendance/mobile')
        ->and($sent[0]['payload']['ttl'])->toBe(900)
        ->and($sent[0]['payload']['urgency'])->toBe('high')
        ->and($sent[0]['payload']['requireInteraction'])->toBeTrue();

    $row = AttendancePresenceCheck::query()->sole();
    expect($row->status)->toBe('sent')
        ->and($row->slot)->toBe(1)
        ->and($row->sent_at->equalTo($first))->toBeTrue()
        ->and($row->expires_at->equalTo($first->copy()->addMinutes(15)))->toBeTrue();
});

test('a tick that is late still sends, and the answer window runs from the real send', function () {
    $w = pcWorld(3);
    $first = pcTimes($w['record'])[1];
    $late = $first->copy()->addMinutes(4);

    pcTick($late);

    $row = AttendancePresenceCheck::query()->sole();
    expect($row->sent_at->equalTo($late))->toBeTrue()
        ->and($row->expires_at->equalTo($late->copy()->addMinutes(15)))->toBeTrue();
});

test('a second check waits for the first to be answered or run out', function () {
    $w = pcWorld(3);
    $times = pcTimes($w['record']);
    // Whatever the count, ask once at the first time, then again well after it expired.
    pcTick($times[1]);
    pcTick($times[1]->copy()->addMinutes(5));
    expect(AttendancePresenceCheck::query()->count())->toBe(1);

    if (isset($times[2])) {
        pcTick(max($times[2], $times[1]->copy()->addMinutes(20)));
        expect(AttendancePresenceCheck::query()->count())->toBe(2)
            ->and(AttendancePresenceCheck::query()->where('slot', 1)->value('status'))->toBe('missed');
    }
});

test('nothing is sent when the person cannot or should not be checked', function (Closure $change) {
    $w = pcWorld(3);
    $at = pcTimes($w['record'])[1];
    $change($w);

    pcTick($at);

    expect($w['sender']->presence())->toHaveCount(0)
        ->and(AttendancePresenceCheck::query()->count())->toBe(0);
})->with([
    'a remote worker' => [fn (array $w) => $w['employee']->forceFill(['attendance_mode' => AttendanceMode::REMOTE])->save()],
    'a field worker' => [fn (array $w) => $w['employee']->forceFill(['attendance_mode' => AttendanceMode::FIELD])->save()],
    'on approved leave' => [function (array $w) {
        LeaveRequest::factory()->create([
            'tenant_id' => $w['tenant']->id, 'employee_id' => $w['employee']->id,
            'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $w['tenant']->id])->id,
            'start_date' => '2026-10-12', 'end_date' => '2026-10-13', 'status' => LeaveStatus::APPROVED,
        ]);
    }],
    'a public holiday' => [fn (array $w) => Holiday::factory()->create(['tenant_id' => $w['tenant']->id, 'date' => '2026-10-12', 'is_active' => true])],
    'no push subscription' => [fn (array $w) => PushSubscription::query()->delete()],
    'no place to be measured against' => [fn (array $w) => $w['branch']->update(['latitude' => null, 'longitude' => null])],
    'an open break' => [fn (array $w) => $w['record']->update(['metadata' => ['breaks' => [['type' => 'out', 'at' => '2026-10-12T09:30:00+00:00', 'key' => 'k']]]])],
    'the setting off' => [fn (array $w) => AttendanceSetting::query()->update(['presence_checks_per_shift' => 0])],
    'a closed record' => [fn (array $w) => $w['record']->update(['check_out' => pcLocal('09:10')])],
    'a voided record' => [fn (array $w) => $w['record']->update(['status' => AttendanceStatus::VOIDED])],
    'an inactive account' => [fn (array $w) => $w['user']->update(['status' => 'inactive'])],
    'the platform switch off' => [fn (array $w) => switchFeature(Feature::WebPush)],
]);

test('a break that has ended does not stop a check', function () {
    $w = pcWorld(3);
    $w['record']->update(['metadata' => ['breaks' => [
        ['type' => 'out', 'at' => '2026-10-12T09:30:00+00:00', 'key' => 'a'],
        ['type' => 'in', 'at' => '2026-10-12T09:50:00+00:00', 'key' => 'b'],
    ]]]);

    pcTick(pcTimes($w['record'])[1]);

    expect($w['sender']->presence())->toHaveCount(1);
});

test('a branch override switches checks on for its people and off again', function () {
    $w = pcWorld(0);
    $at = pcLocal('11:00');
    expect(app(PresenceChecks::class)->due($w['record']->load('shift'), $w['employee'], app(AttendancePolicy::class)->for($w['employee']), PC_ZONE, $at))->toBeNull();

    BranchAttendanceOverride::create(['tenant_id' => $w['tenant']->id, 'branch_id' => $w['branch']->id, 'overrides' => ['presence_checks_per_shift' => 3]]);
    $times = pcTimes($w['record']);
    pcTick($times[1]);
    expect($w['sender']->presence())->toHaveCount(1);

    $w['record']->refresh();
    AttendancePresenceCheck::query()->delete();
    $w['sender']->sent = [];
    BranchAttendanceOverride::query()->update(['overrides' => ['presence_checks_per_shift' => 0]]);
    AttendanceSetting::query()->update(['presence_checks_per_shift' => 3]);
    pcTick($times[1]->copy()->addMinutes(1));
    expect($w['sender']->presence())->toHaveCount(0);
});

test('the setting is saved, read back and bounded', function () {
    $w = pcWorld(0);
    $admin = createUser(['role' => UserRole::HR_ADMIN], $w['tenant']);
    test()->actingAs($admin);

    test()->putJson(pcUrl($w['tenant'], 'attendance/settings'), ['presence_checks_per_shift' => 2])->assertOk()->assertJsonPath('presence_checks_per_shift', 2);
    test()->putJson(pcUrl($w['tenant'], 'attendance/settings'), ['presence_checks_per_shift' => 4])->assertUnprocessable()->assertJsonValidationErrors('presence_checks_per_shift');
    test()->putJson(pcUrl($w['tenant'], "attendance/settings/branches/{$w['branch']->public_id}"), ['overrides' => ['presence_checks_per_shift' => 1]])->assertOk();
    expect(BranchAttendanceOverride::query()->sole()->overrides['presence_checks_per_shift'])->toBe(1);
    test()->putJson(pcUrl($w['tenant'], "attendance/settings/branches/{$w['branch']->public_id}"), ['overrides' => ['presence_checks_per_shift' => 9]])->assertUnprocessable();
});

// ── The reminder pass ───────────────────────────────────────────────────────

test('punch reminders still fire as before, with presence checks on', function () {
    $w = pcWorld(3);
    DB::table('attendance_records')->where('id', $w['record']->id)->delete();

    pcTick(pcLocal('08:21'));

    $tags = array_column(array_column($w['sender']->sent, 'payload'), 'tag');
    expect($tags)->toContain('punch-before_start');
});

test('presence checks go out even with the reminder switches off', function () {
    $w = pcWorld(3, ['reminders_enabled' => false]);

    pcTick(pcTimes($w['record'])[1]);

    expect($w['sender']->presence())->toHaveCount(1);
});

test('neither reminders nor checks go out with web push switched off for the organisation', function () {
    $w = pcWorld(3);
    $w['record']->forceFill(['check_out' => null])->save();
    switchFeature(Feature::WebPush);

    pcTick(pcLocal('08:21'));
    pcTick(pcTimes($w['record'])[1]);

    expect($w['sender']->sent)->toBe([]);
});

test('reminders skip an inactive user', function () {
    $w = pcWorld(0);
    DB::table('attendance_records')->where('id', $w['record']->id)->delete();
    $w['user']->update(['status' => 'inactive']);

    pcTick(pcLocal('08:21'));

    expect($w['sender']->sent)->toBe([]);
});

test('unanswered checks become missed and old rows are purged once a day', function () {
    $w = pcWorld(3);
    $sent = pcSent($w, pcLocal('09:00'), 1);
    $old = AttendancePresenceCheck::query()->create([
        'tenant_id' => $w['tenant']->id, 'employee_id' => $w['employee']->id, 'attendance_record_id' => $w['record']->id,
        'slot' => 3, 'sent_at' => pcLocal('09:00')->subDays(91), 'expires_at' => pcLocal('09:15')->subDays(91), 'status' => 'missed',
    ]);

    pcTick(pcLocal('09:16'));

    expect($sent->fresh()->status)->toBe('missed')
        ->and(AttendancePresenceCheck::query()->whereKey($old->id)->exists())->toBeFalse();
});

// ── Answer ──────────────────────────────────────────────────────────────────

test('an answer from the workplace confirms the check', function () {
    $w = pcWorld(3);
    test()->actingAs($w['user']);
    $row = pcSent($w);

    Carbon::setTestNow(Carbon::now('UTC')->addMinutes(3));
    pcConfirm($w['tenant'])->assertOk()
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('public_id', $row->public_id)
        ->assertJsonPath('late', false);

    $row->refresh();
    expect($row->status)->toBe('confirmed')
        ->and($row->answered_at)->not->toBeNull()
        ->and($row->idempotency_key)->toBe('pc-1')
        ->and($row->distance_meters)->toBeLessThan(5)
        ->and(AttendanceIntegrityFlag::query()->count())->toBe(0);
});

test('an answer from outside is a finding at once, filed as a presence flag', function () {
    $w = pcWorld(3);
    test()->actingAs($w['user']);
    pcSent($w);

    pcConfirm($w['tenant'], ['latitude' => 9.0500])->assertOk()->assertJsonPath('status', 'outside');

    $flag = AttendanceIntegrityFlag::query()->sole();
    expect($flag->punch)->toBe('presence')
        ->and($flag->type)->toBe('outside_geofence')
        ->and($flag->severity)->toBe('high')
        ->and($flag->status)->toBe('open')
        ->and($flag->details['source'])->toBe('presence')
        ->and($flag->details['distance_meters'])->toBeGreaterThan(1000)
        ->and($flag->details['nearest']['kind'])->toBe('branch')
        ->and($flag->attendance_record_id)->toBe($w['record']->id);
});

test('an answer after the deadline is missed, keeps its time and place, and files nothing', function () {
    $w = pcWorld(3);
    test()->actingAs($w['user']);
    $row = pcSent($w);

    Carbon::setTestNow(Carbon::now('UTC')->addMinutes(16));
    pcConfirm($w['tenant'], ['latitude' => 9.0500])->assertOk()
        ->assertJsonPath('status', 'missed')
        ->assertJsonPath('late', true);

    $row->refresh();
    expect($row->status)->toBe('missed')
        ->and($row->answered_at)->not->toBeNull()
        ->and((float) $row->latitude)->toBe(9.05)
        ->and(AttendanceIntegrityFlag::query()->count())->toBe(0);
});

test('an answer to a check the sweep already marked missed is still recorded, as missed', function () {
    $w = pcWorld(3);
    test()->actingAs($w['user']);
    $row = pcSent($w);
    Carbon::setTestNow(Carbon::now('UTC')->addMinutes(20));
    app(PresenceChecks::class)->expire(Carbon::now('UTC'));
    expect($row->fresh()->status)->toBe('missed');

    pcConfirm($w['tenant'])->assertOk()->assertJsonPath('status', 'missed');

    expect($row->fresh()->answered_at)->not->toBeNull();
});

test('a repeated request and a second answer both return what was stored first', function () {
    $w = pcWorld(3);
    test()->actingAs($w['user']);
    $row = pcSent($w);

    pcConfirm($w['tenant'])->assertOk()->assertJsonPath('status', 'confirmed');
    $answered = $row->fresh()->answered_at;

    pcConfirm($w['tenant'])->assertOk()->assertJsonPath('status', 'confirmed');
    pcConfirm($w['tenant'], ['latitude' => 9.05], 'pc-2')->assertNotFound();

    expect($row->fresh()->answered_at->equalTo($answered))->toBeTrue()
        ->and(AttendanceIntegrityFlag::query()->count())->toBe(0);
});

test('there is nothing to confirm when no check was sent', function () {
    $w = pcWorld(3);
    test()->actingAs($w['user']);

    pcConfirm($w['tenant'])->assertNotFound()->assertJsonPath('detail', 'There is no presence check to confirm.');
});

test('another organisation has nothing to confirm', function () {
    $a = pcWorld(3);
    $row = pcSent($a);

    $tenantB = createTenant(['timezone' => PC_ZONE]);
    $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id, 'latitude' => PC_LAT, 'longitude' => PC_LNG, 'geofence_radius_meters' => 150]);
    $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id, 'branch_id' => $branchB->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employeeB->id], $tenantB));

    pcConfirm($tenantB)->assertNotFound();

    expect($row->fresh()->answered_at)->toBeNull();
});

test('a user with no employee profile is told so', function () {
    $w = pcWorld(3);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => null], $w['tenant']));

    pcConfirm($w['tenant'])->assertNotFound();
});

test('the answer needs a position and a key', function () {
    $w = pcWorld(3);
    test()->actingAs($w['user']);
    pcSent($w);

    test()->postJson(pcUrl($w['tenant'], 'attendance/presence/confirm'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['idempotency_key', 'latitude', 'longitude']);
});

test('a wrong phone is refused under enforce and the check is left to run out', function () {
    $w = pcWorld(3, ['device_binding' => 'enforce', 'attendance_rp_id' => 'ethr.test']);
    test()->actingAs($w['user']);
    $row = pcSent($w);

    pcConfirm($w['tenant'])->assertStatus(422)->assertJsonPath('code', 'device-not-registered');

    $row->refresh();
    expect($row->status)->toBe('sent')
        ->and($row->answered_at)->toBeNull()
        ->and($row->idempotency_key)->toBeNull();
});

test('the registered phone confirms under enforce', function () {
    $w = pcWorld(3, ['device_binding' => 'enforce', 'attendance_rp_id' => 'ethr.test']);
    test()->actingAs($w['user']);
    $auth = new SoftAuthenticator;
    $options = test()->postJson(pcUrl($w['tenant'], 'attendance/device/register-options'))->assertOk()->json('options');
    test()->postJson(pcUrl($w['tenant'], 'attendance/device/register'), [
        'credential' => $auth->register($options), 'device_id' => 'install-a', 'label' => 'Phone',
        'latitude' => PC_LAT, 'longitude' => PC_LNG, 'accuracy' => 12,
    ])->assertCreated()->assertJsonPath('status', 'active');
    $row = pcSent($w);

    $challenge = test()->postJson(pcUrl($w['tenant'], 'attendance/device/challenge'))->assertOk()->json('options');
    pcConfirm($w['tenant'], ['device_id' => 'install-a', 'device_proof' => $auth->sign($challenge['rpId'], $challenge['challenge'])])
        ->assertOk()->assertJsonPath('status', 'confirmed');

    expect($row->fresh()->device_verdict)->toBe('verified');
});

test('a wrong phone under flag is answered as unverified', function () {
    $w = pcWorld(3, ['device_binding' => 'flag', 'attendance_rp_id' => 'ethr.test']);
    test()->actingAs($w['user']);
    $row = pcSent($w);

    pcConfirm($w['tenant'])->assertOk()->assertJsonPath('status', 'unverified');

    expect($row->fresh()->device_verdict)->toBe('unregistered')
        ->and(AttendanceIntegrityFlag::query()->count())->toBe(0);
});

// ── Findings ────────────────────────────────────────────────────────────────

function pcMisses(array $w, int $missed, int $unverified = 0, int $confirmed = 0): void
{
    $slot = 0;
    foreach (['missed' => $missed, 'unverified' => $unverified, 'confirmed' => $confirmed] as $status => $count) {
        for ($i = 0; $i < $count; $i++) {
            $record = AttendanceRecord::factory()->create([
                'tenant_id' => $w['tenant']->id, 'employee_id' => $w['employee']->id, 'shift_id' => $w['shift']->id,
                'date' => '2026-10-1'.(++$slot % 9), 'check_in' => Carbon::now('UTC')->subDays($slot), 'check_out' => null,
            ]);
            AttendancePresenceCheck::query()->create([
                'tenant_id' => $w['tenant']->id, 'employee_id' => $w['employee']->id, 'attendance_record_id' => $record->id,
                'slot' => 1, 'sent_at' => Carbon::now('UTC')->subDays($slot), 'expires_at' => Carbon::now('UTC')->subDays($slot)->addMinutes(15),
                'status' => $status, 'answered_at' => $status === 'missed' ? null : Carbon::now('UTC')->subDays($slot),
            ]);
        }
    }
}

test('one missed check files nothing, and a pattern is filed once across two runs', function () {
    $w = pcWorld(3);
    pcMisses($w, 1);
    pcScan();
    expect(AttendanceIntegrityFlag::query()->where('type', 'presence_pattern')->count())->toBe(0);

    pcMisses($w, 1, 1);
    pcScan();
    pcScan();

    // toEqual, not toBe: MySQL 8 stores a JSON object with its keys reordered,
    // so the same details come back in another order there.
    $flag = AttendanceIntegrityFlag::query()->where('type', 'presence_pattern')->sole();
    expect($flag->punch)->toBe('pattern')
        ->and($flag->severity)->toBe('medium')
        ->and($flag->details)->toEqual(['missed' => 2, 'unverified' => 1, 'total' => 3, 'days' => 14]);
});

test('misses that are under half of the checks are not a pattern, and four are serious', function () {
    $w = pcWorld(3);
    pcMisses($w, 2, 0, 3);
    pcScan();
    expect(AttendanceIntegrityFlag::query()->where('type', 'presence_pattern')->count())->toBe(0);

    pcMisses($w, 2, 0, 0);
    pcScan();
    expect(AttendanceIntegrityFlag::query()->where('type', 'presence_pattern')->sole()->severity)->toBe('high');
});

// ── Review ──────────────────────────────────────────────────────────────────

test('rejecting a presence or pattern flag leaves the day standing, rejecting a punch flag still voids it', function (string $punch, bool $voids) {
    $w = pcWorld(3);
    $hr = createUser(['role' => UserRole::HR_ADMIN], $w['tenant']);
    $flag = AttendanceIntegrityFlag::query()->create([
        'tenant_id' => $w['tenant']->id, 'attendance_record_id' => $w['record']->id, 'employee_id' => $w['employee']->id,
        'punch' => $punch, 'type' => 'outside_geofence', 'severity' => 'high', 'details' => [], 'status' => 'open',
    ]);
    $other = AttendanceIntegrityFlag::query()->create([
        'tenant_id' => $w['tenant']->id, 'attendance_record_id' => $w['record']->id, 'employee_id' => $w['employee']->id,
        'punch' => 'day', 'type' => 'high_risk', 'severity' => 'medium', 'details' => [], 'status' => 'open',
    ]);

    test()->actingAs($hr)->putJson(pcUrl($w['tenant'], "attendance/integrity/{$flag->public_id}/review"), ['decision' => 'reject', 'note' => 'Not at work.'])
        ->assertOk()->assertJsonPath('status', 'rejected');

    $flag->refresh();
    expect($flag->status)->toBe('rejected')
        ->and($flag->reviewed_by)->toBe($hr->id)
        ->and($flag->review_note)->toBe('Not at work.')
        ->and($w['record']->fresh()->status === AttendanceStatus::VOIDED)->toBe($voids)
        ->and($other->fresh()->status)->toBe($voids ? 'rejected' : 'open');
})->with([
    'presence' => ['presence', false],
    'pattern' => ['pattern', false],
    'check_in' => ['check_in', true],
    'check_out' => ['check_out', true],
    'day' => ['day', true],
]);

// ── Phone and health ────────────────────────────────────────────────────────

test('my shift shows a pending check, then nothing once it is answered', function () {
    $w = pcWorld(3);
    test()->actingAs($w['user']);

    test()->getJson(pcUrl($w['tenant'], 'attendance/my-shift'))->assertOk()
        ->assertJsonPath('presence.enabled', true)
        ->assertJsonPath('presence.pending', null);

    $row = pcSent($w);
    test()->getJson(pcUrl($w['tenant'], 'attendance/my-shift'))->assertOk()
        ->assertJsonPath('presence.pending.asked_at', $row->sent_at->toIso8601String())
        ->assertJsonPath('presence.pending.expires_at', $row->expires_at->toIso8601String());

    pcConfirm($w['tenant'])->assertOk();
    test()->getJson(pcUrl($w['tenant'], 'attendance/my-shift'))->assertOk()->assertJsonPath('presence.pending', null);
});

test('my shift says presence is off when the setting is off or there is no place', function () {
    $w = pcWorld(0);
    test()->actingAs($w['user']);
    test()->getJson(pcUrl($w['tenant'], 'attendance/my-shift'))->assertOk()->assertJsonPath('presence.enabled', false);

    AttendanceSetting::query()->update(['presence_checks_per_shift' => 2]);
    $w['branch']->update(['latitude' => null, 'longitude' => null]);
    test()->actingAs($w['user']->fresh());
    test()->getJson(pcUrl($w['tenant'], 'attendance/my-shift'))->assertOk()->assertJsonPath('presence.enabled', false);
});

test('the setup check counts office staff the checks cannot reach', function () {
    $w = pcWorld(2);
    Holiday::factory()->create(['tenant_id' => $w['tenant']->id, 'date' => '2026-09-11', 'is_active' => true]);
    $admin = createUser(['role' => UserRole::HR_ADMIN], $w['tenant']);
    test()->actingAs($admin);
    $find = fn (): ?array => collect(test()->getJson(pcUrl($w['tenant'], 'attendance/setup-status'))->assertOk()->json('checks'))->firstWhere('id', 'presence_unreachable');

    // Subscribed and at a branch with a place: reachable.
    expect($find())->toBeNull();

    // A second person with no phone subscribed, and a third in a branch with no place.
    Employee::factory()->create(['tenant_id' => $w['tenant']->id, 'branch_id' => $w['branch']->id]);
    $bare = Branch::factory()->create(['tenant_id' => $w['tenant']->id, 'latitude' => null, 'longitude' => null]);
    $third = Employee::factory()->create(['tenant_id' => $w['tenant']->id, 'branch_id' => $bare->id]);
    $thirdUser = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $third->id], $w['tenant']);
    PushSubscription::create([
        'tenant_id' => $w['tenant']->id, 'user_id' => $thirdUser->id, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/third',
        'endpoint_hash' => PushSubscription::hashEndpoint('https://fcm.googleapis.com/fcm/send/third'), 'public_key' => 'p', 'auth_token' => 'a',
    ]);
    // Remote staff are never counted.
    Employee::factory()->create(['tenant_id' => $w['tenant']->id, 'branch_id' => $w['branch']->id, 'attendance_mode' => AttendanceMode::REMOTE]);

    $check = $find();
    expect($check)->not->toBeNull()
        ->and($check['count'])->toBe(2)
        ->and($check['severity'])->toBe('warning')
        ->and($check['fix_route'])->toBe('/settings/attendance');

    AttendanceSetting::query()->update(['presence_checks_per_shift' => 0]);
    expect($find())->toBeNull();
});
