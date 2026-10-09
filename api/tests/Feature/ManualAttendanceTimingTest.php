<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceConflict;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\CurrentTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
 * A manual entry is checked at the moment HR entered, not the moment HR pressed
 * Save. Until 2026-10-09 the engine stamped the punch "now", matched shifts and
 * looked for clashes there, and the controller moved it to the entered date
 * afterwards. A backdated entry was compared with today's punches, and could
 * merge into one and drag it to the wrong day. A real clash on the entered day
 * was never seen.
 *
 * "Now" is fixed at 2026-10-09 10:00 in Addis Ababa (07:00 UTC).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 07:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/** @return array{tenant: Tenant, employee: Employee} */
function manualTimingSetup(): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    return compact('tenant', 'employee');
}

/** A punch already on record, from another source, at a UTC instant. */
function manualTimingExistingPunch(Tenant $tenant, Employee $employee, string $utc): AttendanceRecord
{
    app(CurrentTenant::class)->set($tenant);

    return app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: AttendanceSource::WEB,
        type: 'check_in',
        idempotencyKey: 'existing-'.Str::uuid(),
        occurredAt: $utc,
    ))->record;
}

function manualTimingPost(Tenant $tenant, Employee $employee, string $date, string $in, ?string $out = null)
{
    return test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/manual", array_filter([
        'idempotency_key' => 'manual-'.Str::uuid(),
        'employee_public_id' => $employee->public_id,
        'date' => $date,
        'check_in' => $in,
        'check_out' => $out,
        'reason' => 'Paper sheet',
    ]));
}

it('leaves today\'s punch alone when HR backdates an entry', function () {
    ['tenant' => $tenant, 'employee' => $employee] = manualTimingSetup();

    // Checked in two minutes before HR pressed Save: close enough that a
    // comparison at "now" merged the two.
    $today = manualTimingExistingPunch($tenant, $employee, '2026-10-09T06:58:00Z');

    manualTimingPost($tenant, $employee, '2026-10-08', '08:00', '17:00')->assertCreated();

    $today->refresh();
    expect($today->status)->not->toBe(AttendanceStatus::VOIDED)
        ->and($today->date->format('Y-m-d'))->toBe('2026-10-09')
        ->and(AttendanceConflict::count())->toBe(0);

    $yesterday = AttendanceRecord::where('employee_id', $employee->id)->whereDate('date', '2026-10-08')->sole();
    expect($yesterday->check_in->utc()->format('Y-m-d H:i'))->toBe('2026-10-08 05:00')
        ->and($yesterday->check_out->utc()->format('Y-m-d H:i'))->toBe('2026-10-08 14:00');
});

it('flags a clash with a punch already on the entered day', function () {
    ['tenant' => $tenant, 'employee' => $employee] = manualTimingSetup();

    $existing = manualTimingExistingPunch($tenant, $employee, '2026-10-08T05:00:00Z'); // 08:00 local

    manualTimingPost($tenant, $employee, '2026-10-08', '10:00')->assertCreated(); // two hours later

    $conflict = AttendanceConflict::sole();
    expect($conflict->record_a_id)->toBe($existing->id)
        ->and($conflict->employee_id)->toBe($employee->id);
});

it('merges with a punch minutes away and keeps its check-out', function () {
    ['tenant' => $tenant, 'employee' => $employee] = manualTimingSetup();

    $existing = manualTimingExistingPunch($tenant, $employee, '2026-10-08T05:00:00Z');
    $existing->update(['check_out' => Carbon::parse('2026-10-08T14:00:00Z')]);

    // 08:02 local, no check-out entered.
    manualTimingPost($tenant, $employee, '2026-10-08', '08:02')->assertCreated();

    $live = AttendanceRecord::where('employee_id', $employee->id)
        ->where('status', '!=', AttendanceStatus::VOIDED)
        ->sole();

    expect($live->check_in->utc()->format('H:i'))->toBe('05:00')
        ->and($live->check_out->utc()->format('Y-m-d H:i'))->toBe('2026-10-08 14:00')
        ->and(AttendanceConflict::count())->toBe(0);
});
