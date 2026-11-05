<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;

// A-Z review D-10 (2026-11-04). A punch that failed for a reason other than a
// refusal showed the person the technical error text. Refusals are written
// for the person punching and still say why; anything else is reported and
// answered with a plain sentence.

function punchErrorSetUp(): array
{
    Exceptions::fake();
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));
    AttendanceRecord::creating(function () {
        throw new QueryException('mysql', 'insert into `attendance_records` (`tenant_id`) values (?)', [7], new PDOException('Deadlock found'));
    });

    return [$tenant, $employee];
}

function punchErrorUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

it('tells the phone a punch failed without the error\'s own text', function () {
    [$tenant] = punchErrorSetUp();

    $detail = test()->postJson(punchErrorUrl($tenant, '/attendance/mobile/check-in'), [
        'idempotency_key' => (string) Str::uuid(),
        'latitude' => 9.0192,
        'longitude' => 38.7525,
    ])->assertStatus(422)->json('detail');

    expect($detail)->toBe(__('attendance.punch_failed'));
    Exceptions::assertReported(QueryException::class);
});

it('tells an offline phone a punch failed without the error\'s own text', function () {
    [$tenant, $employee] = punchErrorSetUp();

    $result = test()->postJson(punchErrorUrl($tenant, '/attendance/sync'), [
        'records' => [[
            'idempotency_key' => 'offline-error-1',
            'employee_public_id' => $employee->public_id,
            'type' => 'check_in',
            'timestamp' => now()->subHour()->toIso8601String(),
            'latitude' => 9.0,
            'longitude' => 38.0,
            'offline_token' => hash('sha256', 'offline-error-1'),
        ]],
    ])->assertOk()->json('results.0');

    expect($result['status'])->toBe('error')
        ->and($result['detail'])->toBe(__('attendance.punch_failed'));
    Exceptions::assertReported(QueryException::class);
});
