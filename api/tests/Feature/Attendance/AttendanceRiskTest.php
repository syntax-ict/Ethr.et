<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\BranchAttendanceOverride;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Shift;
use App\Models\Tenant;
use App\Notifications\AttendanceHighRiskNotification;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\AttendancePatternScan;
use App\Services\Attendance\AttendancePolicy;
use App\Services\Attendance\AttendanceRiskScorer;
use App\Services\Payroll\RunRegisterService;
use App\Support\ImageHash;
use App\Support\PunchSignals;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Risk scoring (AttendanceRiskScorer), adaptive selfies, the nightly pattern
 * scan, and the payroll run listing what is still open. Punches are scored
 * and flagged, never refused.
 */
afterEach(fn () => Carbon::setTestNow());

/** @return array{0: Tenant, 1: Branch} */
function arOrganisation(array $settings = []): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-12 08:20', 'Africa/Addis_Ababa')->utc());
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'latitude' => 9.0108, 'longitude' => 38.7613, 'geofence_radius_meters' => 150]);
    Shift::factory()->default()->create(['tenant_id' => $tenant->id, 'start_time' => '08:30', 'end_time' => '17:30', 'grace_minutes' => 15]);
    AttendanceSetting::create(['tenant_id' => $tenant->id, ...AttendanceSetting::defaults(), ...$settings]);

    return [$tenant, $branch];
}

function arPunch(Tenant $tenant, Employee $employee, array $meta = [], string $type = 'check_in', ?string $ip = '198.51.100.7', ?string $photo = null, ?float $lat = 9.0108): AttendanceRecord
{
    return app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: AttendanceSource::MOBILE,
        type: $type,
        idempotencyKey: uniqid('ar-', true),
        latitude: $lat,
        longitude: $lat === null ? null : 38.7613,
        photoPath: $photo,
        ipAddress: $ip,
        metadata: $meta,
    ))->record->fresh();
}

test('the weights are what a reviewer was told they are', function () {
    expect(AttendanceRiskScorer::WEIGHTS)->toMatchArray([
        'shared_device' => 35,
        'automation' => 40,
        'photo_replayed' => 40,
        'photo_of_someone_else' => 50,
        'stale_fix' => 15,
        'clock_skew' => 15,
        'ip_burst' => 20,
        'unusual_place' => 15,
        'device_switch' => 10,
        'liveness_failed' => 25,
    ]);
});

test('a clean punch scores nothing and is not flagged', function () {
    [$tenant, $branch] = arOrganisation();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);

    $record = arPunch($tenant, $employee, ['device' => PunchSignals::deviceHash('own-phone'), 'altitude' => 2300.0, 'fix_age_ms' => 800]);

    expect($record->risk_score)->toBe(0)
        ->and($record->status)->toBe(AttendanceStatus::PRESENT)
        ->and(AttendanceIntegrityFlag::where('type', 'high_risk')->exists())->toBeFalse();
});

test('one phone punching for two people, driven by automation, is flagged and reviewers told, never refused', function () {
    Notification::fake();
    [$tenant, $branch] = arOrganisation();
    $reviewer = createUser(['role' => UserRole::HR_ADMIN, 'status' => 'active'], $tenant);
    $device = PunchSignals::deviceHash('shared-phone');
    $first = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $second = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);

    arPunch($tenant, $first, ['device' => $device]);
    $record = arPunch($tenant, $second, ['device' => $device, 'webdriver' => true], ip: '198.51.100.99');

    expect($record->risk_reasons)->toHaveKeys(['shared_device', 'automation'])
        ->and($record->risk_score)->toBe(75)
        ->and($record->status)->not->toBe(AttendanceStatus::VOIDED);
    $flag = AttendanceIntegrityFlag::where('attendance_record_id', $record->id)->where('type', 'high_risk')->sole();
    expect($flag->severity)->toBe('high');
    Notification::assertSentTo($reviewer, AttendanceHighRiskNotification::class);
});

test('a stale fix and a far-off phone clock each count, and a branch can set its own threshold', function () {
    [$tenant, $branch] = arOrganisation(['risk_review_threshold' => 40]);
    BranchAttendanceOverride::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'overrides' => ['risk_review_threshold' => 25]]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);

    $record = arPunch($tenant, $employee, ['fix_age_ms' => 400_000, 'client_offset_seconds' => -900]);

    expect($record->risk_reasons)->toBe(['stale_fix' => 15, 'clock_skew' => 15])
        ->and($record->risk_score)->toBe(30)
        ->and(AttendanceIntegrityFlag::where('type', 'high_risk')->sole()->severity)->toBe('medium');
});

test('a selfie that repeats an earlier one, or someone else\'s, is caught', function () {
    Storage::fake(config('filesystems.default'));
    [$tenant, $branch] = arOrganisation();
    $image = imagecreatetruecolor(64, 64);
    for ($x = 0; $x < 64; $x++) {
        imageline($image, $x, 0, $x, 63, imagecolorallocate($image, $x * 4, 255 - $x * 4, 120));
    }
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();
    Storage::disk(config('filesystems.default'))->put('t/selfies/a.jpg', $jpeg);
    Storage::disk(config('filesystems.default'))->put('t/selfies/b.jpg', $jpeg);
    Storage::disk(config('filesystems.default'))->put('t/selfies/c.jpg', $jpeg);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $colleague = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);

    arPunch($tenant, $employee, photo: 't/selfies/a.jpg');
    Carbon::setTestNow(Carbon::parse('2026-10-13 08:20', 'Africa/Addis_Ababa')->utc());
    $replay = arPunch($tenant, $employee, photo: 't/selfies/b.jpg');
    $copy = arPunch($tenant, $colleague, photo: 't/selfies/c.jpg');

    expect($replay->risk_reasons)->toHaveKey('photo_replayed')
        ->and($copy->risk_reasons)->toHaveKey('photo_of_someone_else')
        ->and($replay->metadata['photo_hash'])->toHaveLength(16);
});

test('the image hash tells the same picture from a different one', function () {
    $make = function (int $shade): string {
        $image = imagecreatetruecolor(80, 60);
        for ($x = 0; $x < 80; $x++) {
            imageline($image, $x, 0, $x, 59, imagecolorallocate($image, ($x * 3 + $shade) % 256, 100, 200 - $x));
        }
        ob_start();
        imagejpeg($image, null, 95);

        return (string) ob_get_clean();
    };
    $flip = function (string $jpeg): string {
        $image = imagecreatefromstring($jpeg);
        imageflip($image, IMG_FLIP_HORIZONTAL);
        ob_start();
        imagejpeg($image, null, 40);

        return (string) ob_get_clean();
    };

    $original = (string) ImageHash::dHash($make(0));
    $recompressed = (string) ImageHash::dHash($flip($flip($make(0))));
    $different = (string) ImageHash::dHash($flip($make(0)));

    expect(ImageHash::distance($original, $recompressed))->toBeLessThanOrEqual(ImageHash::SAME_PICTURE_BITS)
        ->and(ImageHash::distance($original, $different))->toBeGreaterThan(ImageHash::SAME_PICTURE_BITS);
});

test('two people on one network is a finding only when it is not a known office network', function () {
    [$tenant, $branch] = arOrganisation();
    $a = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $b = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    arPunch($tenant, $a, ip: '203.0.113.50');
    expect(arPunch($tenant, $b, ip: '203.0.113.50')->risk_reasons)->toHaveKey('ip_burst');

    // The office's own network: seen on three punches verified inside the branch.
    [$office, $hq] = arOrganisation();
    foreach (range(1, 3) as $i) {
        AttendanceRecord::factory()->create([
            'tenant_id' => $office->id,
            'employee_id' => Employee::factory()->create(['tenant_id' => $office->id, 'branch_id' => $hq->id])->id,
            'geofence_verified' => true,
            'metadata' => ['ip_address' => '203.0.113.60'],
            'date' => '2026-10-0'.$i,
        ]);
    }
    $c = Employee::factory()->create(['tenant_id' => $office->id, 'branch_id' => $hq->id]);
    $d = Employee::factory()->create(['tenant_id' => $office->id, 'branch_id' => $hq->id]);
    arPunch($office, $c, ip: '203.0.113.60');
    expect(arPunch($office, $d, ip: '203.0.113.60')->risk_reasons ?? [])->not->toHaveKey('ip_burst');
});

test('checking out on a different phone than the one checked in on counts', function () {
    [$tenant, $branch] = arOrganisation();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    arPunch($tenant, $employee, ['device' => PunchSignals::deviceHash('phone-a')]);

    Carbon::setTestNow(Carbon::parse('2026-10-12 17:35', 'Africa/Addis_Ababa')->utc());
    $record = arPunch($tenant, $employee, ['device' => PunchSignals::deviceHash('phone-b')], 'check_out');

    expect($record->risk_reasons)->toHaveKey('device_switch');
});

test('adaptive selfies: always for new staff and after a finding, about one day in five for a clean record', function () {
    [$tenant, $branch] = arOrganisation(['adaptive_selfie' => true]);
    $policies = app(AttendancePolicy::class);
    $policy = $policies->organisation();
    $new = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    expect($policies->selfieRequired($new, $policy, '2026-10-12'))->toBeTrue();

    $veteran = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $veteran->id, 'date' => '2026-05-01']);
    $asked = collect(range(1, 60))
        ->filter(fn (int $d) => $policies->selfieRequired($veteran, $policy, Carbon::parse('2026-10-01')->addDays($d)->format('Y-m-d')))
        ->count();
    expect($asked)->toBeGreaterThan(3)->toBeLessThan(25);

    AttendanceIntegrityFlag::create([
        'tenant_id' => $tenant->id, 'employee_id' => $veteran->id,
        'attendance_record_id' => AttendanceRecord::where('employee_id', $veteran->id)->value('id'),
        'punch' => 'check_in', 'type' => 'outside_geofence', 'severity' => 'high', 'status' => 'rejected',
    ]);
    expect($policies->selfieRequired($veteran, $policy, '2026-10-20'))->toBeTrue();
});

test('the nightly scan files a pair who keep punching on one phone', function () {
    [$tenant, $branch] = arOrganisation();
    $device = PunchSignals::deviceHash('pair-phone');
    $a = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $b = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    foreach (['2026-10-08', '2026-10-09', '2026-10-10'] as $day) {
        $at = Carbon::parse("{$day} 08:25", 'Africa/Addis_Ababa')->utc();
        AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $a->id, 'date' => $day, 'check_in' => $at, 'device_hash' => $device]);
        AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $b->id, 'date' => $day, 'check_in' => $at->copy()->addSeconds(30), 'device_hash' => $device]);
    }

    expect(app(AttendancePatternScan::class)->run($tenant)['buddy'])->toBe(2)
        ->and(app(AttendancePatternScan::class)->run($tenant)['buddy'])->toBe(0)
        ->and(AttendanceIntegrityFlag::where('type', 'buddy_pattern')->pluck('employee_id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

test('the payroll run lists people with serious punches still open', function () {
    [$tenant, $branch] = arOrganisation();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'salary_cents' => 500000, 'hire_date' => '2020-01-01']);
    $record = AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'date' => '2026-10-05']);
    AttendanceIntegrityFlag::create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'attendance_record_id' => $record->id,
        'punch' => 'day', 'type' => 'high_risk', 'severity' => 'medium', 'status' => 'open', 'details' => ['score' => 50],
    ]);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
    PayrollEntry::factory()->create(['tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id]);

    $integrity = app(RunRegisterService::class)->register($run)['integrity'];

    expect($integrity['count'])->toBe(1)
        ->and($integrity['flags'])->toBe(1)
        ->and($integrity['employees'][0]['employee_public_id'])->toBe($employee->public_id);
});
