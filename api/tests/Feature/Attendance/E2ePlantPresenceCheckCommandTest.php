<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Models\AttendancePresenceCheck;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * The journeys' way of getting a pending presence check at spec time. It must
 * answer what my-shift reads, and it must refuse outside local and testing.
 */

function plantWorld(bool $checkedIn = true): array
{
    $tenant = createTenant(['subdomain' => 'plant-org']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'PL-1']);
    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'date' => now()->toDateString(),
        'check_in' => now()->subHour(), 'check_out' => $checkedIn ? null : now(), 'status' => AttendanceStatus::PRESENT,
    ]);

    return [$tenant, $employee, $record];
}

it('plants one open check on the checked-in record, and a second run replaces rather than repeats it', function () {
    [$tenant, $employee, $record] = plantWorld();

    expect(Artisan::call('e2e:plant-presence-check', ['tenant' => 'plant-org', 'employee' => 'PL-1']))->toBe(0);
    expect(Artisan::call('e2e:plant-presence-check', ['tenant' => 'plant-org', 'employee' => 'PL-1']))->toBe(0);

    app(CurrentTenant::class)->set($tenant);
    $checks = AttendancePresenceCheck::query()->where('employee_id', $employee->id)->get();
    expect($checks)->toHaveCount(1)
        ->and($checks[0]->attendance_record_id)->toBe($record->id)
        ->and($checks[0]->status)->toBe(AttendancePresenceCheck::SENT)
        ->and($checks[0]->expires_at->isFuture())->toBeTrue()
        ->and($checks[0]->answered_at)->toBeNull();
});

it('plants nothing for someone who is not checked in', function () {
    plantWorld(checkedIn: false);

    expect(Artisan::call('e2e:plant-presence-check', ['tenant' => 'plant-org', 'employee' => 'PL-1']))->toBe(1);
    expect(DB::table('attendance_presence_checks')->count())->toBe(0);
});

it('refuses outside local and testing', function () {
    plantWorld();
    app()->detectEnvironment(fn () => 'production');

    expect(Artisan::call('e2e:plant-presence-check', ['tenant' => 'plant-org', 'employee' => 'PL-1']))->toBe(1);
    expect(DB::table('attendance_presence_checks')->count())->toBe(0);
});
