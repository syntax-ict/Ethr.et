<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\ConflictResolutionStatus;
use App\Enums\ConflictType;
use App\Enums\UserRole;
use App\Models\AttendanceConflict;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\PhotoRetention;
use App\Services\Attendance\SplitDayJoiner;
use App\Services\Payroll\PayrollPeriodLock;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A day recorded before 2026-11-18 as two records: A, closed at lunch, and B,
 * the afternoon's check-in, flagged MULTI_SOURCE_FAR. Resolving that conflict
 * as "merged" joins them into A when they really are one day split by a break.
 * Anything else a merge resolution is asked about keeps its old meaning: the
 * conflict is resolved and no record changes.
 *
 * Monday 12 October 2026 in Addis Ababa (UTC+3), the shift 08:30-17:30.
 */
afterEach(fn () => Carbon::setTestNow());

/** @return array{0: Tenant, 1: Employee, 2: Shift} */
function joinSplitSetup(): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id]);
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '08:30',
        'end_time' => '17:30',
        'crosses_midnight' => false,
        'grace_minutes' => 10,
        'early_departure_minutes' => 45,
        'break_minutes' => 60,
        'working_days' => '1,2,3,4,5,6,7',
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'JS-1']);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $shift->id,
        'assignable_type' => Employee::class,
        'assignable_id' => $employee->id,
        'effective_from' => '2026-10-01',
    ]);

    return [$tenant, $employee, $shift];
}

function joinSplitAt(string $local, string $date = '2026-10-12'): Carbon
{
    return Carbon::parse("{$date} {$local}", 'Africa/Addis_Ababa')->utc();
}

/**
 * The two records as ConflictResolver::flag left them, written directly: the
 * engine would rejoin them now. A is the closed morning (an early leave), B
 * the afternoon (flagged), each with the keys, location and selfie its own
 * punches left.
 *
 * @return array{0: AttendanceRecord, 1: AttendanceRecord, 2: AttendanceConflict}
 */
function joinSplitDay(Tenant $tenant, Employee $employee, Shift $shift, string $date = '2026-10-12', array $a = [], array $b = [], string $tag = 'x'): array
{
    $recordA = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => $shift->id,
        'date' => $date,
        'source' => AttendanceSource::BIOMETRIC,
        'check_in' => joinSplitAt('08:20', $date),
        'check_out' => joinSplitAt('12:05', $date),
        'status' => AttendanceStatus::EARLY_LEAVE,
        'idempotency_key' => "a-in-{$tag}",
        'photo_path' => "a-in-photo-{$tag}.jpg",
        'metadata' => [
            'checkout_idempotency_key' => "a-out-{$tag}",
            'checkout_latitude' => 9.01,
            'checkout_longitude' => 38.76,
            'checkout_photo_path' => "a-out-photo-{$tag}.jpg",
            'punches' => [
                ['kind' => 'check_in', 'source' => 'biometric', 'at' => joinSplitAt('08:20', $date)->toIso8601String(), 'outcome' => 'applied'],
                ['kind' => 'check_out', 'source' => 'biometric', 'at' => joinSplitAt('12:05', $date)->toIso8601String(), 'outcome' => 'applied'],
            ],
        ],
        ...$a,
    ]);

    $recordB = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => $shift->id,
        'date' => $date,
        'source' => AttendanceSource::BIOMETRIC,
        'check_in' => joinSplitAt('13:55', $date),
        'check_out' => joinSplitAt('17:40', $date),
        'status' => AttendanceStatus::LATE,
        'idempotency_key' => "b-in-{$tag}",
        'latitude' => 9.02,
        'longitude' => 38.77,
        'photo_path' => "b-in-photo-{$tag}.jpg",
        'metadata' => [
            'conflict_action' => 'flagged',
            'conflict_with' => $recordA->public_id,
            'checkout_idempotency_key' => "b-out-{$tag}",
            'checkout_latitude' => 9.03,
            'checkout_longitude' => 38.78,
            'checkout_photo_path' => "b-out-photo-{$tag}.jpg",
            'punches' => [
                ['kind' => 'check_in', 'source' => 'biometric', 'at' => joinSplitAt('13:55', $date)->toIso8601String(), 'outcome' => 'applied'],
                ['kind' => 'check_out', 'source' => 'biometric', 'at' => joinSplitAt('17:40', $date)->toIso8601String(), 'outcome' => 'applied'],
            ],
        ],
        ...$b,
    ]);

    $conflict = AttendanceConflict::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'record_a_id' => $recordA->id,
        'record_b_id' => $recordB->id,
        'conflict_type' => ConflictType::MULTI_SOURCE_FAR,
    ]);

    return [$recordA, $recordB, $conflict];
}

function joinSplitUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/attendance/conflicts/{$path}";
}

function joinSplitResolve(Tenant $tenant, AttendanceConflict $conflict, string $resolution = 'merged')
{
    return test()->putJson(joinSplitUrl($tenant, "{$conflict->public_id}/resolve"), ['resolution' => $resolution]);
}

/** @return array<string, mixed> */
function joinSplitSorted(?array $value): array
{
    $value ??= [];
    ksort($value);

    return $value;
}

/** What a record holds that a "no change" assertion compares. */
function joinSplitSnapshot(AttendanceRecord $record): array
{
    $record = $record->fresh();

    return [
        'status' => $record->status->value,
        'check_in' => $record->check_in?->toIso8601String(),
        'check_out' => $record->check_out?->toIso8601String(),
        'metadata' => joinSplitSorted($record->metadata),
    ];
}

// ── Joining one conflict ────────────────────────────────────────────────────

it('joins a split day into the morning record when the conflict is resolved as merged', function () {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    [$a, $b, $conflict] = joinSplitDay($tenant, $employee, $shift);

    joinSplitResolve($tenant, $conflict)->assertOk()->assertJsonPath('resolution', 'merged');

    $a = $a->fresh();
    $b = $b->fresh();
    $metadata = $a->metadata;

    expect($a->check_in->equalTo(joinSplitAt('08:20')))->toBeTrue()
        ->and($a->check_out->equalTo(joinSplitAt('17:40')))->toBeTrue()
        // The afternoon was on time, so the morning's early leave goes.
        ->and($a->status)->toBe(AttendanceStatus::PRESENT);

    $breaks = collect($metadata['breaks'])->map(fn ($e) => joinSplitSorted($e))->all();
    expect($breaks)->toBe([
        [
            'at' => joinSplitAt('12:05')->toIso8601String(),
            'key' => 'a-out-x',
            'lat' => 9.01,
            'lon' => 38.76,
            'photo' => 'a-out-photo-x.jpg',
            'type' => 'out',
        ],
        [
            'at' => joinSplitAt('13:55')->toIso8601String(),
            'key' => 'b-in-x',
            'lat' => 9.02,
            'lon' => 38.77,
            'photo' => 'b-in-photo-x.jpg',
            'type' => 'in',
        ],
    ]);
    expect($metadata['break_photos'])->toBe(2)
        // B's check-out is now A's.
        ->and($metadata['checkout_idempotency_key'])->toBe('b-out-x')
        ->and($metadata['checkout_latitude'])->toBe(9.03)
        ->and($metadata['checkout_longitude'])->toBe(38.78)
        ->and($metadata['checkout_photo_path'])->toBe('b-out-photo-x.jpg')
        ->and($metadata)->not->toHaveKey('conflict_action')
        ->and($metadata)->not->toHaveKey('conflict_with');

    expect(collect($metadata['punches'])->map(fn ($p) => $p['kind'].'@'.$p['at'])->all())->toBe([
        'check_in@'.joinSplitAt('08:20')->toIso8601String(),
        'check_out@'.joinSplitAt('12:05')->toIso8601String(),
        'check_in@'.joinSplitAt('13:55')->toIso8601String(),
        'check_out@'.joinSplitAt('17:40')->toIso8601String(),
    ]);

    expect($b->status)->toBe(AttendanceStatus::VOIDED)
        ->and($b->metadata['voided_reason'])->toBe('conflict_joined')
        ->and($b->metadata['conflict_id'])->toBe($conflict->public_id)
        ->and($b->metadata['joined_into'])->toBe($a->public_id);

    $conflict = $conflict->fresh();
    expect($conflict->resolution)->toBe(ConflictResolutionStatus::MERGED)
        ->and($conflict->resolved_at)->not->toBeNull();

    $audit = AuditLog::query()->where('action', 'attendance.split_day_joined')->first();
    expect($audit)->not->toBeNull();
    expect($audit->payload['kept'])->toBe($a->public_id)
        ->and($audit->payload['joined'])->toBe($b->public_id)
        ->and($audit->payload['gap_minutes'])->toBe(110)
        ->and($audit->payload['conflict_id'])->toBe($conflict->public_id);
});

it('keeps the morning as it was when the afternoon was late and left early, and rates by the new check-out', function () {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    // The last check-out is 16:00, before 16:45: an early leave.
    [$a, $b, $conflict] = joinSplitDay($tenant, $employee, $shift, b: ['check_out' => joinSplitAt('16:00')]);

    joinSplitResolve($tenant, $conflict)->assertOk();

    expect($a->fresh()->status)->toBe(AttendanceStatus::EARLY_LEAVE);
});

it('keeps a late morning late however the day ended', function () {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    [$a, , $conflict] = joinSplitDay($tenant, $employee, $shift, a: ['check_in' => joinSplitAt('09:10'), 'status' => AttendanceStatus::LATE]);

    joinSplitResolve($tenant, $conflict)->assertOk();

    expect($a->fresh()->status)->toBe(AttendanceStatus::LATE);
});

it('moves the afternoon\'s own break notes to the day and keeps them on one record only', function () {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $note = ['type' => 'out', 'at' => joinSplitAt('15:30')->toIso8601String(), 'key' => 'b-brk-out', 'photo' => 'b-brk.jpg'];
    [$a, $b, $conflict] = joinSplitDay($tenant, $employee, $shift, b: ['metadata' => [
        'conflict_action' => 'flagged',
        'breaks' => [$note, ['type' => 'in', 'at' => joinSplitAt('15:40')->toIso8601String(), 'key' => 'b-brk-in']],
        'break_photos' => 1,
    ]]);

    joinSplitResolve($tenant, $conflict)->assertOk();

    $metadata = $a->fresh()->metadata;
    expect(collect($metadata['breaks'])->pluck('key')->all())->toBe(['a-out-x', 'b-in-x', 'b-brk-out', 'b-brk-in'])
        ->and($metadata['break_photos'])->toBe(3)
        ->and($b->fresh()->metadata)->not->toHaveKey('breaks')
        ->and($b->fresh()->metadata)->not->toHaveKey('break_photos');
});
it('leaves the day open when the afternoon was never closed', function () {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    [$a, , $conflict] = joinSplitDay($tenant, $employee, $shift, b: ['check_out' => null, 'metadata' => [
        'conflict_action' => 'flagged',
        'punches' => [['kind' => 'check_in', 'source' => 'biometric', 'at' => joinSplitAt('13:55')->toIso8601String(), 'outcome' => 'applied']],
    ]]);

    joinSplitResolve($tenant, $conflict)->assertOk();

    $a = $a->fresh();
    expect($a->check_out)->toBeNull()
        ->and($a->metadata)->not->toHaveKey('checkout_idempotency_key')
        ->and($a->metadata)->not->toHaveKey('checkout_photo_path')
        ->and($a->metadata['breaks'])->toHaveCount(2)
        // Rated as at arrival, as a reopened record is.
        ->and($a->status)->toBe(AttendanceStatus::PRESENT);
});

it('keeps the earlier record when the conflict names them the other way round', function () {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    [$morning, $afternoon, $conflict] = joinSplitDay($tenant, $employee, $shift);
    $conflict->update(['record_a_id' => $afternoon->id, 'record_b_id' => $morning->id]);
    // The flag is on the kept record here: it was the later one to arrive.
    $morning->update(['metadata' => [...$morning->metadata, 'conflict_action' => 'flagged', 'conflict_with' => $afternoon->public_id, 'flagged_at' => '2026-10-12T11:00:00+00:00']]);

    joinSplitResolve($tenant, $conflict)->assertOk();

    expect($morning->fresh()->metadata)->not->toHaveKey('conflict_action')
        ->and($morning->fresh()->metadata)->not->toHaveKey('conflict_with')
        ->and($morning->fresh()->metadata)->not->toHaveKey('flagged_at');
    expect($afternoon->fresh()->status)->toBe(AttendanceStatus::VOIDED)
        ->and($morning->fresh()->check_out->equalTo(joinSplitAt('17:40')))->toBeTrue()
        ->and($morning->fresh()->status)->toBe(AttendanceStatus::PRESENT);
});

it('changes no record when a merged pair is not a split day', function (array $a, array $b) {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    [$recordA, $recordB, $conflict] = joinSplitDay($tenant, $employee, $shift, a: $a, b: $b);
    $before = [joinSplitSnapshot($recordA), joinSplitSnapshot($recordB)];

    joinSplitResolve($tenant, $conflict)->assertOk()->assertJsonPath('resolution', 'merged');

    expect([joinSplitSnapshot($recordA), joinSplitSnapshot($recordB)])->toBe($before)
        ->and($conflict->fresh()->resolution)->toBe(ConflictResolutionStatus::MERGED);
    expect(AuditLog::query()->where('action', 'attendance.split_day_joined')->count())->toBe(0);
})->with([
    'two check-ins ten minutes apart, the first never closed' => [
        ['check_in' => joinSplitAt('08:30'), 'check_out' => null],
        ['check_in' => joinSplitAt('08:40'), 'check_out' => null],
    ],
    'overlapping times' => [
        ['check_in' => joinSplitAt('08:30'), 'check_out' => joinSplitAt('17:00')],
        ['check_in' => joinSplitAt('12:00'), 'check_out' => joinSplitAt('13:00')],
    ],
    'the second opens the minute the first closes' => [
        ['check_in' => joinSplitAt('08:30'), 'check_out' => joinSplitAt('12:00')],
        ['check_in' => joinSplitAt('12:00'), 'check_out' => joinSplitAt('17:00')],
    ],
]);

it('changes no record when the two records are on different dates, or one is void', function (string $case) {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $b = $case === 'date' ? ['date' => '2026-10-13'] : ['status' => AttendanceStatus::VOIDED];
    [$recordA, $recordB, $conflict] = joinSplitDay($tenant, $employee, $shift, b: $b);
    $before = [joinSplitSnapshot($recordA), joinSplitSnapshot($recordB)];

    joinSplitResolve($tenant, $conflict)->assertOk();

    expect([joinSplitSnapshot($recordA), joinSplitSnapshot($recordB)])->toBe($before);
})->with(['date', 'voided']);

it('still resolves dismissed, keep_a and keep_b exactly as before', function () {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    [$a, $b, $conflict] = joinSplitDay($tenant, $employee, $shift);
    $before = [joinSplitSnapshot($a), joinSplitSnapshot($b)];

    joinSplitResolve($tenant, $conflict, 'dismissed')->assertOk();
    expect([joinSplitSnapshot($a), joinSplitSnapshot($b)])->toBe($before);

    [$a2, $b2, $conflict2] = joinSplitDay($tenant, $employee, $shift, tag: 'y');
    joinSplitResolve($tenant, $conflict2, 'keep_a')->assertOk();
    expect($b2->fresh()->status)->toBe(AttendanceStatus::VOIDED)
        ->and($b2->fresh()->metadata['voided_reason'])->toBe('conflict_resolved')
        ->and($a2->fresh()->status)->toBe(AttendanceStatus::EARLY_LEAVE);
});

// ── A paid day ──────────────────────────────────────────────────────────────

function joinSplitPay(Tenant $tenant, Employee $employee): void
{
    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
        'status' => 'approved',
    ]);
    PayrollEntry::factory()->create(['tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id]);
}

it('refuses to join a day an approved payroll run paid, as it refuses keep_a', function () {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    [$a, $b, $conflict] = joinSplitDay($tenant, $employee, $shift);
    joinSplitPay($tenant, $employee);
    $before = [joinSplitSnapshot($a), joinSplitSnapshot($b)];

    joinSplitResolve($tenant, $conflict)
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/payroll-period-closed');
    joinSplitResolve($tenant, $conflict, 'keep_a')
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/payroll-period-closed');

    expect([joinSplitSnapshot($a), joinSplitSnapshot($b)])->toBe($before)
        ->and($conflict->fresh()->resolution)->toBe(ConflictResolutionStatus::PENDING);
});

// ── What a resend answers after the join ────────────────────────────────────

it('still answers every key of the joined day as a duplicate and records nothing new', function () {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    [$a, , $conflict] = joinSplitDay($tenant, $employee, $shift);
    joinSplitResolve($tenant, $conflict)->assertOk();

    $resend = function (string $type, string $key, string $local) use ($tenant, $employee) {
        $at = joinSplitAt($local);
        Carbon::setTestNow($at->copy()->addHours(6));

        return app(AttendanceEngine::class)->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $tenant->id,
            source: AttendanceSource::BIOMETRIC,
            type: $type,
            idempotencyKey: $key,
            occurredAt: $at->toIso8601String(),
        ));
    };

    $count = AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count();

    // The morning's check-out, the afternoon's check-in and check-out.
    expect($resend('check_out', 'a-out-x', '12:05')->wasDuplicate)->toBeTrue();
    expect($resend('check_in', 'b-in-x', '13:55')->wasDuplicate)->toBeTrue();
    expect($resend('check_out', 'b-out-x', '17:40')->wasDuplicate)->toBeTrue();

    expect(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe($count)
        ->and($a->fresh()->check_out->equalTo(joinSplitAt('17:40')))->toBeTrue();
});

it('keeps the selfies of a joined day findable and deletes them with the day', function () {
    Storage::fake('local');
    Notification::fake();
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $paths = [];
    foreach (['a-out', 'b-in', 'b-out', 'a-in'] as $name) {
        $paths[$name] = "{$tenant->public_id}/selfies/".Str::ulid().'.jpg';
        Storage::disk('local')->put($paths[$name], $name);
    }
    [$a, $b, $conflict] = joinSplitDay(
        $tenant,
        $employee,
        $shift,
        a: ['photo_path' => $paths['a-in'], 'metadata' => ['checkout_photo_path' => $paths['a-out'], 'checkout_idempotency_key' => 'a-out-x']],
        b: ['photo_path' => $paths['b-in'], 'metadata' => ['conflict_action' => 'flagged', 'checkout_photo_path' => $paths['b-out'], 'checkout_idempotency_key' => 'b-out-x']],
    );
    joinSplitResolve($tenant, $conflict)->assertOk();

    expect(PhotoRetention::withPhotos()->whereKey($a->id)->exists())->toBeTrue()
        ->and($a->fresh()->breakPhotoPaths())->toBe([$paths['a-out'], $paths['b-in']]);

    app(PhotoRetention::class)->deletePhotos($a->fresh());

    foreach ($paths as $path) {
        Storage::disk('local')->assertMissing($path);
    }
    $after = $a->fresh();
    expect($after->metadata)->not->toHaveKey('break_photos')
        ->and(collect($after->metadata['breaks'])->pluck('photo')->filter()->all())->toBe([]);
    // B kept no path to a file that is no longer its own.
    expect($b->fresh()->photo_path)->toBeNull();
});

// ── Bulk ────────────────────────────────────────────────────────────────────

function joinSplitBulk(Tenant $tenant, array $body)
{
    return test()->postJson(joinSplitUrl($tenant, 'join'), $body);
}

/** Three days of one person: two split days and one overlapping pair. */
function joinSplitWeek(Tenant $tenant, Employee $employee, Shift $shift): array
{
    $one = joinSplitDay($tenant, $employee, $shift, '2026-10-12', tag: 'd1');
    $two = joinSplitDay($tenant, $employee, $shift, '2026-10-13', tag: 'd2');
    $clash = joinSplitDay(
        $tenant,
        $employee,
        $shift,
        '2026-10-14',
        a: ['check_in' => joinSplitAt('08:30', '2026-10-14'), 'check_out' => joinSplitAt('17:00', '2026-10-14')],
        b: ['check_in' => joinSplitAt('12:00', '2026-10-14'), 'check_out' => joinSplitAt('13:00', '2026-10-14')],
        tag: 'd3',
    );

    return [$one, $two, $clash];
}

it('joins the chosen conflicts and names why it left the others', function () {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    [$one, $two, $clash] = joinSplitWeek($tenant, $employee, $shift);

    $response = joinSplitBulk($tenant, ['conflict_ids' => [$one[2]->public_id, $two[2]->public_id, $clash[2]->public_id]])
        ->assertOk()
        ->assertJsonPath('joined', 2)
        ->assertJsonPath('remaining', 0)
        ->assertJsonCount(1, 'left')
        ->assertJsonPath('left.0.conflict_id', $clash[2]->public_id)
        ->assertJsonPath('left.0.reason', SplitDayJoiner::NOT_SPLIT_DAY);
    expect($response->json('next_after'))->toBeNull();

    expect($one[1]->fresh()->status)->toBe(AttendanceStatus::VOIDED)
        ->and($two[1]->fresh()->status)->toBe(AttendanceStatus::VOIDED)
        ->and($one[2]->fresh()->resolution)->toBe(ConflictResolutionStatus::MERGED)
        // The clash is not touched: still pending, both records as they were.
        ->and($clash[2]->fresh()->resolution)->toBe(ConflictResolutionStatus::PENDING)
        ->and($clash[1]->fresh()->status)->not->toBe(AttendanceStatus::VOIDED);
    expect(AuditLog::query()->where('action', 'attendance.split_day_joined')->count())->toBe(2);
});

it('joins every pending conflict of one person, and no one else\'s', function () {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    joinSplitWeek($tenant, $employee, $shift);
    $other = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'JS-2']);
    [, $otherB, $otherConflict] = joinSplitDay($tenant, $other, $shift, tag: 'o');

    joinSplitBulk($tenant, ['employee_public_id' => $employee->public_id])
        ->assertOk()
        ->assertJsonPath('joined', 2)
        ->assertJsonCount(1, 'left')
        ->assertJsonPath('remaining', 0);

    expect($otherConflict->fresh()->resolution)->toBe(ConflictResolutionStatus::PENDING)
        ->and($otherB->fresh()->status)->not->toBe(AttendanceStatus::VOIDED);
});

it('does not take a conflict already resolved, and says so', function () {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    [, , $conflict] = joinSplitDay($tenant, $employee, $shift);
    joinSplitResolve($tenant, $conflict, 'dismissed')->assertOk();

    joinSplitBulk($tenant, ['conflict_ids' => [$conflict->public_id]])
        ->assertOk()
        ->assertJsonPath('joined', 0)
        ->assertJsonPath('left.0.reason', SplitDayJoiner::NOT_PENDING);
});

it('leaves a paid day with its reason and joins the rest', function () {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    [, , $oneConflict] = joinSplitDay($tenant, $employee, $shift, '2026-10-12', tag: 'p1');
    [, , $twoConflict] = joinSplitDay($tenant, $employee, $shift, '2026-11-03', tag: 'p2');
    joinSplitPay($tenant, $employee);

    $response = joinSplitBulk($tenant, ['employee_public_id' => $employee->public_id])->assertOk();

    expect($response->json('joined'))->toBe(1)
        ->and($response->json('left'))->toHaveCount(1)
        ->and($response->json('left.0.reason'))->toBe(SplitDayJoiner::PAYROLL_CLOSED)
        ->and($response->json('left.0.conflict_id'))->toBe($oneConflict->public_id);
    expect($twoConflict->fresh()->resolution)->toBe(ConflictResolutionStatus::MERGED);
});

it('works for a slice and carries on from the cursor on the next call', function () {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    [$one, $two, $clash] = joinSplitWeek($tenant, $employee, $shift);
    // A budget already spent: each call takes the first it is given and stops.
    app()->bind(SplitDayJoiner::class, fn () => new SplitDayJoiner(app(AttendanceEngine::class), app(PayrollPeriodLock::class), sliceSeconds: 0.0));

    $first = joinSplitBulk($tenant, ['employee_public_id' => $employee->public_id])->assertOk();
    expect($first->json('joined'))->toBe(1)
        ->and($first->json('remaining'))->toBe(2)
        ->and($first->json('next_after'))->toBe($one[2]->public_id);

    $second = joinSplitBulk($tenant, ['employee_public_id' => $employee->public_id, 'after' => $first->json('next_after')])->assertOk();
    expect($second->json('joined'))->toBe(1)
        ->and($second->json('remaining'))->toBe(1)
        ->and($second->json('next_after'))->toBe($two[2]->public_id);

    $third = joinSplitBulk($tenant, ['employee_public_id' => $employee->public_id, 'after' => $second->json('next_after')])->assertOk();
    expect($third->json('joined'))->toBe(0)
        ->and($third->json('left'))->toHaveCount(1)
        ->and($third->json('left.0.conflict_id'))->toBe($clash[2]->public_id)
        ->and($third->json('remaining'))->toBe(0)
        ->and($third->json('next_after'))->toBeNull();
});

it('counts another organisation\'s conflict as not found and touches nothing', function () {
    [$tenantA, $employeeA, $shiftA] = joinSplitSetup();
    [, , $conflictOfA] = joinSplitDay($tenantA, $employeeA, $shiftA);

    $tenantB = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenantB);
    $beforeRecords = AttendanceRecord::withoutGlobalScopes()->orderBy('id')->get()->map(fn ($r) => joinSplitSnapshot($r))->all();

    joinSplitBulk($tenantB, ['conflict_ids' => [$conflictOfA->public_id]])
        ->assertOk()
        ->assertJsonPath('joined', 0)
        ->assertJsonPath('left.0.conflict_id', $conflictOfA->public_id)
        ->assertJsonPath('left.0.reason', SplitDayJoiner::NOT_FOUND);

    joinSplitBulk($tenantB, ['employee_public_id' => $employeeA->public_id])
        ->assertOk()
        ->assertJsonPath('joined', 0)
        ->assertJsonPath('remaining', 0);

    // And the single resolve is a 404 to them.
    test()->putJson(joinSplitUrl($tenantB, "{$conflictOfA->public_id}/resolve"), ['resolution' => 'merged'])->assertNotFound();

    expect(AttendanceRecord::withoutGlobalScopes()->orderBy('id')->get()->map(fn ($r) => joinSplitSnapshot($r))->all())->toBe($beforeRecords)
        ->and(AttendanceConflict::withoutGlobalScopes()->find($conflictOfA->id)->resolution)->toBe(ConflictResolutionStatus::PENDING);
});

it('gives a user without the permission 403 and changes nothing', function () {
    [$tenant, $employee, $shift] = joinSplitSetup();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
    [, $b, $conflict] = joinSplitDay($tenant, $employee, $shift);

    joinSplitBulk($tenant, ['conflict_ids' => [$conflict->public_id]])->assertForbidden();

    expect($b->fresh()->status)->not->toBe(AttendanceStatus::VOIDED)
        ->and($conflict->fresh()->resolution)->toBe(ConflictResolutionStatus::PENDING);
});

it('touches only the conflicts inside a scoped role\'s reach, and never the caller\'s own', function () {
    [$tenant, $lead, $shift] = joinSplitSetup();
    $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $lead->id, 'employee_code' => 'JS-R']);
    $stranger = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'JS-S']);

    $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Shift Lead', 'org_scope' => 'direct_reports']);
    $role->permissions()->sync(Permission::whereIn('name', ['attendance.viewConflicts', 'attendance.resolveConflicts'])->pluck('id'));
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $lead->id, 'custom_role_id' => $role->id], $tenant);

    [, $reportB, $reportConflict] = joinSplitDay($tenant, $report, $shift, tag: 'r');
    [, $strangerB, $strangerConflict] = joinSplitDay($tenant, $stranger, $shift, tag: 's');
    [, $ownB, $ownConflict] = joinSplitDay($tenant, $lead, $shift, tag: 'l');

    $response = joinSplitBulk($tenant, ['conflict_ids' => [$reportConflict->public_id, $strangerConflict->public_id, $ownConflict->public_id]])
        ->assertOk()
        ->assertJsonPath('joined', 1);

    expect(collect($response->json('left'))->pluck('reason')->unique()->all())->toBe([SplitDayJoiner::NOT_FOUND])
        ->and($reportB->fresh()->status)->toBe(AttendanceStatus::VOIDED)
        ->and($strangerB->fresh()->status)->not->toBe(AttendanceStatus::VOIDED)
        ->and($ownB->fresh()->status)->not->toBe(AttendanceStatus::VOIDED);
});

it('refuses a body that names neither or both selectors, or too many ids', function () {
    [$tenant] = joinSplitSetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    joinSplitBulk($tenant, [])->assertUnprocessable();
    joinSplitBulk($tenant, ['conflict_ids' => [Str::ulid()->toString()], 'employee_public_id' => (string) Str::ulid()])->assertUnprocessable();
    joinSplitBulk($tenant, ['conflict_ids' => array_map(fn () => (string) Str::ulid(), range(1, 201))])->assertUnprocessable();
});
