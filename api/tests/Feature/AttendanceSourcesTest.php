<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;

// ── Manual Attendance Entry ──

test('hr admin can create manual attendance', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/manual", [
        'idempotency_key' => 'manual-001',
        'employee_public_id' => $employee->public_id,
        'date' => now()->format('Y-m-d'),
        'check_in' => '08:30',
        'check_out' => '17:30',
        'reason' => 'Employee forgot to clock in',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('source', 'manual')
        ->assertJsonMissingPath('id');
});

test('manual attendance requires reason', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/manual", [
        'idempotency_key' => 'manual-002',
        'employee_public_id' => $employee->public_id,
        'date' => now()->format('Y-m-d'),
        'check_in' => '08:30',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

test('employee cannot create manual attendance', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $target = Employee::factory()->create(['tenant_id' => $tenant->id]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/manual", [
        'idempotency_key' => 'manual-003',
        'employee_public_id' => $target->public_id,
        'date' => now()->format('Y-m-d'),
        'check_in' => '08:30',
        'reason' => 'Test',
    ])->assertForbidden();
});

test('supervisor cannot create manual attendance', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $target = Employee::factory()->create(['tenant_id' => $tenant->id]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/manual", [
        'idempotency_key' => 'manual-004',
        'employee_public_id' => $target->public_id,
        'date' => now()->format('Y-m-d'),
        'check_in' => '08:30',
        'reason' => 'Test',
    ])->assertForbidden();
});

test('manual attendance is audit logged', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/manual", [
        'idempotency_key' => 'manual-audit-001',
        'employee_public_id' => $employee->public_id,
        'date' => now()->format('Y-m-d'),
        'check_in' => '08:30',
        'reason' => 'Device malfunction',
    ])->assertStatus(201);

    $this->assertDatabaseHas('audit_log', [
        'action' => 'attendance.manual_entry',
    ]);
});

test('manual attendance idempotency returns duplicate', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $payload = [
        'idempotency_key' => 'manual-dup-001',
        'employee_public_id' => $employee->public_id,
        'date' => now()->format('Y-m-d'),
        'check_in' => '08:30',
        'reason' => 'Test',
    ];

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/manual", $payload)
        ->assertStatus(201);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/manual", $payload)
        ->assertStatus(200)
        ->assertJsonPath('was_duplicate', true);
});

test('manual attendance has confidence score 60', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/manual", [
        'idempotency_key' => 'manual-conf-001',
        'employee_public_id' => $employee->public_id,
        'date' => now()->format('Y-m-d'),
        'check_in' => '08:30',
        'reason' => 'Test',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('confidence_score', 60);
});

// ── Kiosk Attendance ──

test('employee can check in via kiosk', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP001']);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/kiosk", [
        'idempotency_key' => 'kiosk-001',
        'employee_code' => 'EMP001',
        'type' => 'check_in',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('source', 'kiosk')
        ->assertJsonMissingPath('id');
});

test('kiosk returns 404 for unknown employee code', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/kiosk", [
        'idempotency_key' => 'kiosk-404',
        'employee_code' => 'NONEXISTENT',
        'type' => 'check_in',
    ])->assertStatus(404);
});

test('kiosk attendance has confidence score 80', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP002']);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/kiosk", [
        'idempotency_key' => 'kiosk-conf-001',
        'employee_code' => 'EMP002',
        'type' => 'check_in',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('confidence_score', 80);
});

test('kiosk check-out works after check-in', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP003']);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    AttendanceRecord::factory()->checkInOnly()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => now()->format('Y-m-d'),
        'check_in' => now()->subHours(4),
        'source' => AttendanceSource::KIOSK,
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/kiosk", [
        'idempotency_key' => 'kiosk-co-001',
        'employee_code' => 'EMP003',
        'type' => 'check_out',
    ]);

    $response->assertOk();
    $record = AttendanceRecord::where('employee_id', $employee->id)->latest('id')->first();
    expect($record->check_out)->not->toBeNull();
});

test('kiosk requires type field', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/kiosk", [
        'idempotency_key' => 'kiosk-val-001',
        'employee_code' => 'EMP001',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['type']);
});

// ── CSV Attendance Import ──

test('hr admin can get import template', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/import/template");

    $response->assertOk()
        ->assertJsonStructure(['template', 'headers']);
});

test('hr admin can preview csv import', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'CSV001']);

    $csv = "employee_code,date,check_in_time,check_out_time\nCSV001,2026-06-28,08:30,17:30\n";
    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('attendance.csv', $csv);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/import/preview", [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('valid', 1)
        ->assertJsonPath('invalid', 0);
});

test('csv preview validates missing columns', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $csv = "employee_code,date\nCSV001,2026-06-28\n";
    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('bad.csv', $csv);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/import/preview", [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('valid', 0);
    expect($response->json('errors'))->not->toBeEmpty();
});

test('csv preview detects invalid employee codes', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $csv = "employee_code,date,check_in_time,check_out_time\nNONEXIST,2026-06-28,08:30,17:30\n";
    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('bad-emp.csv', $csv);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/import/preview", [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('valid', 0)
        ->assertJsonPath('invalid', 1);
});

test('hr admin can commit csv import', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'CSV002']);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/import/commit", [
        'import_key' => 'import-001',
        'rows' => [
            [
                'employee_code' => 'CSV002',
                'date' => '2026-06-28',
                'check_in' => '08:30',
                'check_out' => '17:30',
            ],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('created', 1)
        ->assertJsonPath('skipped', 0);

    $this->assertDatabaseHas('attendance_records', [
        'employee_id' => $employee->id,
        'source' => 'csv',
    ]);
});

test('csv import is idempotent', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'CSV003']);

    $payload = [
        'import_key' => 'import-idem-001',
        'rows' => [
            [
                'employee_code' => 'CSV003',
                'date' => '2026-06-28',
                'check_in' => '08:30',
                'check_out' => '17:30',
            ],
        ],
    ];

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/import/commit", $payload)
        ->assertStatus(201)
        ->assertJsonPath('created', 1);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/import/commit", $payload)
        ->assertStatus(201)
        ->assertJsonPath('created', 0)
        ->assertJsonPath('skipped', 1);
});

test('csv import has confidence score 50', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'CSV004']);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/import/commit", [
        'import_key' => 'import-conf-001',
        'rows' => [
            [
                'employee_code' => 'CSV004',
                'date' => '2026-06-28',
                'check_in' => '08:30',
            ],
        ],
    ])->assertStatus(201);

    $record = AttendanceRecord::where('employee_id', $employee->id)->first();
    expect($record->confidence_score)->toBe(50);
});

test('csv import is audit logged', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'CSV005']);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/import/commit", [
        'import_key' => 'import-audit-001',
        'rows' => [
            ['employee_code' => 'CSV005', 'date' => '2026-06-28', 'check_in' => '08:30'],
        ],
    ])->assertStatus(201);

    $this->assertDatabaseHas('audit_log', [
        'action' => 'attendance.import.committed',
    ]);
});

test('employee cannot access csv import', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/import/template")
        ->assertForbidden();
});

// ── Tenant Isolation ──

test('manual attendance respects tenant isolation', function () {
    $tenant1 = createTenant(['subdomain' => 'alpha']);
    $tenant2 = createTenant(['subdomain' => 'beta']);

    $emp2 = Employee::factory()->create(['tenant_id' => $tenant2->id]);
    $user1 = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant1);

    test()->postJson("http://alpha.ethr.test/api/v1/attendance/manual", [
        'idempotency_key' => 'iso-001',
        'employee_public_id' => $emp2->public_id,
        'date' => now()->format('Y-m-d'),
        'check_in' => '08:30',
        'reason' => 'Test',
    ])->assertStatus(404);
});

// ── Authentication Required ──

test('manual attendance requires authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);

    test()->postJson('http://authtest.ethr.test/api/v1/attendance/manual', [])
        ->assertUnauthorized();
});

test('kiosk attendance requires authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);

    test()->postJson('http://authtest.ethr.test/api/v1/attendance/kiosk', [])
        ->assertUnauthorized();
});

test('csv import requires authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);

    test()->postJson('http://authtest.ethr.test/api/v1/attendance/import/template')
        ->assertUnauthorized();
});
