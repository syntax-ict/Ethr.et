<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use Carbon\Carbon;
use Tests\Support\IdempotencyAttendanceFixtures as Att;

/*
 * Attendance and devices: the same event or entry arriving a second time, read
 * back as the person's records for the day. A pushed event and a pulled event
 * carry different idempotency keys (`webhook:<vendor>:<device>:<badge>:<time>`
 * against `device:<device>:<badge>:<time>`), so the key cannot recognise one
 * event arriving both ways; only the day rules can.
 */

afterEach(fn () => Carbon::setTestNow());

/** @return list<string> every punch the live record logged, as kind:source:outcome */
function idemPunchLog(AttendanceRecord $record): array
{
    return collect($record->metadata['punches'] ?? [])->map(fn (array $p) => "{$p['kind']}:{$p['source']}:{$p['outcome']}")->all();
}

it('keeps one live record when a terminal event arrives by push and then by pull: the day rules merge them, the pulled check-in as a voided twin and the pulled check-out as a duplicate', function () {
    $ctx = Att::fixture();
    $device = Att::zktecoDevice($ctx);
    Att::bindPullAdapter($ctx);

    app()->forgetScopedInstances();
    test()->withHeader('X-Webhook-Token', 'idem-zkteco-token')
        ->postJson(Att::url($ctx['tenant'], 'devices/webhook/zkteco'), Att::zktecoPush($ctx))
        ->assertOk();

    expect(Att::rows($ctx['employee']))->toHaveCount(1);

    Att::pull($device);

    $rows = Att::rows($ctx['employee']);
    $live = Att::live($ctx['employee']);
    $voided = $rows->where('status', AttendanceStatus::VOIDED)->values();

    expect($live)->toHaveCount(1)
        ->and($live[0]->idempotency_key)->toStartWith('webhook:zk:')
        ->and($live[0]->check_in->equalTo($ctx['in']))->toBeTrue()
        ->and($live[0]->check_out->equalTo($ctx['out']))->toBeTrue()
        // The pulled check-in was written under its own key and then merged away.
        ->and($voided)->toHaveCount(1)
        ->and($voided[0]->idempotency_key)->toStartWith('device:')
        ->and($voided[0]->metadata['merged_into'])->toBe($live[0]->public_id)
        ->and(idemPunchLog($live[0]))->toBe([
            'check_in:biometric:created',
            'check_out:biometric:applied',
            'check_in:biometric:deduped',
            'check_out:biometric:duplicate',
        ]);
});

it('keeps one live record and no new row when PullDeviceEventsJob is run twice over the same events', function () {
    $ctx = Att::fixture();
    $device = Att::zktecoDevice($ctx);
    $fake = Att::bindPullAdapter($ctx);

    Att::pull($device);
    $afterFirst = Att::rows($ctx['employee']);
    $firstIds = $afterFirst->pluck('id')->all();
    $pulledOnce = $fake->pulls;

    Att::pull($device);
    $afterSecond = Att::rows($ctx['employee']);

    expect($fake->pulls)->toBeGreaterThan($pulledOnce)
        ->and($afterFirst)->toHaveCount(1)
        ->and($afterSecond->pluck('id')->all())->toBe($firstIds)
        ->and(Att::live($ctx['employee']))->toHaveCount(1)
        ->and($afterSecond[0]->check_in->equalTo($ctx['in']))->toBeTrue()
        ->and($afterSecond[0]->check_out->equalTo($ctx['out']))->toBeTrue();
});

it('keeps one record when a manual entry is submitted twice with the same key, today: the second is answered 200 with was_duplicate instead of 201', function () {
    $ctx = Att::fixture();
    test()->actingAs($ctx['hr']);
    $body = [
        'idempotency_key' => 'idem-manual',
        'employee_public_id' => $ctx['employee']->public_id,
        'date' => Att::DAY,
        'check_in' => '08:00',
        'check_out' => '17:00',
        'reason' => 'Read off the paper register',
    ];

    test()->postJson(Att::url($ctx['tenant'], 'attendance/manual'), $body)->assertCreated();
    test()->postJson(Att::url($ctx['tenant'], 'attendance/manual'), $body)->assertOk()->assertJsonPath('was_duplicate', true);

    $rows = Att::rows($ctx['employee']);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->check_in->equalTo($ctx['in']))->toBeTrue()
        ->and($rows[0]->check_out->equalTo($ctx['out']))->toBeTrue()
        ->and(idemPunchLog($rows[0]))->toBe(['check_in:manual:created']);
});

it('keeps one live record when the same manual entry is re-sent under a fresh key: the day rule voids the second as a twin merged into the first', function () {
    $ctx = Att::fixture();
    test()->actingAs($ctx['hr']);
    $body = [
        'employee_public_id' => $ctx['employee']->public_id,
        'date' => Att::DAY,
        'check_in' => '08:00',
        'check_out' => '17:00',
        'reason' => 'Read off the paper register',
    ];

    test()->postJson(Att::url($ctx['tenant'], 'attendance/manual'), [...$body, 'idempotency_key' => 'idem-manual-a'])->assertCreated();
    test()->postJson(Att::url($ctx['tenant'], 'attendance/manual'), [...$body, 'idempotency_key' => 'idem-manual-b'])->assertCreated();

    $live = Att::live($ctx['employee']);
    $voided = Att::rows($ctx['employee'])->where('status', AttendanceStatus::VOIDED)->values();

    expect($live)->toHaveCount(1)
        ->and($voided)->toHaveCount(1)
        ->and($voided[0]->metadata['merged_into'])->toBe($live[0]->public_id)
        ->and($live[0]->check_in->equalTo($ctx['in']))->toBeTrue()
        ->and($live[0]->check_out->equalTo($ctx['out']))->toBeTrue();
});

it('creates nothing the second time a committed CSV import is committed again, today: it is answered 201 with created 0 and a second audit row', function () {
    $ctx = Att::fixture();
    test()->actingAs($ctx['hr']);
    $body = [
        'import_key' => 'idem-import',
        'rows' => [['employee_code' => Att::CODE, 'date' => Att::DAY, 'check_in' => '08:00', 'check_out' => '17:00']],
    ];

    test()->postJson(Att::url($ctx['tenant'], 'attendance/import/commit'), $body)
        ->assertCreated()->assertJsonPath('created', 1)->assertJsonPath('skipped', 0);
    test()->postJson(Att::url($ctx['tenant'], 'attendance/import/commit'), $body)
        ->assertCreated()->assertJsonPath('created', 0)->assertJsonPath('skipped', 1)->assertJsonPath('errors', []);

    $rows = Att::rows($ctx['employee']);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->check_in->equalTo($ctx['in']))->toBeTrue()
        ->and(AuditLog::where('action', 'attendance.import.committed')->count())->toBe(2);
});
