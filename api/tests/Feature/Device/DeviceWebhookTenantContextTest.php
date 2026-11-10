<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\UnmatchedDevicePunch;
use App\Services\CurrentTenant;
use Carbon\Carbon;

/**
 * A terminal's webhook carries nothing that names its organisation: it posts
 * to the address the app gave it, which is the deployment's own, and it cannot
 * send `X-Tenant`. Its token finds the device, and the device is the only
 * thing that says whose punch this is.
 *
 * Until 2026-11-10 the handlers stopped there. The request was never given the
 * device's organisation as its context, so the engine looked the employee up
 * with none, found nobody (the scope fails closed), and the terminal was
 * answered `processed` with nothing recorded and nothing held. Every other
 * test of these handlers passed, because `createTenant()` leaves the
 * organisation set in the test's own process, where a real request has none.
 *
 * These tests take that away first, and send the punch to the apex.
 */
const CONTEXT_BADGE = '7301';
const CONTEXT_NOW = '2026-11-03 09:00:00';

afterEach(fn () => Carbon::setTestNow());

/** @return array{tenant: Tenant, employee: Employee} */
function contextFixture(string $adapter): array
{
    Carbon::setTestNow(Carbon::parse(CONTEXT_NOW, 'UTC'));

    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'badge_number' => CONTEXT_BADGE,
        'hire_date' => '2024-01-01',
    ]);
    Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => $adapter,
        'serial_number' => 'CTX-'.strtoupper($adapter),
        'webhook_token' => "context-{$adapter}-token",
        'timezone' => 'Africa/Addis_Ababa',
    ]);

    // A second organisation: an install with one tenant resolves it for
    // every request, which would hide exactly what this is about.
    Tenant::factory()->create();

    return ['tenant' => $tenant, 'employee' => $employee];
}

/** The punch as the terminal sends it: to the apex, with no organisation anywhere. */
function contextWebhook(string $adapter, array $body)
{
    // What `createTenant()` left behind in this process is not in a real request.
    app()->forgetScopedInstances();
    expect(app(CurrentTenant::class)->get())->toBeNull();

    return test()->withHeader('X-Webhook-Token', "context-{$adapter}-token")
        ->postJson("http://ethr.test/api/v1/devices/webhook/{$adapter}", $body);
}

/** @return array<string, mixed> */
function contextBody(string $adapter, string $badge): array
{
    $at = Carbon::parse(CONTEXT_NOW, 'UTC')->subMinutes(30)->setTimezone('Africa/Addis_Ababa');

    return match ($adapter) {
        'zkteco' => ['records' => [['pin' => $badge, 'timestamp' => $at->format('Y-m-d H:i:s'), 'punch' => 0]]],
        'suprema' => ['events' => [['user_id' => $badge, 'datetime' => $at->toIso8601String(), 'event_type_id' => 0x1000]]],
        'hikvision' => [
            'dateTime' => $at->toIso8601String(),
            'eventType' => 'AccessControllerEvent',
            'AccessControllerEvent' => [
                'majorEventType' => 5, 'subEventType' => 75,
                'employeeNoString' => $badge,
                'attendanceStatus' => 'checkIn',
            ],
        ],
    };
}

it('records a punch whose only link to its organisation is the terminal\'s token', function (string $adapter) {
    ['tenant' => $tenant, 'employee' => $employee] = contextFixture($adapter);

    $response = contextWebhook($adapter, contextBody($adapter, CONTEXT_BADGE))->assertOk();
    if ($adapter !== 'hikvision') {
        $response->assertJsonPath('count', 1);
    }

    app(CurrentTenant::class)->set($tenant);
    $record = AttendanceRecord::query()->where('employee_id', $employee->id)->sole();

    expect($record->tenant_id)->toBe($tenant->id)
        ->and($record->source)->toBe(AttendanceSource::BIOMETRIC)
        ->and($record->check_in->toDateTimeString())->toBe('2026-11-03 08:30:00');
})->with(['zkteco', 'suprema', 'hikvision']);

it('holds an unknown badge for the terminal\'s own organisation', function (string $adapter) {
    ['tenant' => $tenant] = contextFixture($adapter);

    contextWebhook($adapter, contextBody($adapter, '9999'))->assertOk();

    app(CurrentTenant::class)->set($tenant);
    $held = UnmatchedDevicePunch::query()->sole();

    expect($held->tenant_id)->toBe($tenant->id)
        ->and(AttendanceRecord::query()->count())->toBe(0);
})->with(['zkteco', 'suprema', 'hikvision']);
