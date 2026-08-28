<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

function engine(): AttendanceEngine
{
    return app(AttendanceEngine::class);
}

describe('attendance event time', function () {
    it('dates a backlogged punch at the event time, not ingestion time', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');

        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        // A biometric event that happened three days ago, ingested only now.
        engine()->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $tenant->id,
            source: AttendanceSource::BIOMETRIC,
            type: 'check_in',
            idempotencyKey: 'backlog-1',
            occurredAt: '2026-07-28 08:15:00',
        ));

        $record = AttendanceRecord::where('tenant_id', $tenant->id)->firstOrFail();

        expect($record->date->format('Y-m-d'))->toBe('2026-07-28');
        expect(Carbon::parse($record->check_in)->format('Y-m-d H:i'))->toBe('2026-07-28 08:15');
    });

    it('falls back to now when no event time is supplied', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');

        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        engine()->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $tenant->id,
            source: AttendanceSource::WEB,
            type: 'check_in',
            idempotencyKey: 'live-1',
        ));

        $record = AttendanceRecord::where('tenant_id', $tenant->id)->firstOrFail();

        expect($record->date->format('Y-m-d'))->toBe('2026-07-31');
        expect(Carbon::parse($record->check_in)->format('H:i'))->toBe('09:00');
    });

    it('matches a backlogged check-out to the check-in of the same event day', function () {
        Carbon::setTestNow('2026-07-31 20:00:00');

        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        engine()->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $tenant->id,
            source: AttendanceSource::BIOMETRIC,
            type: 'check_in',
            idempotencyKey: 'day-in',
            occurredAt: '2026-07-28 08:00:00',
        ));

        engine()->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $tenant->id,
            source: AttendanceSource::BIOMETRIC,
            type: 'check_out',
            idempotencyKey: 'day-out',
            occurredAt: '2026-07-28 17:30:00',
        ));

        $record = AttendanceRecord::where('tenant_id', $tenant->id)
            ->whereDate('date', '2026-07-28')
            ->firstOrFail();

        expect($record->check_out)->not->toBeNull();
        expect(Carbon::parse($record->check_out)->format('Y-m-d H:i'))->toBe('2026-07-28 17:30');
    });
});

describe('offline sync uses the client punch time', function () {
    it('records offline punches at their captured timestamp', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');

        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
        test()->actingAs($user);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/sync", [
            'records' => [[
                'idempotency_key' => 'off-1',
                'employee_public_id' => $employee->public_id,
                'type' => 'check_in',
                'timestamp' => '2026-07-30 07:45:00',
                'offline_token' => 'tok-1',
            ]],
        ])->assertOk();

        $record = AttendanceRecord::where('tenant_id', $tenant->id)->firstOrFail();

        expect($record->date->format('Y-m-d'))->toBe('2026-07-30');
        expect(Carbon::parse($record->check_in)->format('Y-m-d H:i'))->toBe('2026-07-30 07:45');
    });
});
