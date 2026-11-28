<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\RollForwardHolidaysJob;
use App\Jobs\ScanAttendanceAnomaliesJob;
use App\Jobs\ScanMissingPunchesJob;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AttendanceAnomalyNotification;
use App\Notifications\MissingPunchNotification;
use App\Services\Attendance\AttendanceIntelligence;
use App\Services\CurrentTenant;
use App\Services\Holiday\HolidayService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Scheduled jobs handled twice for the same input. The scheduler can run a job
 * again after a timeout or a missed tick, and a failed attempt is retried.
 */

afterEach(fn () => Carbon::setTestNow());

/**
 * An employee and a supervisor, each with a login, and a day on which the
 * employee has some attendance for the scans to find.
 *
 * @return array{tenant: Tenant, employee: Employee, employeeUser: User, supervisorUser: User}
 */
function idemScanPeople(): array
{
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $supervisorUser = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);
    $supervisor->update(['user_id' => $supervisorUser->id]);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id]);
    $employeeUser = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $employee->update(['user_id' => $employeeUser->id]);

    return compact('tenant', 'employee', 'employeeUser', 'supervisorUser');
}

/** In-app notifications a user holds, by notification class. */
function idemInbox(User $user, string $class): int
{
    return DB::table('notifications')
        ->where('notifiable_type', $user->getMorphClass())
        ->where('notifiable_id', $user->id)
        ->where('type', $class)
        ->count();
}

it('creates the holidays once when RollForwardHolidaysJob is handled twice for the same year', function () {
    $tenant = createTenant();
    Holiday::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => null,
        'name' => 'Idem Founders Day',
        'date' => '2026-06-15',
        'recurring' => true,
        'ethiopian_calendar' => false,
        'is_estimated' => false,
    ]);

    $handle = fn () => (new RollForwardHolidaysJob($tenant->id, 2027))->handle(app(HolidayService::class), app(CurrentTenant::class));

    $handle();
    $afterFirst = Holiday::query()->orderBy('id')->get(['id', 'name', 'date', 'branch_id'])->toArray();
    $handle();
    $afterSecond = Holiday::query()->orderBy('id')->get(['id', 'name', 'date', 'branch_id'])->toArray();

    expect(collect($afterFirst)->where('name', 'Idem Founders Day'))->toHaveCount(2)
        ->and(count($afterFirst))->toBeGreaterThan(2)
        ->and($afterSecond)->toBe($afterFirst);
});

it('tells the employee and the supervisor again when ScanMissingPunchesJob is handled twice, today: each gets a second in-app notification for the same day', function () {
    ['tenant' => $tenant, 'employee' => $employee, 'employeeUser' => $employeeUser, 'supervisorUser' => $supervisorUser] = idemScanPeople();
    AttendanceRecord::factory()->checkInOnly()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => '2026-10-05',
        'check_in' => Carbon::parse('2026-10-05 08:30'),
    ]);

    $handle = fn () => (new ScanMissingPunchesJob($tenant->id, '2026-10-05'))->handle(app(AttendanceIntelligence::class), app(CurrentTenant::class));
    $class = MissingPunchNotification::class;

    $handle();
    expect(idemInbox($employeeUser, $class))->toBe(1)
        ->and(idemInbox($supervisorUser, $class))->toBe(1);

    $handle();
    expect(idemInbox($employeeUser, $class))->toBe(2)
        ->and(idemInbox($supervisorUser, $class))->toBe(2)
        ->and(AttendanceRecord::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

it('tells the supervisor again when ScanAttendanceAnomaliesJob is handled twice, today: a second in-app notification for the same anomaly', function () {
    ['tenant' => $tenant, 'employee' => $employee, 'supervisorUser' => $supervisorUser] = idemScanPeople();
    $date = now()->subDay()->toDateString();

    // More than sixteen worked hours trips AttendanceIntelligence::detectAnomalies().
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => $date,
        'check_in' => now()->subDay()->setTime(4, 0),
        'check_out' => now()->subDay()->setTime(23, 0),
    ]);

    $handle = fn () => (new ScanAttendanceAnomaliesJob($tenant->id, $date))->handle(app(AttendanceIntelligence::class), app(CurrentTenant::class));
    $class = AttendanceAnomalyNotification::class;

    $handle();
    $afterFirst = idemInbox($supervisorUser, $class);
    expect($afterFirst)->toBeGreaterThanOrEqual(1);

    $handle();
    expect(idemInbox($supervisorUser, $class))->toBe($afterFirst * 2)
        ->and(AttendanceRecord::query()->where('employee_id', $employee->id)->count())->toBe(1);
});
