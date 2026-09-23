<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;

/**
 * Two attendance reads authorised on `attendance.view` alone.
 *
 * That ability is in PermissionSeeder's $everyone grant list, so it answers
 * "may you read attendance" and never "may you read THIS employee's attendance".
 * Both endpoints are keyed on a ULID rather than a sequential id, so neither was
 * enumerable — but neither applied the orgScope()/canAccessEmployee() check that
 * every sibling attendance read applies through scopeAccessibleEmployees(), and
 * an identifier obtained legitimately (the tenant-wide reporting tree hands out
 * every employee's public_id to any employee.viewAny holder) was enough.
 *
 * Each test below fails with a 200 before the fix. See BASELINE.md §12i.
 */
// ── GET /attendance/{attendanceRecord} ──

test('an employee cannot read a colleague\'s attendance record', function () {
    $tenant = createTenant();

    $me = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $colleague = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $user = createUser(
        ['role' => UserRole::EMPLOYEE, 'employee_id' => $me->id],
        $tenant,
    );

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $colleague->id,
    ]);

    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/{$record->public_id}")
        ->assertForbidden();
});

test('an employee can still read their own attendance record', function () {
    $tenant = createTenant();

    $me = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(
        ['role' => UserRole::EMPLOYEE, 'employee_id' => $me->id],
        $tenant,
    );

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $me->id,
    ]);

    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/{$record->public_id}")
        ->assertOk()
        ->assertJsonPath('public_id', $record->public_id);
});

// ── GET /employees/{employee}/attendance/timeline ──

test('a supervisor cannot read the attendance timeline of someone who is not their report', function () {
    $tenant = createTenant();

    $boss = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $stranger = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => null,
    ]);

    $user = createUser(
        ['role' => UserRole::SUPERVISOR, 'employee_id' => $boss->id],
        $tenant,
    );

    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$stranger->public_id}/attendance/timeline")
        ->assertForbidden();
});

test('a supervisor can still read the attendance timeline of a direct report', function () {
    $tenant = createTenant();

    $boss = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $report = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $boss->id,
    ]);

    $user = createUser(
        ['role' => UserRole::SUPERVISOR, 'employee_id' => $boss->id],
        $tenant,
    );

    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$report->public_id}/attendance/timeline")
        ->assertOk();
});

// ── The permission alone is not the control ──

test('attendance.view is granted to every role, so it cannot be the scope check', function () {
    $tenant = createTenant();

    $me = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(
        ['role' => UserRole::EMPLOYEE, 'employee_id' => $me->id],
        $tenant,
    );

    // The premise of both fixes: the lowest role in the product holds the
    // ability the two endpoints used to authorise on. If this ever goes false,
    // the endpoints are still correct but this file's reasoning is stale.
    expect($user->hasPermission('attendance.view'))->toBeTrue();
});
