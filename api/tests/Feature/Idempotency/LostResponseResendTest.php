<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use Tests\Support\IdempotencyFixtures as Fx;
use Tests\Support\IdempotencyLeaveFixtures as Leave;

/*
 * A write re-sent after its response was lost. Payroll approve, mark paid and
 * void, and every leave write, carry no idempotency key: a client that sends
 * an `Idempotency-Key` header gets exactly what one that does not gets. What
 * the client receives on the re-send is what these tests read.
 */

it('answers a re-sent payroll approve with an error whatever Idempotency-Key it carries, today: the client recovers by reading the run', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    Fx::employeeWithBalances($tenant);
    $run = Fx::processRun($tenant, $user->id);
    $header = ['Idempotency-Key' => 'lost-response-approve'];

    test()->putJson(Fx::runUrl($tenant, $run, 'approve'), [], $header)->assertOk();

    // The response was lost; the client sends the same request with the same key.
    test()->putJson(Fx::runUrl($tenant, $run, 'approve'), [], $header)
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/invalid-state');

    // The only way to learn the first call worked is to read the run.
    test()->getJson(Fx::apiUrl($tenant, "payroll/runs/{$run->public_id}"))->assertOk()->assertJsonPath('status', 'approved');
});

it('answers a re-sent payroll mark paid and void with an error whatever Idempotency-Key they carry', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    Fx::employeeWithBalances($tenant);
    $paidRun = Fx::processRun($tenant, $user->id);
    test()->putJson(Fx::runUrl($tenant, $paidRun, 'approve'))->assertOk();
    $header = ['Idempotency-Key' => 'lost-response-paid'];
    $paid = ['paid_on' => '2026-07-02', 'payment_reference' => 'BANK-REF-1'];

    test()->putJson(Fx::runUrl($tenant, $paidRun, 'paid'), $paid, $header)->assertOk();
    test()->putJson(Fx::runUrl($tenant, $paidRun, 'paid'), $paid, $header)->assertStatus(422);
    // A paid run cannot be voided at all, so a void sent after a lost mark-paid is refused with its own reason.
    test()->postJson(Fx::runUrl($tenant, $paidRun, 'void'), ['reason' => 'x'], $header)
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/payroll-run-paid');

    expect($paidRun->fresh()->status)->toBe('paid')
        ->and($paidRun->fresh()->payment_reference)->toBe('BANK-REF-1');
});

it('answers a re-sent payroll process with the first run when the idempotency key repeats: the one route family that is safe to re-send', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    Fx::employeeWithBalances($tenant);
    $body = ['period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'idempotency_key' => 'lost-response-process'];

    $first = test()->postJson(Fx::apiUrl($tenant, 'payroll/process'), $body)->assertStatus(202);
    $second = test()->postJson(Fx::apiUrl($tenant, 'payroll/process'), $body)->assertOk()->assertJsonPath('was_duplicate', true);

    expect($second->json('id'))->toBe($first->json('id'))
        ->and(PayrollRun::query()->count())->toBe(1);
});

it('answers a re-sent leave request with an overlap error whatever Idempotency-Key it carries, today: the client recovers by listing its own requests', function () {
    $f = Leave::tenant();
    $body = [
        'leave_type_public_id' => $f['type']->public_id,
        'start_date' => Leave::START,
        'end_date' => Leave::END,
        'reason' => 'Family',
    ];
    $header = ['Idempotency-Key' => 'lost-response-leave'];

    $first = test()->actingAs($f['person'])->postJson(Leave::url($f['tenant'], 'leave/request'), $body, $header)->assertCreated();
    test()->actingAs($f['person'])->postJson(Leave::url($f['tenant'], 'leave/request'), $body, $header)
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/overlap');

    $mine = test()->actingAs($f['person'])->getJson(Leave::url($f['tenant'], 'leave/my'))->assertOk();
    expect($mine->json('data'))->toHaveCount(1)
        ->and($mine->json('data.0.id'))->toBe($first->json('id'));
});

it('answers a re-sent leave decision with an error whatever Idempotency-Key it carries, today: approve and reject are not repeatable', function () {
    $f = Leave::tenant();
    $days = Leave::days($f['employee']);
    Leave::balance($f['employee'], $f['type'], pending: $days);
    $request = Leave::request($f['employee'], $f['type'], LeaveStatus::PENDING, $days);
    $header = ['Idempotency-Key' => 'lost-response-decision'];
    $url = Leave::url($f['tenant'], "leave/{$request->public_id}/approve");

    test()->actingAs($f['hr'])->putJson($url, [], $header)->assertOk();
    test()->actingAs($f['hr'])->putJson($url, [], $header)->assertStatus(422)->assertJsonPath('type', 'https://ethr.et/errors/invalid-state');

    expect(LeaveRequest::query()->whereKey($request->id)->value('status'))->toBe(LeaveStatus::APPROVED);
});
