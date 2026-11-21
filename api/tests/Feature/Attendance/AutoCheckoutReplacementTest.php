<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\CorrectionStatus;
use App\Enums\UserRole;
use App\Exceptions\NoOpenCheckIn;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceInboundPunch;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\AttendanceResult;
use App\Services\CurrentTenant;
use App\Services\Payroll\AttendanceReconciliation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Subsystem integration review 2026-11-20, finding 4. Automatic check-out
// closes a day left open at the shift's end. When the person's own check-out
// then arrived (they stayed late, a terminal synced a backlog) there was no
// open record: a directed check-out was refused and an `auto` punch opened a
// stray check-in, so the hours and overtime actually worked were lost.

const ACR_DAY = '2026-10-12';

function acrAt(string $time, string $day = ACR_DAY): Carbon
{
    return Carbon::parse("{$day} {$time}", 'Africa/Addis_Ababa')->utc();
}

/** @return array{0: Tenant, 1: Employee, 2: AttendanceRecord} the day, closed by the command at 17:30 */
function acrAutoClosed(array $record = []): array
{
    Carbon::setTestNow(acrAt('20:30'));
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id, 'auto_checkout_enabled' => true]);
    app(CurrentTenant::class)->set($tenant);
    $shift = Shift::factory()->default()->create(['tenant_id' => $tenant->id, 'start_time' => '08:30', 'end_time' => '17:30']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $created = AttendanceRecord::factory()->create($record + [
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'shift_id' => $shift->id,
        'date' => ACR_DAY, 'status' => AttendanceStatus::PRESENT,
        'check_in' => acrAt('08:25'), 'check_out' => null,
    ]);

    test()->artisan('attendance:auto-checkout')->assertSuccessful();
    // The command forgets the organisation it served.
    app(CurrentTenant::class)->set($tenant);

    return [$tenant, $employee, $created->fresh()];
}

function acrPunch(Tenant $tenant, Employee $employee, string $type, string $time, ?string $key = null, string $day = ACR_DAY): AttendanceResult
{
    return app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: AttendanceSource::MOBILE,
        type: $type,
        idempotencyKey: $key ?? 'acr-'.uniqid(),
        occurredAt: acrAt($time, $day)->toIso8601String(),
    ));
}

afterEach(fn () => Carbon::setTestNow());

it('starts from a record the command really closed at the shift end', function () {
    [, , $record] = acrAutoClosed();

    expect($record->check_out->equalTo(acrAt('17:30')))->toBeTrue()
        ->and($record->metadata['auto_checkout'])->toBeTrue();
});

it('replaces the automatic check-out with a later check-out of the person', function () {
    [$tenant, $employee, $record] = acrAutoClosed();
    $automaticAt = $record->metadata['auto_checkout_at'];

    $result = acrPunch($tenant, $employee, 'check_out', '19:00');

    $fresh = $record->fresh();
    expect($result->wasDuplicate)->toBeFalse()
        ->and($fresh->check_out->equalTo(acrAt('19:00')))->toBeTrue()
        ->and(array_key_exists('auto_checkout', $fresh->metadata))->toBeFalse()
        ->and(array_key_exists('auto_checkout_at', $fresh->metadata))->toBeFalse()
        ->and(array_keys($fresh->metadata['auto_checkout_replaced']))->toEqualCanonicalizing(['shift_end', 'auto_checkout_at', 'replaced_at'])
        ->and($fresh->metadata['auto_checkout_replaced']['auto_checkout_at'])->toBe($automaticAt)
        ->and(Carbon::parse($fresh->metadata['auto_checkout_replaced']['shift_end'])->equalTo(acrAt('17:30')))->toBeTrue()
        ->and(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'attendance.auto_checkout_replaced')->count())->toBe(1);

    $audit = AuditLog::query()->where('action', 'attendance.auto_checkout_replaced')->sole();
    expect(Carbon::parse($audit->payload['automatic_check_out'])->equalTo(acrAt('17:30')))->toBeTrue()
        ->and(Carbon::parse($audit->payload['check_out'])->equalTo(acrAt('19:00')))->toBeTrue();
});

it('no longer lists the day as an automatic check-out in the reconciliation', function () {
    [$tenant, $employee, $record] = acrAutoClosed();
    $listed = fn (): array => collect(app(AttendanceReconciliation::class)->forEmployee($tenant, $employee, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')))
        ->pluck('type')->all();

    expect($listed())->toContain('auto_checkout');

    acrPunch($tenant, $employee, 'check_out', '19:00');

    expect($listed())->not->toContain('auto_checkout');
});

it('replaces the automatic check-out with an earlier one and rates it as an early leave', function () {
    [$tenant, $employee, $record] = acrAutoClosed();

    acrPunch($tenant, $employee, 'check_out', '17:10');

    $fresh = $record->fresh();
    expect($fresh->check_out->equalTo(acrAt('17:10')))->toBeTrue()
        ->and($fresh->status)->toBe(AttendanceStatus::EARLY_LEAVE)
        ->and(isset($fresh->metadata['auto_checkout']))->toBeFalse()
        ->and(isset($fresh->metadata['auto_checkout_replaced']))->toBeTrue();
});

it('lets an undirected punch close the automatic check-out instead of opening a stray check-in', function () {
    [$tenant, $employee, $record] = acrAutoClosed();

    $result = acrPunch($tenant, $employee, 'auto', '19:00');

    expect($result->wasDuplicate)->toBeFalse()
        ->and($record->fresh()->check_out->equalTo(acrAt('19:00')))->toBeTrue()
        ->and(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(1);
});

it('treats an undirected punch past the close window as an arrival, as before', function () {
    [$tenant, $employee, $record] = acrAutoClosed();

    // 17:30 + 6 hours is 23:30.
    acrPunch($tenant, $employee, 'auto', '23:45');

    $fresh = $record->fresh();
    expect($fresh->check_out->equalTo(acrAt('17:30')))->toBeTrue()
        ->and($fresh->metadata['auto_checkout'])->toBeTrue()
        ->and(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBeGreaterThan(1);
});

it('lets a directed check-out replace it however late, within the open-hours rule', function () {
    [$tenant, $employee, $record] = acrAutoClosed();

    acrPunch($tenant, $employee, 'check_out', '23:45');

    expect($record->fresh()->check_out->equalTo(acrAt('23:45')))->toBeTrue();
});

it('replaces it when the punch is a device backlog received the next morning', function () {
    [$tenant, $employee, $record] = acrAutoClosed();
    Carbon::setTestNow(acrAt('09:00', '2026-10-13'));

    acrPunch($tenant, $employee, 'check_out', '19:00');

    expect($record->fresh()->check_out->equalTo(acrAt('19:00')))->toBeTrue()
        ->and(isset($record->fresh()->metadata['auto_checkout']))->toBeFalse();
});

it('never reopens a record a person corrected', function () {
    [$tenant, $employee, $record] = acrAutoClosed();
    $record->update(['metadata' => [...$record->metadata, 'is_corrected' => true]]);

    expect(fn () => acrPunch($tenant, $employee, 'check_out', '19:00'))->toThrow(NoOpenCheckIn::class);

    $fresh = $record->fresh();
    expect($fresh->check_out->equalTo(acrAt('17:30')))->toBeTrue()
        ->and($fresh->metadata['auto_checkout'])->toBeTrue();
});

it('does not rewrite a day already paid in an approved run', function () {
    [$tenant, $employee, $record] = acrAutoClosed();
    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'approved',
    ]);
    PayrollEntry::factory()->create(['tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id]);

    expect(fn () => acrPunch($tenant, $employee, 'check_out', '19:00'))->toThrow(NoOpenCheckIn::class);

    expect($record->fresh()->check_out->equalTo(acrAt('17:30')))->toBeTrue();
});

it('does not give the punch to the automatic check-out when a later record of the person exists', function () {
    [$tenant, $employee, $record] = acrAutoClosed();
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'date' => ACR_DAY,
        'check_in' => acrAt('18:00'), 'check_out' => acrAt('18:20'),
    ]);

    expect(fn () => acrPunch($tenant, $employee, 'check_out', '19:00'))->toThrow(NoOpenCheckIn::class);

    expect($record->fresh()->check_out->equalTo(acrAt('17:30')))->toBeTrue();
});

it('lets a genuinely open record win over an automatic check-out', function () {
    [$tenant, $employee, $record] = acrAutoClosed();
    $second = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'date' => ACR_DAY,
        'check_in' => acrAt('18:00'), 'check_out' => null,
    ]);

    acrPunch($tenant, $employee, 'check_out', '19:00');

    expect($second->fresh()->check_out->equalTo(acrAt('19:00')))->toBeTrue()
        ->and($record->fresh()->check_out->equalTo(acrAt('17:30')))->toBeTrue();
});

it('answers a replay of the same check-out as a duplicate, and changes nothing twice', function () {
    [$tenant, $employee, $record] = acrAutoClosed();

    acrPunch($tenant, $employee, 'check_out', '19:00', 'acr-same');
    $again = acrPunch($tenant, $employee, 'check_out', '19:00', 'acr-same');

    expect($again->wasDuplicate)->toBeTrue()
        ->and($record->fresh()->check_out->equalTo(acrAt('19:00')))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'attendance.auto_checkout_replaced')->count())->toBe(1);

    // A different check-out after the replacement behaves as after any real one.
    expect(fn () => acrPunch($tenant, $employee, 'check_out', '21:00', 'acr-other'))->toThrow(NoOpenCheckIn::class);
});

it('keeps a pending correction pending, and its approval still applies its times', function () {
    [$tenant, $employee, $record] = acrAutoClosed();
    $correction = AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id, 'attendance_record_id' => $record->id, 'employee_id' => $employee->id,
        'proposed_check_in' => null, 'proposed_check_out' => acrAt('18:45'), 'status' => CorrectionStatus::PENDING,
    ]);

    acrPunch($tenant, $employee, 'check_out', '19:00');

    expect($record->fresh()->check_out->equalTo(acrAt('19:00')))->toBeTrue()
        ->and($correction->fresh()->status)->toBe(CorrectionStatus::PENDING);

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/{$correction->public_id}/approve")->assertOk();

    $fresh = $record->fresh();
    expect($fresh->check_out->equalTo(acrAt('18:45')))->toBeTrue()
        ->and($fresh->metadata['is_corrected'])->toBeTrue();
});

it('ends a device punch through the inbox as the replaced check-out, not a quarantined one', function () {
    [$tenant, $employee, $record] = acrAutoClosed();
    $employee->update(['badge_number' => '1001']);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Device::factory()->hikvision()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'connection_config' => ['mode' => 'push'], 'webhook_token' => 'acr-token',
    ]);

    test()->withHeader('X-Webhook-Token', 'acr-token')
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/hikvision", [
            'dateTime' => ACR_DAY.'T19:00:00+03:00',
            'eventType' => 'AccessControllerEvent',
            'AccessControllerEvent' => [
                'majorEventType' => 5, 'subEventType' => 75,
                'employeeNoString' => '1001', 'attendanceStatus' => 'checkOut',
            ],
        ])->assertOk();

    expect(DeviceInboundPunch::withoutGlobalScopes()->whereNotNull('failed_at')->count())->toBe(0)
        ->and($record->fresh()->check_out->equalTo(acrAt('19:00')))->toBeTrue()
        ->and(isset($record->fresh()->metadata['auto_checkout_replaced']))->toBeTrue();
});

it('does not overwrite a real check-out that lands between the command loading a record and closing it', function () {
    Carbon::setTestNow(acrAt('20:30'));
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id, 'auto_checkout_enabled' => true]);
    app(CurrentTenant::class)->set($tenant);
    $shift = Shift::factory()->default()->create(['tenant_id' => $tenant->id, 'start_time' => '08:30', 'end_time' => '17:30']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'shift_id' => $shift->id,
        'date' => ACR_DAY, 'status' => AttendanceStatus::PRESENT, 'check_in' => acrAt('08:25'), 'check_out' => null,
    ]);

    // The person's own check-out is written the moment the command has loaded the
    // open record, so the command holds a stale copy.
    $landed = false;
    AttendanceRecord::retrieved(function (AttendanceRecord $loaded) use (&$landed, $record): void {
        if ($landed || $loaded->id !== $record->id) {
            return;
        }
        $landed = true;
        DB::table('attendance_records')->where('id', $record->id)->update(['check_out' => acrAt('19:00')]);
    });

    test()->artisan('attendance:auto-checkout')->assertSuccessful();
    app(CurrentTenant::class)->set($tenant);

    $fresh = AttendanceRecord::query()->whereKey($record->id)->sole();
    expect($landed)->toBeTrue()
        ->and($fresh->check_out->equalTo(acrAt('19:00')))->toBeTrue()
        ->and(isset(($fresh->metadata ?? [])['auto_checkout']))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'attendance.auto_checkout')->count())->toBe(0)
        ->and(DB::table('notifications')->count())->toBe(0);
});
