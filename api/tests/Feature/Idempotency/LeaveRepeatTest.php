<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Models\AuditLog;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Notifications\LeaveApprovedNotification;
use App\Notifications\LeaveChangedNotification;
use App\Notifications\LeaveRejectedNotification;
use Illuminate\Support\Facades\Notification;
use Tests\Support\IdempotencyLeaveFixtures as Leave;

/*
 * Leave: the same request, decision or recall sent a second time, read back
 * from the balance and the request rows. Sequential repeats; the races are in
 * tests/Concurrency.
 */

it('keeps one request and raises pending days once when the identical request is submitted twice, today: the second is answered 422 overlap, not the first request', function () {
    $f = Leave::tenant();
    $body = [
        'leave_type_public_id' => $f['type']->public_id,
        'start_date' => Leave::START,
        'end_date' => Leave::END,
        'reason' => 'Family',
    ];
    $days = Leave::days($f['employee']);

    test()->actingAs($f['person'])->postJson(Leave::url($f['tenant'], 'leave/request'), $body)->assertCreated();
    $balance = LeaveBalance::query()->where('employee_id', $f['employee']->id)->sole();
    expect((float) $balance->pending_days)->toBe($days);

    $second = test()->actingAs($f['person'])->postJson(Leave::url($f['tenant'], 'leave/request'), $body);
    $second->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');
    // The identical request is refused as an overlap with itself, not answered
    // with the request the first call created.
    $second->assertJsonPath('type', 'https://ethr.et/errors/overlap');

    expect(LeaveRequest::query()->where('employee_id', $f['employee']->id)->count())->toBe(1)
        ->and(Leave::figures($balance))->toBe(['used' => 0.0, 'pending' => $days, 'entitled' => 16.0])
        ->and(AuditLog::where('action', 'leave.requested')->count())->toBe(1);
});

it('takes the days once when approve is sent twice, today: the second approve is answered 422 invalid-state and the employee is told once', function () {
    Notification::fake();
    $f = Leave::tenant();
    $days = Leave::days($f['employee']);
    $balance = Leave::balance($f['employee'], $f['type'], pending: $days);
    $request = Leave::request($f['employee'], $f['type'], LeaveStatus::PENDING, $days);

    test()->actingAs($f['hr'])->putJson(Leave::url($f['tenant'], "leave/{$request->public_id}/approve"))->assertOk()->assertJsonPath('status', 'approved');
    test()->actingAs($f['hr'])->putJson(Leave::url($f['tenant'], "leave/{$request->public_id}/approve"))
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/invalid-state')
        ->assertJsonPath('detail', __('leave.not_pending'));

    expect($request->fresh()->status)->toBe(LeaveStatus::APPROVED)
        ->and(Leave::figures($balance))->toBe(['used' => $days, 'pending' => 0.0, 'entitled' => 16.0])
        ->and(AuditLog::where('action', 'leave.approved')->count())->toBe(1);
    Notification::assertSentToTimes($f['person'], LeaveApprovedNotification::class, 1);
});

it('releases the pending days once when reject is sent twice, today: the second reject is answered 422 invalid-state', function () {
    Notification::fake();
    $f = Leave::tenant();
    $days = Leave::days($f['employee']);
    $balance = Leave::balance($f['employee'], $f['type'], pending: $days + 2);
    $request = Leave::request($f['employee'], $f['type'], LeaveStatus::PENDING, $days);
    $url = Leave::url($f['tenant'], "leave/{$request->public_id}/reject");

    test()->actingAs($f['hr'])->putJson($url, ['reason' => 'Busy week'])->assertOk()->assertJsonPath('status', 'rejected');
    test()->actingAs($f['hr'])->putJson($url, ['reason' => 'Busy week'])->assertStatus(422)->assertJsonPath('type', 'https://ethr.et/errors/invalid-state');

    // Two days of pending belong to some other request: a double release would show.
    expect(Leave::figures($balance))->toBe(['used' => 0.0, 'pending' => 2.0, 'entitled' => 16.0])
        ->and(AuditLog::where('action', 'leave.rejected')->count())->toBe(1);
    Notification::assertSentToTimes($f['person'], LeaveRejectedNotification::class, 1);
});

it('does not approve a rejected request: reject then approve leaves the balance as the rejection left it', function () {
    Notification::fake();
    $f = Leave::tenant();
    $days = Leave::days($f['employee']);
    $balance = Leave::balance($f['employee'], $f['type'], pending: $days);
    $request = Leave::request($f['employee'], $f['type'], LeaveStatus::PENDING, $days);

    test()->actingAs($f['hr'])->putJson(Leave::url($f['tenant'], "leave/{$request->public_id}/reject"), ['reason' => 'No'])->assertOk();
    test()->actingAs($f['hr'])->putJson(Leave::url($f['tenant'], "leave/{$request->public_id}/approve"))
        ->assertStatus(422)
        ->assertJsonPath('detail', __('leave.not_pending'));

    expect($request->fresh()->status)->toBe(LeaveStatus::REJECTED)
        ->and(Leave::figures($balance))->toBe(['used' => 0.0, 'pending' => 0.0, 'entitled' => 16.0]);
    Notification::assertNotSentTo($f['person'], LeaveApprovedNotification::class);
});

it('gives the days back once when recall is sent twice, today: the second recall is answered 422 invalid-state', function () {
    Notification::fake();
    $f = Leave::tenant();
    $days = Leave::days($f['employee']);
    // Three days of used leave belong to something else: a double give-back would show.
    $balance = Leave::balance($f['employee'], $f['type'], used: $days + 3);
    $request = Leave::request($f['employee'], $f['type'], LeaveStatus::APPROVED, $days);
    $url = Leave::url($f['tenant'], "leave/{$request->public_id}/recall");

    test()->actingAs($f['hr'])->putJson($url, ['reason' => 'Trip called off'])->assertOk()->assertJsonPath('status', 'cancelled');
    test()->actingAs($f['hr'])->putJson($url, ['reason' => 'Trip called off'])
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/invalid-state')
        ->assertJsonPath('detail', __('leave.not_approved'));

    expect($request->fresh()->status)->toBe(LeaveStatus::CANCELLED)
        ->and(Leave::figures($balance))->toBe(['used' => 3.0, 'pending' => 0.0, 'entitled' => 16.0])
        ->and(AuditLog::where('action', 'leave.recalled')->count())->toBe(1);
    Notification::assertSentToTimes($f['person'], LeaveChangedNotification::class, 1);
});

it('gives back the days of a shortened leave once when the same early return is recalled twice, today: the second is a 422 validation error on end_date', function () {
    Notification::fake();
    $f = Leave::tenant();
    $days = Leave::days($f['employee']);
    $kept = Leave::days($f['employee'], Leave::START, '2026-12-08');
    $balance = Leave::balance($f['employee'], $f['type'], used: $days + 3);
    $request = Leave::request($f['employee'], $f['type'], LeaveStatus::APPROVED, $days);
    $url = Leave::url($f['tenant'], "leave/{$request->public_id}/recall");
    $body = ['end_date' => '2026-12-08', 'reason' => 'Came back early'];

    test()->actingAs($f['hr'])->putJson($url, $body)->assertOk();
    $second = test()->actingAs($f['hr'])->putJson($url, $body);

    // The leave now ends on the date the second call names, so the date is
    // refused as not being before the end of the leave.
    $second->assertStatus(422)->assertJsonValidationErrors(['end_date']);

    expect($request->fresh()->end_date->toDateString())->toBe('2026-12-08')
        ->and((float) $request->fresh()->days)->toBe($kept)
        ->and(Leave::figures($balance))->toBe(['used' => $kept + 3, 'pending' => 0.0, 'entitled' => 16.0])
        ->and(AuditLog::where('action', 'leave.recalled')->count())->toBe(1);
});

it('releases the pending days once when cancel is sent twice, today: the second cancel is answered 422 invalid-state', function () {
    $f = Leave::tenant();
    $days = Leave::days($f['employee']);
    $balance = Leave::balance($f['employee'], $f['type'], pending: $days + 2);
    $request = Leave::request($f['employee'], $f['type'], LeaveStatus::PENDING, $days);
    $url = Leave::url($f['tenant'], "leave/{$request->public_id}/cancel");

    test()->actingAs($f['person'])->putJson($url)->assertOk()->assertJsonPath('status', 'cancelled');
    test()->actingAs($f['person'])->putJson($url)
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/invalid-state')
        ->assertJsonPath('detail', __('leave.not_pending'));

    expect($request->fresh()->status)->toBe(LeaveStatus::CANCELLED)
        ->and(Leave::figures($balance))->toBe(['used' => 0.0, 'pending' => 2.0, 'entitled' => 16.0])
        ->and(AuditLog::where('action', 'leave.cancelled')->count())->toBe(1);
});
