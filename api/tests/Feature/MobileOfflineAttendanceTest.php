<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\Attendance\ConflictResolver;
use App\Services\Attendance\QrCodeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

// ── Mobile Check-in ──

test('employee can check in via mobile with GPS', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/check-in", [
        'idempotency_key' => 'mob-ci-001',
        'latitude' => 9.0192,
        'longitude' => 38.7525,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('source', 'mobile')
        ->assertJsonMissingPath('id');
});

test('mobile check-in requires GPS coordinates', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/check-in", [
        'idempotency_key' => 'mob-ci-002',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['latitude', 'longitude']);
});

test('mobile check-in with geofence verified has confidence 90', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
        'latitude' => 9.0192,
        'longitude' => 38.7525,
        'geofence_radius_meters' => 500,
    ]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/check-in", [
        'idempotency_key' => 'mob-geo-001',
        'latitude' => 9.0192,
        'longitude' => 38.7525,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('confidence_score', 90)
        ->assertJsonPath('geofence_verified', true);
});

test('mobile check-in outside geofence has confidence 88', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
        'latitude' => 9.0192,
        'longitude' => 38.7525,
        'geofence_radius_meters' => 100,
    ]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/check-in", [
        'idempotency_key' => 'mob-nogeo-001',
        'latitude' => 10.0,
        'longitude' => 39.0,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('confidence_score', 88)
        ->assertJsonPath('geofence_verified', false);
});

test('mobile check-in with selfie has confidence 95', function () {
    Storage::fake('minio');

    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    // Clients send the captured selfie inline; the server stores it and derives
    // the object key. See MobileSelfieUploadTest for the storage guarantees.
    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/check-in", [
        'idempotency_key' => 'mob-selfie-001',
        'latitude' => 9.0,
        'longitude' => 38.0,
        'photo' => selfieDataUrl(120, 120),
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('confidence_score', 95);
});

test('employee can check out via mobile', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    AttendanceRecord::factory()->checkInOnly()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => now()->format('Y-m-d'),
        'check_in' => now()->subHours(4),
        'source' => AttendanceSource::MOBILE,
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/check-out", [
        'idempotency_key' => 'mob-co-001',
    ]);

    $response->assertOk();
});

// ── QR Attendance ──

test('hr admin can generate QR code', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/qr/generate?branch_public_id={$branch->public_id}");

    $response->assertOk()
        ->assertJsonStructure(['token', 'branch_public_id', 'expires_at']);
});

test('employee can check in via QR code', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    $qrService = app(QrCodeService::class);
    $qrData = $qrService->generate($branch, null, 30);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/qr", [
        'idempotency_key' => 'qr-ci-001',
        'qr_token' => $qrData['token'],
        'type' => 'check_in',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('source', 'qr')
        ->assertJsonPath('confidence_score', 85);
});

test('expired QR code is rejected', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    Carbon::setTestNow(now()->subHours(2));
    $qrService = app(QrCodeService::class);
    $qrData = $qrService->generate($branch, null, 30);
    Carbon::setTestNow();

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/qr", [
        'idempotency_key' => 'qr-exp-001',
        'qr_token' => $qrData['token'],
        'type' => 'check_in',
    ])->assertStatus(422);
});

test('invalid QR token is rejected', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/qr", [
        'idempotency_key' => 'qr-invalid-001',
        'qr_token' => 'totally-fake-token',
        'type' => 'check_in',
    ])->assertStatus(422);
});

test('QR code from different tenant is rejected', function () {
    $tenant1 = createTenant(['subdomain' => 'alpha']);
    $tenant2 = createTenant(['subdomain' => 'beta']);

    $branch2 = Branch::factory()->create(['tenant_id' => $tenant2->id]);
    $qrService = app(QrCodeService::class);
    $qrData = $qrService->generate($branch2, null, 30);

    $employee = Employee::factory()->create(['tenant_id' => $tenant1->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant1);
    test()->actingAs($user);

    test()->postJson('http://alpha.ethr.test/api/v1/attendance/qr', [
        'idempotency_key' => 'qr-cross-001',
        'qr_token' => $qrData['token'],
        'type' => 'check_in',
    ])->assertStatus(422);
});

// ── QR Code Service ──

test('qr code service generates and validates token', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    $service = new QrCodeService;
    $result = $service->generate($branch, null, 30);

    expect($result)->toHaveKeys(['token', 'branch_public_id', 'expires_at']);

    $payload = $service->validate($result['token'], $tenant->id);
    expect($payload)->not->toBeNull();
    expect($payload['branch_id'])->toBe($branch->id);
});

test('qr code service rejects expired token', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    Carbon::setTestNow(now()->subHours(2));
    $service = new QrCodeService;
    $result = $service->generate($branch, null, 30);
    Carbon::setTestNow();

    $payload = $service->validate($result['token'], $tenant->id);
    expect($payload)->toBeNull();
});

// ── Offline Batch Sync ──

test('employee can sync offline attendance records', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/sync", [
        'records' => [
            [
                'idempotency_key' => 'offline-001',
                'employee_public_id' => $employee->public_id,
                'type' => 'check_in',
                'timestamp' => now()->subHours(4)->toIso8601String(),
                'latitude' => 9.0,
                'longitude' => 38.0,
                'offline_token' => hash('sha256', 'offline-001'),
            ],
            [
                'idempotency_key' => 'offline-002',
                'employee_public_id' => $employee->public_id,
                'type' => 'check_in',
                'timestamp' => now()->subHours(2)->toIso8601String(),
                'latitude' => 9.0,
                'longitude' => 38.0,
                'offline_token' => hash('sha256', 'offline-002'),
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('summary.created', 2)
        ->assertJsonPath('summary.duplicate', 0)
        ->assertJsonPath('summary.total', 2);
});

test('offline sync is idempotent', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $payload = [
        'records' => [
            [
                'idempotency_key' => 'offline-dup-001',
                'employee_public_id' => $employee->public_id,
                'type' => 'check_in',
                'timestamp' => now()->toIso8601String(),
                'offline_token' => hash('sha256', 'offline-dup-001'),
            ],
        ],
    ];

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/sync", $payload)
        ->assertOk()
        ->assertJsonPath('summary.created', 1);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/sync", $payload)
        ->assertOk()
        ->assertJsonPath('summary.duplicate', 1)
        ->assertJsonPath('summary.created', 0);
});

test('offline sync reports errors for unknown employees', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/sync", [
        'records' => [
            [
                'idempotency_key' => 'offline-err-001',
                'employee_public_id' => 'nonexistent-public-id',
                'type' => 'check_in',
                'timestamp' => now()->toIso8601String(),
                'offline_token' => hash('sha256', 'test'),
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('summary.error', 1)
        ->assertJsonPath('summary.created', 0);
});

test('offline sync has confidence score 88', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/sync", [
        'records' => [
            [
                'idempotency_key' => 'offline-conf-001',
                'employee_public_id' => $employee->public_id,
                'type' => 'check_in',
                'timestamp' => now()->toIso8601String(),
                'offline_token' => hash('sha256', 'conf-001'),
            ],
        ],
    ])->assertOk();

    $record = AttendanceRecord::where('employee_id', $employee->id)->first();
    expect($record->confidence_score)->toBe(88);
    expect($record->source)->toBe(AttendanceSource::OFFLINE_MOBILE);
});

// ── Conflict Resolver ──

test('conflict resolver merges same-minute records from different sources keeping higher confidence', function () {
    // Formal decision table (PHASE_03 GAP-FIX-2): different source, same minute → Merge, keep highest confidence.
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $existing = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'check_in' => Carbon::today()->setTime(8, 30),
        'confidence_score' => 75,
        'source' => AttendanceSource::WEB,
    ]);

    $newRecord = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => $existing->date,
        'check_in' => Carbon::today()->setTime(8, 30),
        'confidence_score' => 100,
        'source' => AttendanceSource::BIOMETRIC,
    ]);

    $resolver = new ConflictResolver;
    $result = $resolver->resolve($newRecord);

    expect($result->action)->toBe('merged');
    expect($result->record->id)->toBe($newRecord->id);
    $existing->refresh();
    expect($existing->status)->toBe(AttendanceStatus::VOIDED);
    expect(AttendanceRecord::find($newRecord->id))->not->toBeNull();
});

test('conflict resolver dedupes same-minute records keeping the first on a confidence tie', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $existing = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'check_in' => Carbon::today()->setTime(8, 30),
        'confidence_score' => 75,
        'source' => AttendanceSource::WEB,
    ]);

    $newRecord = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => $existing->date,
        'check_in' => Carbon::today()->setTime(8, 30),
        'confidence_score' => 75,
        'source' => AttendanceSource::WEB,
    ]);

    $result = (new ConflictResolver)->resolve($newRecord);

    expect($result->action)->toBe('deduped');
    expect(AttendanceRecord::find($existing->id))->not->toBeNull();
    $newRecord->refresh();
    expect($newRecord->status)->toBe(AttendanceStatus::VOIDED);
});

test('conflict resolver merges records 1-5 minutes apart into one spanning record', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $existing = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'check_in' => Carbon::today()->setTime(8, 30),
        'check_out' => null,
        'confidence_score' => 75,
        'source' => AttendanceSource::WEB,
    ]);

    $newRecord = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => $existing->date,
        'check_in' => Carbon::today()->setTime(8, 33),
        'check_out' => Carbon::today()->setTime(17, 0),
        'confidence_score' => 100,
        'source' => AttendanceSource::BIOMETRIC,
    ]);

    $result = (new ConflictResolver)->resolve($newRecord);

    expect($result->action)->toBe('merged');
    $existing->refresh();
    expect($existing->status)->toBe(AttendanceStatus::VOIDED);
    $survivor = AttendanceRecord::find($newRecord->id);
    expect($survivor)->not->toBeNull();
    expect($survivor->check_in->format('H:i'))->toBe('08:30');
    expect($survivor->check_out->format('H:i'))->toBe('17:00');
});

test('conflict resolver flags records more than 5 minutes apart for review, keeping both', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $existing = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'check_in' => Carbon::today()->setTime(8, 30),
        'confidence_score' => 75,
        'source' => AttendanceSource::WEB,
    ]);

    $newRecord = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => $existing->date,
        'check_in' => Carbon::today()->setTime(8, 40),
        'confidence_score' => 100,
        'source' => AttendanceSource::BIOMETRIC,
    ]);

    $result = (new ConflictResolver)->resolve($newRecord);

    expect($result->action)->toBe('flagged');
    expect(AttendanceRecord::find($existing->id))->not->toBeNull();
    expect(AttendanceRecord::find($newRecord->id))->not->toBeNull();
    expect($result->record->metadata['conflict_with'])->toBe($existing->public_id);
});

test('conflict resolver reports no conflict for an unrelated first check-in', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $newRecord = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'check_in' => Carbon::today()->setTime(8, 30),
    ]);

    $result = (new ConflictResolver)->resolve($newRecord);

    expect($result->action)->toBe('created');
});

// ── Conflict Resolver wired into the live check-in pipeline ──

test('a second web check-in within a minute of the first is deduped automatically', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/check-in", [
        'idempotency_key' => 'web-ci-conflict-1',
        'source' => 'web',
    ])->assertCreated();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/check-in", [
        'idempotency_key' => 'web-ci-conflict-2',
        'source' => 'web',
    ])->assertCreated();

    expect(AttendanceRecord::where('employee_id', $employee->id)->where('status', '!=', 'voided')->count())->toBe(1);
    $this->assertDatabaseHas('audit_log', ['action' => 'attendance.conflict_deduped']);
});

test('a flagged conflict is visible in the attendance record API response', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $existing = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'check_in' => Carbon::today()->setTime(8, 30),
    ]);

    $newRecord = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => $existing->date,
        'check_in' => Carbon::today()->setTime(8, 40),
    ]);

    (new ConflictResolver)->resolve($newRecord);

    $response = test()->actingAs(createUser(['role' => UserRole::HR_ADMIN], $tenant))
        ->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance");

    $response->assertOk();
    $flagged = collect($response->json('data'))->firstWhere('public_id', $newRecord->public_id);
    expect($flagged['conflict']['action'])->toBe('flagged');
    expect($flagged['conflict']['with_record_public_id'])->toBe($existing->public_id);
});

// ── Authentication ──

test('mobile check-in requires authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);

    test()->postJson('http://authtest.ethr.test/api/v1/attendance/mobile/check-in', [])
        ->assertUnauthorized();
});

test('QR scan requires authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);

    test()->postJson('http://authtest.ethr.test/api/v1/attendance/qr', [])
        ->assertUnauthorized();
});

test('offline sync requires authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);

    test()->postJson('http://authtest.ethr.test/api/v1/attendance/sync', [])
        ->assertUnauthorized();
});
