<?php

declare(strict_types=1);

use App\Contracts\DeviceAdapter;
use App\Enums\AttendanceStatus;
use App\Enums\CorrectionStatus;
use App\Enums\ExpectedDay;
use App\Enums\UserRole;
use App\Jobs\PullDeviceEventsJob;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\BranchAttendanceOverride;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\KioskSession;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\ExpectedDays;
use App\Services\Attendance\QrCodeService;
use App\Services\CurrentTenant;
use App\Services\Device\DeviceManager;
use App\Services\Device\MockAdapter;
use App\Services\Identity\IdentityResolver;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * Every way a punch reaches ETHR must leave payroll the same day to pay
 * (2026-10-27, PR 0 of the attendance-to-payroll reconciliation plan).
 *
 * One person works one day, and that day is captured once through each
 * channel: the three vendor webhooks, ZKTeco ADMS, a pull from a device, the
 * phone, the web, a kiosk live and offline, the signed-in kiosk, a QR code,
 * HR's manual entry, a CSV import, the phone's offline sync, and a missing
 * check-out filled by an approved correction. Each must leave exactly one
 * record that payroll reads identically: the same work date, the same status,
 * the same overtime buckets from PayrollEngine, and the same day from
 * ExpectedDays.
 *
 * The day is one of four that channels have got wrong before: a night shift
 * crossing midnight, a holiday at the employee's branch only, a branch whose
 * attendance rules override the organisation's, and a person with no shift to
 * be rated against. A holiday at another
 * branch on the same date sits in every fixture, so a channel that reads the
 * organisation's calendar instead of the branch's is seen too.
 */
const PARITY_ZONE = 'Africa/Addis_Ababa';

/** A Tuesday. */
const PARITY_DAY = '2026-10-13';

const PARITY_BADGE = '7001';

const PARITY_CODE = 'PAR-1';

/**
 * Each scenario's shift, punches (wall clock in PARITY_ZONE) and what payroll
 * must read from them.
 *
 * @return array<string, array{shift: ?array<string, mixed>, in: string, out: string, status: AttendanceStatus, buckets: array<string, int>, day: ExpectedDay, branch_holiday: bool, override: bool}>
 */
function parityScenarios(): array
{
    $dayShift = ['start_time' => '08:00', 'end_time' => '17:00', 'crosses_midnight' => false, 'working_days' => '1,2,3,4,5'];

    return [
        // 22:00-06:00, out at 07:30: ninety minutes of daytime overtime on
        // the night the shift began, not the morning it ended.
        'night shift crossing midnight' => [
            'shift' => ['start_time' => '22:00', 'end_time' => '06:00', 'crosses_midnight' => true, 'working_days' => '1,2,3,4,5,6,7'],
            'in' => PARITY_DAY.' 21:58',
            'out' => '2026-10-14 07:30',
            'status' => AttendanceStatus::PRESENT,
            'buckets' => ['normal' => 90],
            'day' => ExpectedDay::WORKDAY,
            'branch_holiday' => false,
            'override' => false,
        ],
        // The whole day worked is holiday time (Art. 68(1)).
        'branch-specific holiday' => [
            'shift' => $dayShift,
            'in' => PARITY_DAY.' 08:00',
            'out' => PARITY_DAY.' 17:00',
            'status' => AttendanceStatus::PRESENT,
            'buckets' => ['holiday' => 540],
            'day' => ExpectedDay::HOLIDAY,
            'branch_holiday' => true,
            'override' => false,
        ],
        // The organisation allows web punches only and wants a PIN, a selfie
        // and a location; the branch allows every method and none of those.
        // Forty minutes late against fifteen of grace, an hour over.
        'branch attendance override' => [
            'shift' => $dayShift,
            'in' => PARITY_DAY.' 08:40',
            'out' => PARITY_DAY.' 18:00',
            'status' => AttendanceStatus::LATE,
            'buckets' => ['normal' => 60],
            'day' => ExpectedDay::WORKDAY,
            'branch_holiday' => false,
            'override' => true,
        ],
        // No assignment and no default shift. The engine leaves such a punch
        // `pending`, with nothing to rate it against, and AttendanceHealth
        // counts it as a missing shift set-up. With no shift there is no
        // overtime window, and a Tuesday is a working day of the
        // organisation's week, so payroll pays no overtime.
        'no shift assigned' => [
            'shift' => null,
            'in' => PARITY_DAY.' 08:00',
            'out' => PARITY_DAY.' 17:00',
            'status' => AttendanceStatus::PENDING,
            'buckets' => [],
            'day' => ExpectedDay::WORKDAY,
            'branch_holiday' => false,
            'override' => false,
        ],
    ];
}

/**
 * @param  array{shift: ?array<string, mixed>, in: string, out: string, branch_holiday: bool, override: bool}  $scenario
 * @return array{tenant: Tenant, branch: Branch, shift: ?Shift, employee: Employee, worker: User, hr: User, in: Carbon, out: Carbon}
 */
function parityFixture(array $scenario): array
{
    $in = Carbon::parse($scenario['in'], PARITY_ZONE)->utc();
    $out = Carbon::parse($scenario['out'], PARITY_ZONE)->utc();
    Carbon::setTestNow($in->copy()->subHours(2));

    $tenant = createTenant(['timezone' => PARITY_ZONE]);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $elsewhere = Branch::factory()->create(['tenant_id' => $tenant->id]);

    Holiday::factory()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $elsewhere->id,
        'date' => PARITY_DAY, 'recurring' => false,
    ]);
    if ($scenario['branch_holiday']) {
        Holiday::factory()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
            'date' => PARITY_DAY, 'recurring' => false,
        ]);
    }

    if ($scenario['override']) {
        AttendanceSetting::create([
            ...AttendanceSetting::defaults(),
            'tenant_id' => $tenant->id,
            'enabled_methods' => ['web'],
            'kiosk_pin_required' => true,
            'mobile_photo_required' => true,
            'geofence_required' => true,
        ]);
        BranchAttendanceOverride::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'overrides' => [
                'enabled_methods' => ['biometric', 'mobile', 'qr', 'kiosk', 'web', 'manual', 'csv'],
                'kiosk_pin_required' => false,
                'mobile_photo_required' => false,
                'geofence_required' => false,
            ],
        ]);
    }

    $shift = $scenario['shift'] === null ? null : Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'grace_minutes' => 15,
        'early_departure_minutes' => 15,
        ...$scenario['shift'],
    ]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'employee_code' => PARITY_CODE,
        'badge_number' => PARITY_BADGE,
        'hire_date' => '2024-01-01',
    ]);
    if ($shift !== null) {
        ShiftAssignment::factory()->create([
            'tenant_id' => $tenant->id,
            'shift_id' => $shift->id,
            'assignable_type' => Employee::class,
            'assignable_id' => $employee->id,
        ]);
    }

    return [
        'tenant' => $tenant,
        'branch' => $branch,
        'shift' => $shift,
        'employee' => $employee,
        'worker' => createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant),
        'hr' => createUser(['role' => UserRole::HR_ADMIN], $tenant),
        'in' => $in,
        'out' => $out,
    ];
}

function parityUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

/** The punch's wall clock where it was made. */
function parityLocal(Carbon $moment, string $format = 'Y-m-d H:i:s'): string
{
    return $moment->copy()->setTimezone(PARITY_ZONE)->format($format);
}

/**
 * A live channel sends each punch as it happens; a pushing device a minute
 * later.
 *
 * @param  callable(string, Carbon): mixed  $send  given 'check_in' or 'check_out' and the punch's moment
 */
function parityLive(array $ctx, callable $send, int $delayMinutes = 0): void
{
    foreach (['check_in' => $ctx['in'], 'check_out' => $ctx['out']] as $type => $moment) {
        Carbon::setTestNow($moment->copy()->addMinutes($delayMinutes));
        $send($type, $moment);
    }
}

/** A channel that reports the day after it: a backlog, a sheet, an outage. */
function parityAfterwards(array $ctx): void
{
    Carbon::setTestNow($ctx['out']->copy()->addMinutes(30));
}

function parityDevice(array $ctx, string $adapter, array $attributes = []): Device
{
    return Device::factory()->create([
        'tenant_id' => $ctx['tenant']->id,
        'branch_id' => $ctx['branch']->id,
        'adapter_type' => $adapter,
        'serial_number' => 'PAR-'.strtoupper($adapter),
        'webhook_token' => "parity-{$adapter}-token",
        ...$attributes,
    ]);
}

function parityWebhook(array $ctx, string $vendor, array $body): TestResponse
{
    return test()->withHeader('X-Webhook-Token', "parity-{$vendor}-token")
        ->postJson(parityUrl($ctx['tenant'], "devices/webhook/{$vendor}"), $body)
        ->assertOk();
}

function parityHikvision(array $ctx): void
{
    parityDevice($ctx, 'hikvision');

    parityLive($ctx, fn (string $type, Carbon $at) => parityWebhook($ctx, 'hikvision', [
        'dateTime' => $at->copy()->setTimezone(PARITY_ZONE)->toIso8601String(),
        'eventType' => 'AccessControllerEvent',
        'AccessControllerEvent' => [
            'majorEventType' => 5, 'subEventType' => 75,
            'employeeNoString' => PARITY_BADGE,
            'attendanceStatus' => $type === 'check_in' ? 'checkIn' : 'checkOut',
        ],
    ])->assertJsonPath('status', 'processed'), delayMinutes: 1);
}

function parityZkteco(array $ctx): void
{
    parityDevice($ctx, 'zkteco');

    // No zone on the wire: the terminal's clock is read in the device's.
    parityLive($ctx, fn (string $type, Carbon $at) => parityWebhook($ctx, 'zkteco', [
        'records' => [['pin' => PARITY_BADGE, 'timestamp' => parityLocal($at), 'punch' => $type === 'check_in' ? 0 : 1]],
    ])->assertJsonPath('count', 1), delayMinutes: 1);
}

function paritySuprema(array $ctx): void
{
    parityDevice($ctx, 'suprema');

    // BioStar event types: 0x1000 is an arrival, 0x2000 a departure.
    parityLive($ctx, fn (string $type, Carbon $at) => parityWebhook($ctx, 'suprema', [
        'events' => [[
            'user_id' => PARITY_BADGE,
            'datetime' => $at->copy()->setTimezone(PARITY_ZONE)->toIso8601String(),
            'event_type_id' => $type === 'check_in' ? 0x1000 : 0x2000,
        ]],
    ])->assertJsonPath('count', 1), delayMinutes: 1);
}

function parityAdms(array $ctx): void
{
    $device = parityDevice($ctx, 'zkteco_adms', [
        'webhook_token' => null,
        'connection_config' => ['mode' => 'adms'],
        'webhook_ip_allowlist' => ['127.0.0.1'],
    ]);

    parityLive($ctx, function (string $type, Carbon $at) use ($device) {
        $state = $type === 'check_in' ? 0 : 1;
        $uri = '/iclock/cdata?'.http_build_query(['SN' => $device->serial_number, 'table' => 'ATTLOG']);

        test()->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], PARITY_BADGE."\t".parityLocal($at)."\t{$state}\t1\t0\t0\t0\n")
            ->assertOk()
            ->assertSeeText('OK: 1');
    }, delayMinutes: 1);
}

function parityPull(array $ctx): void
{
    $device = parityDevice($ctx, 'mock', ['webhook_token' => null, 'last_sync_at' => null]);

    // The mock adapter invents its punches; this one replays the day, as the
    // existing pull tests route the `mock` adapter to a fake.
    $fake = new class implements DeviceAdapter
    {
        /** @var list<array{employee_badge: string, timestamp: string, type: string}> */
        public array $events = [];

        public function connect(Device $device): bool
        {
            return true;
        }

        public function getStatus(Device $device): array
        {
            return ['online' => true];
        }

        public function getDeviceInfo(Device $device): array
        {
            return [];
        }

        public function pullEvents(Device $device, ?string $since = null): array
        {
            $after = $since !== null ? strtotime($since) : PHP_INT_MIN;

            return array_values(array_filter($this->events, fn (array $e): bool => strtotime($e['timestamp']) > $after));
        }

        public function pullEnrollments(Device $device): array
        {
            return [];
        }

        public function pushEventUrl(Device $device, string $callbackUrl): bool
        {
            return false;
        }
    };
    foreach (['check_in' => $ctx['in'], 'check_out' => $ctx['out']] as $type => $at) {
        $fake->events[] = ['employee_badge' => PARITY_BADGE, 'timestamp' => $at->copy()->setTimezone(PARITY_ZONE)->format('Y-m-d\TH:i:sP'), 'type' => $type];
    }
    app()->instance(MockAdapter::class, $fake);

    parityAfterwards($ctx);
    (new PullDeviceEventsJob($device, 'manual'))->handle(
        app(DeviceManager::class),
        app(AttendanceEngine::class),
        app(IdentityResolver::class),
    );
}

function parityMobile(array $ctx): void
{
    test()->actingAs($ctx['worker']);

    parityLive($ctx, fn (string $type) => test()->postJson(parityUrl($ctx['tenant'], 'attendance/mobile/'.($type === 'check_in' ? 'check-in' : 'check-out')), [
        'idempotency_key' => "parity-mobile-{$type}",
        'latitude' => 9.0192,
        'longitude' => 38.7525,
    ])->assertSuccessful());
}

function parityWeb(array $ctx): void
{
    test()->actingAs($ctx['worker']);

    parityLive($ctx, fn (string $type) => test()->postJson(parityUrl($ctx['tenant'], 'attendance/'.($type === 'check_in' ? 'check-in' : 'check-out')), [
        'idempotency_key' => "parity-web-{$type}",
    ])->assertSuccessful());
}

function parityKioskSession(array $ctx): KioskSession
{
    $session = KioskSession::factory()->create([
        'tenant_id' => $ctx['tenant']->id,
        'branch_id' => $ctx['branch']->id,
        'status' => 'active',
    ]);
    app(CurrentTenant::class)->forget();

    return $session;
}

/** The shared terminal at the branch: an employee code, and no PIN, which the branch does not ask for. */
function parityKioskPin(array $ctx): void
{
    $session = parityKioskSession($ctx);

    parityLive($ctx, function (string $type) use ($ctx, $session) {
        app(CurrentTenant::class)->forget();
        test()->postJson(parityUrl($ctx['tenant'], 'kiosk/check-in'), [
            'idempotency_key' => "parity-kiosk-{$type}",
            'employee_code' => PARITY_CODE,
            'type' => $type,
        ], ['X-Kiosk-Token' => $session->token])->assertSuccessful();
    });
}

/** Sealed at the terminal during an outage, sent when the connection returns. */
function parityKioskSync(array $ctx): void
{
    $session = parityKioskSession($ctx);
    $offline = test()->postJson(parityUrl($ctx['tenant'], 'kiosk/authenticate'), ['token' => $session->token])
        ->assertOk()
        ->json('offline');

    $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($offline['public_key'], 64, "\n")."-----END PUBLIC KEY-----\n";
    $records = [];
    foreach (['check_in' => $ctx['in'], 'check_out' => $ctx['out']] as $type => $at) {
        $key = "parity-kiosk-sync-{$type}";
        openssl_public_encrypt((string) json_encode([
            'c' => PARITY_CODE,
            'p' => null,
            't' => $type,
            'at' => $at->toIso8601String(),
            'k' => $key,
        ]), $sealed, $pem, OPENSSL_PKCS1_OAEP_PADDING);
        $records[] = ['idempotency_key' => $key, 'sealed' => base64_encode($sealed), 'grant' => $offline['grant']];
    }

    parityAfterwards($ctx);
    app(CurrentTenant::class)->forget();
    test()->postJson(parityUrl($ctx['tenant'], 'kiosk/sync'), ['records' => $records], ['X-Kiosk-Token' => $session->token])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'created')
        ->assertJsonPath('results.1.status', 'created');
}

/** The signed-in kiosk screen HR runs for someone at the desk. */
function paritySignedInKiosk(array $ctx): void
{
    test()->actingAs($ctx['hr']);

    parityLive($ctx, fn (string $type) => test()->postJson(parityUrl($ctx['tenant'], 'attendance/kiosk'), [
        'idempotency_key' => "parity-desk-{$type}",
        'employee_code' => PARITY_CODE,
        'type' => $type,
    ])->assertSuccessful());
}

function parityQr(array $ctx): void
{
    test()->actingAs($ctx['worker']);

    parityLive($ctx, function (string $type) use ($ctx) {
        $code = app(QrCodeService::class)->generate($ctx['branch'], null, 30);

        test()->postJson(parityUrl($ctx['tenant'], 'attendance/qr'), [
            'idempotency_key' => "parity-qr-{$type}",
            'qr_token' => $code['token'],
            'type' => $type,
            'latitude' => 9.0192,
            'longitude' => 38.7525,
        ])->assertSuccessful();
    });
}

function parityManual(array $ctx): void
{
    test()->actingAs($ctx['hr']);
    parityAfterwards($ctx);

    test()->postJson(parityUrl($ctx['tenant'], 'attendance/manual'), [
        'idempotency_key' => 'parity-manual',
        'employee_public_id' => $ctx['employee']->public_id,
        'date' => PARITY_DAY,
        'check_in' => parityLocal($ctx['in'], 'H:i'),
        'check_out' => parityLocal($ctx['out'], 'H:i'),
        'reason' => 'Read off the paper register',
    ])->assertCreated();
}

function parityCsv(array $ctx): void
{
    test()->actingAs($ctx['hr']);
    parityAfterwards($ctx);

    test()->postJson(parityUrl($ctx['tenant'], 'attendance/import/commit'), [
        'import_key' => 'parity-import',
        'rows' => [[
            'employee_code' => PARITY_CODE,
            'date' => PARITY_DAY,
            'check_in' => parityLocal($ctx['in'], 'H:i'),
            'check_out' => parityLocal($ctx['out'], 'H:i'),
        ]],
    ])->assertCreated()->assertJsonPath('created', 1);
}

function parityOfflineMobile(array $ctx): void
{
    test()->actingAs($ctx['worker']);
    parityAfterwards($ctx);

    $records = [];
    foreach (['check_in' => $ctx['in'], 'check_out' => $ctx['out']] as $type => $at) {
        $records[] = [
            'idempotency_key' => "parity-offline-{$type}",
            'employee_public_id' => $ctx['employee']->public_id,
            'type' => $type,
            'timestamp' => $at->copy()->setTimezone(PARITY_ZONE)->toIso8601String(),
            'offline_token' => 'parity-offline-grant',
        ];
    }

    test()->postJson(parityUrl($ctx['tenant'], 'attendance/sync'), ['records' => $records])
        ->assertOk()
        ->assertJsonPath('summary.created', 2);
}

/** Checked in on the phone, forgot to check out; HR approves the missing punch. */
function parityCorrection(array $ctx): void
{
    test()->actingAs($ctx['worker']);
    Carbon::setTestNow($ctx['in']);
    test()->postJson(parityUrl($ctx['tenant'], 'attendance/check-in'), ['idempotency_key' => 'parity-correction-in'])
        ->assertSuccessful();

    parityAfterwards($ctx);
    $record = AttendanceRecord::query()->where('employee_id', $ctx['employee']->id)->sole();
    $correction = AttendanceCorrection::factory()->create([
        'tenant_id' => $ctx['tenant']->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $ctx['employee']->id,
        'proposed_check_in' => null,
        'proposed_check_out' => $ctx['out'],
        'status' => CorrectionStatus::PENDING,
    ]);

    test()->actingAs($ctx['hr']);
    test()->putJson(parityUrl($ctx['tenant'], "attendance/corrections/{$correction->public_id}/approve"))
        ->assertOk();
}

const PARITY_CHANNELS = [
    'Hikvision webhook' => 'parityHikvision',
    'ZKTeco webhook' => 'parityZkteco',
    'Suprema webhook' => 'paritySuprema',
    'ZKTeco ADMS' => 'parityAdms',
    'device pull' => 'parityPull',
    'mobile' => 'parityMobile',
    'web' => 'parityWeb',
    'kiosk PIN' => 'parityKioskPin',
    'kiosk offline sync' => 'parityKioskSync',
    'signed-in kiosk' => 'paritySignedInKiosk',
    'QR scan' => 'parityQr',
    'manual entry' => 'parityManual',
    'CSV import' => 'parityCsv',
    'offline mobile sync' => 'parityOfflineMobile',
    'approved correction' => 'parityCorrection',
];

afterEach(fn () => Carbon::setTestNow());

it('leaves payroll the same day whichever channel captured it', function (string $scenarioName, string $channel) {
    $scenario = parityScenarios()[$scenarioName];
    $ctx = parityFixture($scenario);

    (PARITY_CHANNELS[$channel])($ctx);

    app(CurrentTenant::class)->set($ctx['tenant']);
    $employee = $ctx['employee']->fresh();

    $records = AttendanceRecord::withoutGlobalScopes()
        ->where('tenant_id', $ctx['tenant']->id)
        ->where('employee_id', $employee->id)
        ->where('status', '!=', AttendanceStatus::VOIDED)
        ->get();
    expect($records)->toHaveCount(1);
    $record = $records->first();

    expect($record->date->format('Y-m-d'))->toBe(PARITY_DAY)
        ->and($record->status)->toBe($scenario['status'])
        ->and($record->check_in?->equalTo($ctx['in']))->toBeTrue()
        ->and($record->check_out?->equalTo($ctx['out']))->toBeTrue();

    $payroll = app(PayrollEngine::class);
    $overtime = (fn (Employee $e, Carbon $from, Carbon $to) => $this->gatherOvertimeByType($e, $from, $to))
        ->call($payroll, $employee, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    expect($overtime['buckets'])->toBe([
        'normal' => 0, 'night' => 0, 'rest_day' => 0, 'holiday' => 0, 'holiday_night' => 0,
        ...$scenario['buckets'],
    ]);

    $days = app(ExpectedDays::class)->forEmployee($employee, Carbon::parse(PARITY_DAY), Carbon::parse(PARITY_DAY));
    expect($days[PARITY_DAY])->toBe($scenario['day']);
})->with(array_combine(array_keys(parityScenarios()), array_map(fn ($name) => [$name], array_keys(parityScenarios()))))
    ->with(array_combine(array_keys(PARITY_CHANNELS), array_map(fn ($name) => [$name], array_keys(PARITY_CHANNELS))));
