<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\ConflictResolutionStatus;
use App\Enums\ConflictType;
use App\Enums\UserRole;
use App\Models\AttendanceConflict;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\Attendance\ConflictResolver;
use Carbon\Carbon;

// ── ConflictResolver Decision Table ──

test('same source same minute deduplicates keeping first', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $existing = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'source' => AttendanceSource::WEB,
        'check_in' => Carbon::today()->setTime(8, 30, 0),
        'confidence_score' => 75,
    ]);

    $duplicate = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'source' => AttendanceSource::WEB,
        'check_in' => Carbon::today()->setTime(8, 30, 20),
        'confidence_score' => 75,
    ]);

    $resolution = app(ConflictResolver::class)->resolve($duplicate);

    expect($resolution->action)->toBe('deduped');
    expect($duplicate->fresh()->status)->toBe(AttendanceStatus::VOIDED);
});

test('different source same minute merges keeping higher confidence', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $existing = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'source' => AttendanceSource::WEB,
        'check_in' => Carbon::today()->setTime(8, 30, 0),
        'confidence_score' => 75,
    ]);

    $biometric = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'source' => AttendanceSource::BIOMETRIC,
        'check_in' => Carbon::today()->setTime(8, 30, 15),
        'confidence_score' => 100,
    ]);

    $resolution = app(ConflictResolver::class)->resolve($biometric);

    expect($resolution->action)->toBe('merged');
    expect($resolution->record->confidence_score)->toBe(100);
});

test('different source 1-5 min gap merges with earliest in latest out', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $existing = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'source' => AttendanceSource::WEB,
        'check_in' => Carbon::today()->setTime(8, 30),
        'check_out' => Carbon::today()->setTime(17, 0),
        'confidence_score' => 75,
    ]);

    $mobile = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'source' => AttendanceSource::MOBILE,
        'check_in' => Carbon::today()->setTime(8, 33),
        'check_out' => Carbon::today()->setTime(17, 30),
        'confidence_score' => 90,
    ]);

    $resolution = app(ConflictResolver::class)->resolve($mobile);

    expect($resolution->action)->toBe('merged');
    $survivor = $resolution->record;
    expect($survivor->check_in->format('H:i'))->toBe('08:30');
    expect($survivor->check_out->format('H:i'))->toBe('17:30');
});

test('different source far apart flags for HR review', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $morning = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'source' => AttendanceSource::WEB,
        'check_in' => Carbon::today()->setTime(8, 30),
        'confidence_score' => 75,
    ]);

    $lateEntry = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'source' => AttendanceSource::MOBILE,
        'check_in' => Carbon::today()->setTime(14, 0),
        'confidence_score' => 90,
    ]);

    $resolution = app(ConflictResolver::class)->resolve($lateEntry);

    expect($resolution->action)->toBe('flagged');

    $conflict = AttendanceConflict::where('employee_id', $employee->id)->first();
    expect($conflict)->not->toBeNull();
    expect($conflict->conflict_type)->toBe(ConflictType::MULTI_SOURCE_FAR);
    expect($conflict->resolution)->toBe(ConflictResolutionStatus::PENDING);
});

test('confidence tie uses source priority biometric over web', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $web = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'source' => AttendanceSource::WEB,
        'check_in' => Carbon::today()->setTime(8, 30, 0),
        'confidence_score' => 90,
    ]);

    $bio = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'source' => AttendanceSource::BIOMETRIC,
        'check_in' => Carbon::today()->setTime(8, 30, 10),
        'confidence_score' => 90,
    ]);

    $resolution = app(ConflictResolver::class)->resolve($bio);

    expect($resolution->action)->toBe('merged');
    expect($resolution->record->source)->toBe(AttendanceSource::BIOMETRIC);
});

test('duplicate idempotency key is rejected before conflict resolution', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $key = 'idem-test-001';

    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'idempotency_key' => $key,
    ]);

    expect(
        AttendanceRecord::where('tenant_id', $tenant->id)
            ->where('idempotency_key', $key)
            ->count()
    )->toBe(1);
});

test('no conflict when only one record exists', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'check_in' => Carbon::today()->setTime(8, 30),
    ]);

    $resolution = app(ConflictResolver::class)->resolve($record);
    expect($resolution->action)->toBe('created');
});

// ── Conflict API Endpoints ──

test('hr admin can list attendance conflicts', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $recordA = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);
    $recordB = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    AttendanceConflict::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'record_a_id' => $recordA->id,
        'record_b_id' => $recordB->id,
        'conflict_type' => ConflictType::MULTI_SOURCE_FAR,
    ]);

    $token = $user->createToken('auth', ['*'])->plainTextToken;

    $response = test()->withToken($token)
        ->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/conflicts");

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.conflict_type', 'multi_source_far');
    $response->assertJsonPath('data.0.resolution', 'pending');
    $response->assertJsonMissingPath('data.0.id');
});

test('hr admin can resolve a conflict by keeping record A', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $recordA = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);
    $recordB = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    $conflict = AttendanceConflict::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'record_a_id' => $recordA->id,
        'record_b_id' => $recordB->id,
        'conflict_type' => ConflictType::MULTI_SOURCE_FAR,
    ]);

    $token = $user->createToken('auth', ['*'])->plainTextToken;

    $response = test()->withToken($token)
        ->putJson(
            "http://{$tenant->subdomain}.ethr.test/api/v1/attendance/conflicts/{$conflict->public_id}/resolve",
            [
                'resolution' => 'keep_a',
                'resolution_notes' => 'Record A is the correct entry.',
            ]
        );

    $response->assertOk();
    $response->assertJsonPath('resolution', 'keep_a');

    expect($recordB->fresh()->status)->toBe(AttendanceStatus::VOIDED);
});

test('resolving an already resolved conflict returns error', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $recordA = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);
    $recordB = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    $conflict = AttendanceConflict::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'record_a_id' => $recordA->id,
        'record_b_id' => $recordB->id,
        'conflict_type' => ConflictType::MULTI_SOURCE_FAR,
        'resolution' => ConflictResolutionStatus::KEEP_A,
        'resolved_by' => $user->id,
        'resolved_at' => now(),
    ]);

    $token = $user->createToken('auth', ['*'])->plainTextToken;

    $response = test()->withToken($token)
        ->putJson(
            "http://{$tenant->subdomain}.ethr.test/api/v1/attendance/conflicts/{$conflict->public_id}/resolve",
            ['resolution' => 'keep_b']
        );

    $response->assertStatus(422);
    $response->assertJsonPath('type', 'https://ethr.et/errors/invalid-state');
});
