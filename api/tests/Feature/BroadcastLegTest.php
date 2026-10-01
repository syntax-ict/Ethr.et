<?php

declare(strict_types=1);

use App\Events\AttendanceRecorded;
use App\Events\DeviceOffline;
use App\Events\EmployeeTransitioned;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\Queue;

/**
 * Production runs BROADCAST_CONNECTION=null: there is no Reverb on shared
 * hosting. The notifications already add their `broadcast` channel only when
 * the driver is `reverb`; these three ShouldBroadcast events did not, and
 * Laravel queues a BroadcastEvent job for every ShouldBroadcast event whatever
 * the driver — so every attendance punch queued a job that broadcast to
 * nothing, on a queue a cron call drains once a minute.
 */
dataset('broadcast events', [
    'attendance recorded' => [AttendanceRecorded::class],
    'device offline' => [DeviceOffline::class],
    'employee transitioned' => [EmployeeTransitioned::class],
]);

test('no broadcast job is queued when broadcasting is off', function (string $event) {
    config(['broadcasting.default' => 'null']);

    expect((new ReflectionClass($event))->newInstanceWithoutConstructor()->broadcastWhen())->toBeFalse();
})->with('broadcast events');

test('the broadcast leg returns when a real broadcaster is configured', function (string $event) {
    config(['broadcasting.default' => 'reverb']);

    expect((new ReflectionClass($event))->newInstanceWithoutConstructor()->broadcastWhen())->toBeTrue();
})->with('broadcast events');

test('an attendance punch with broadcasting off queues no broadcast job', function () {
    config(['broadcasting.default' => 'null']);
    Queue::fake();

    $tenant = createTenant();
    event(new AttendanceRecorded(AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id,
    ])));

    Queue::assertNotPushed(BroadcastEvent::class);
});
