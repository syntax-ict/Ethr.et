<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Services\Device\DevicePunchKey;
use App\Services\Observability\QueueHealth;
use Carbon\Carbon;

afterEach(fn () => Carbon::setTestNow());

it('leaves a key of 64 characters or less alone', function () {
    $key = 'webhook:hik:1:1001:2026-10-14T08:02:00+03:00';

    expect(DevicePunchKey::fit($key))->toBe($key)
        ->and(DevicePunchKey::fit(str_repeat('a', 64)))->toBe(str_repeat('a', 64));
});

it('shortens a longer key to 63 characters, and the same way twice', function () {
    $key = 'webhook:hik:1:'.str_repeat('9', 70).':2026-10-14T08:02:00+03:00';
    $fitted = DevicePunchKey::fit($key);

    expect(mb_strlen($fitted))->toBe(63)
        ->and($fitted)->toStartWith('dk:')
        ->and(DevicePunchKey::fit($fitted))->toBe($fitted)
        ->and(DevicePunchKey::fit($key.'x'))->not->toBe($fitted);
});

it('drains the attendance queue before default', function () {
    expect(QueueHealth::QUEUES)->toBe(['attendance', 'default', 'notifications', 'exports']);
});

it('records the in and the out of the longest badge an employee holds, and a resend changes nothing', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-14 17:30:00', 'Africa/Addis_Ababa'));
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Device::factory()->hikvision()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'connection_config' => ['mode' => 'push'], 'webhook_token' => 'key-token',
    ]);
    // employees.badge_number holds 50 characters (MySQL refuses more), and
    // with it the device key is over the 64 attendance_records allows.
    $badge = str_repeat('7', 50);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'badge_number' => $badge]);

    $post = fn (string $time) => test()->withHeader('X-Webhook-Token', 'key-token')
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/hikvision", [
            'dateTime' => "2026-10-14T{$time}+03:00",
            'eventType' => 'AccessControllerEvent',
            'AccessControllerEvent' => ['majorEventType' => 5, 'subEventType' => 75, 'employeeNoString' => $badge, 'attendanceStatus' => 'undefined'],
        ])->assertOk();

    $post('08:00:00');
    $post('09:00:00');
    $post('08:00:00');
    $post('09:00:00');

    $records = AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->get();
    expect($records)->toHaveCount(1)
        ->and($records->first()->check_in)->not->toBeNull()
        ->and($records->first()->check_out)->not->toBeNull()
        ->and(mb_strlen((string) $records->first()->idempotency_key))->toBeLessThanOrEqual(64);
});
