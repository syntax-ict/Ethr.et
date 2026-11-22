<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceConflict;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\ConflictResolver;
use App\Services\CurrentTenant;
use Carbon\Carbon;

// A merge rewrites the survivor and voids the loser. On a day already paid in
// an approved run either one can be the record a payslip was paid from, and a
// device backlog synced after payday used to do exactly that.
afterEach(fn () => Carbon::setTestNow());

const PDM_DAY = '2026-10-12';

function pdmAt(string $time): Carbon
{
    return Carbon::parse(PDM_DAY.' '.$time, 'Africa/Addis_Ababa')->utc();
}

/** @return array{0: object, 1: Employee} */
function pdmTenant(?string $runStatus): array
{
    Carbon::setTestNow(pdmAt('20:00'));
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id]);
    app(CurrentTenant::class)->set($tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    if ($runStatus !== null) {
        pdmRun($tenant, $employee, $runStatus);
    }

    return [$tenant, $employee];
}

function pdmRun(object $tenant, Employee $employee, string $status): void
{
    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => $status,
    ]);
    PayrollEntry::factory()->create(['tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id]);
}

function pdmRecord(object $tenant, Employee $employee, AttendanceSource $source, string $in, int $confidence, ?string $out = null): AttendanceRecord
{
    return AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'date' => PDM_DAY,
        'source' => $source, 'check_in' => pdmAt($in), 'check_out' => $out ? pdmAt($out) : null,
        'confidence_score' => $confidence, 'status' => AttendanceStatus::PRESENT,
    ]);
}

function pdmSnapshot(AttendanceRecord $r): array
{
    $r = AttendanceRecord::withoutGlobalScopes()->findOrFail($r->id);

    return [$r->check_in->toIso8601String(), $r->check_out?->toIso8601String(), $r->status, $r->confidence_score, $r->metadata];
}

it('leaves a paid day alone when a stronger source arrives minutes later', function (string $status) {
    [$tenant, $employee] = pdmTenant($status);
    $old = pdmRecord($tenant, $employee, AttendanceSource::WEB, '08:30', 75, '17:00');
    $before = pdmSnapshot($old);
    $new = pdmRecord($tenant, $employee, AttendanceSource::BIOMETRIC, '08:32', 100);

    $resolution = app(ConflictResolver::class)->resolve($new);

    expect($resolution->action)->toBe('kept_paid')
        ->and($resolution->record->is($old))->toBeTrue()
        ->and(pdmSnapshot($old))->toBe($before);
    $voided = $new->fresh();
    expect($voided->status)->toBe(AttendanceStatus::VOIDED)
        ->and($voided->metadata['voided_reason'])->toBe('paid_day_duplicate')
        ->and($voided->metadata['duplicate_of'])->toBe($old->public_id)
        ->and($voided->metadata)->toHaveKey('voided_at')
        ->and(AttendanceConflict::query()->count())->toBe(0);
})->with(['approved', 'paid']);

it('leaves a paid day alone for the same source within a minute', function () {
    [$tenant, $employee] = pdmTenant('approved');
    $old = pdmRecord($tenant, $employee, AttendanceSource::WEB, '08:30', 75);
    $before = pdmSnapshot($old);
    $new = pdmRecord($tenant, $employee, AttendanceSource::WEB, '08:30', 90);

    $resolution = app(ConflictResolver::class)->resolve($new);

    expect($resolution->action)->toBe('kept_paid')
        ->and(pdmSnapshot($old))->toBe($before)
        ->and($new->fresh()->status)->toBe(AttendanceStatus::VOIDED)
        ->and($new->fresh()->metadata['voided_reason'])->toBe('paid_day_duplicate');
});

it('still keeps both and flags a far second punch on a paid day', function () {
    [$tenant, $employee] = pdmTenant('approved');
    $old = pdmRecord($tenant, $employee, AttendanceSource::WEB, '08:30', 75, '12:00');
    $before = pdmSnapshot($old);
    $new = pdmRecord($tenant, $employee, AttendanceSource::BIOMETRIC, '13:00', 100);

    expect(app(ConflictResolver::class)->resolve($new)->action)->toBe('flagged')
        ->and(pdmSnapshot($old))->toBe($before)
        ->and($new->fresh()->status)->not->toBe(AttendanceStatus::VOIDED)
        ->and(AttendanceConflict::query()->count())->toBe(1);
});

it('merges as before when the run is only completed or there is no run', function (?string $status) {
    [$tenant, $employee] = pdmTenant($status);
    $old = pdmRecord($tenant, $employee, AttendanceSource::WEB, '08:30', 75);
    $new = pdmRecord($tenant, $employee, AttendanceSource::BIOMETRIC, '08:32', 100);

    $resolution = app(ConflictResolver::class)->resolve($new);

    expect($resolution->action)->toBe('merged')
        ->and($old->fresh()->status)->toBe(AttendanceStatus::VOIDED)
        ->and($new->fresh()->status)->not->toBe(AttendanceStatus::VOIDED);
})->with(['completed', null]);

it('is not locked by another organisation approved run', function () {
    [$tenant, $employee] = pdmTenant(null);
    $other = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    $otherEmployee = Employee::factory()->create(['tenant_id' => $other->id]);
    pdmRun($other, $otherEmployee, 'approved');
    app(CurrentTenant::class)->set($tenant);

    $old = pdmRecord($tenant, $employee, AttendanceSource::WEB, '08:30', 75);
    $new = pdmRecord($tenant, $employee, AttendanceSource::BIOMETRIC, '08:32', 100);

    expect(app(ConflictResolver::class)->resolve($new)->action)->toBe('merged')
        ->and($old->fresh()->status)->toBe(AttendanceStatus::VOIDED);
});

it('answers a device punch on a paid day as already checked in and announces nothing', function () {
    [$tenant, $employee] = pdmTenant('approved');
    Webhook::factory()->create([
        'tenant_id' => $tenant->id, 'url' => 'https://hooks.example.et/attendance',
        'events' => ['attendance.checked_in'], 'is_active' => true,
    ]);
    $old = pdmRecord($tenant, $employee, AttendanceSource::WEB, '08:30', 75, '17:00');
    $before = pdmSnapshot($old);

    $result = app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: AttendanceSource::BIOMETRIC,
        type: 'check_in',
        idempotencyKey: 'pdm-'.uniqid(),
        occurredAt: pdmAt('08:32')->toIso8601String(),
    ));

    expect($result->wasDuplicate)->toBeTrue()
        ->and($result->record->id)->toBe($old->id)
        ->and($result->outcome->code)->toBe('already_checked_in')
        ->and(pdmSnapshot($old))->toBe($before)
        ->and(WebhookDelivery::withoutGlobalScopes()->where('event', 'attendance.checked_in')->count())->toBe(0)
        ->and(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->where('status', '!=', AttendanceStatus::VOIDED)->count())->toBe(1);
});
