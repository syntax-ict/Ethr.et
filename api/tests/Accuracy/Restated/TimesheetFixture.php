<?php

declare(strict_types=1);

namespace Tests\Accuracy\Restated;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;

/**
 * A small organisation and a fortnight of attendance for the timesheet outputs:
 * three people in two departments, with late and early punches, an absence, a
 * punch with no check-out, annual and sick leave, a public holiday, work on a
 * rest day and on the holiday, and overtime running past the night line.
 *
 * It writes records; it never says what the timesheet should show. The ledger
 * is what the engine answers, and each output is compared with it.
 *
 * "Now" is Friday 2026-10-23 10:00 in Addis Ababa, so yesterday is the 22nd.
 * The span is Monday 2026-10-12 to Sunday 2026-10-25.
 *
 * Static only: Pest loads every test file into one process.
 */
final class TimesheetFixture
{
    public const FROM = '2026-10-12';

    public const TO = '2026-10-25';

    public const ZONE = 'Africa/Addis_Ababa';

    /** @return array{tenant: Tenant, viewer: User, employees: list<Employee>} */
    public static function build(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-10-23 10:00', self::ZONE));

        $tenant = createTenant(['timezone' => self::ZONE]);
        $viewer = actingAsUser(['role' => UserRole::TENANT_ADMIN, 'display_name' => 'Hana Tadesse'], $tenant);
        $shift = Shift::factory()->default()->create(['tenant_id' => $tenant->id]);

        $finance = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);
        $operations = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Operations']);

        $abebe = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $finance->id, 'name' => 'Abebe Kebede', 'hire_date' => '2025-01-01', 'salary_cents' => 1_000_000]);
        $bekele = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $operations->id, 'name' => 'Bekele Tola', 'hire_date' => '2025-01-01', 'salary_cents' => 1_000_000]);
        $chaltu = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $operations->id, 'name' => 'Chaltu Bayu', 'hire_date' => '2026-10-14', 'salary_cents' => 1_000_000]);

        Holiday::factory()->create(['tenant_id' => $tenant->id, 'date' => '2026-10-15', 'branch_id' => null, 'is_active' => true, 'recurring' => false]);

        $punch = static function (Employee $employee, string $day, ?string $in, ?string $out, AttendanceStatus $status = AttendanceStatus::PRESENT) use ($tenant, $shift): void {
            AttendanceRecord::factory()->create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'date' => $day,
                'check_in' => $in === null ? null : Carbon::parse("{$day} {$in}", self::ZONE)->utc(),
                'check_out' => $out === null ? null : Carbon::parse("{$day} {$out}", self::ZONE)->utc(),
                'status' => $status,
            ]);
        };

        // Abebe: present, late, left early, rest-day work, no check-out, an absence, overtime, annual and sick leave.
        $punch($abebe, '2026-10-12', '08:25', '17:35');
        $punch($abebe, '2026-10-13', '09:10', '17:30', AttendanceStatus::LATE);
        $punch($abebe, '2026-10-14', '08:30', '16:00', AttendanceStatus::EARLY_LEAVE);
        $punch($abebe, '2026-10-17', '09:00', '12:00');
        $punch($abebe, '2026-10-19', '08:30', null, AttendanceStatus::PENDING);
        $punch($abebe, '2026-10-21', '08:30', '20:00');

        // Bekele: overtime past the night line, work on the public holiday and on a rest day, a late day and an early leave.
        $punch($bekele, '2026-10-12', '08:30', '23:30');
        $punch($bekele, '2026-10-13', '08:50', '17:30', AttendanceStatus::LATE);
        $punch($bekele, '2026-10-14', '08:30', '17:30');
        $punch($bekele, '2026-10-15', '09:00', '23:00');
        $punch($bekele, '2026-10-16', '08:30', '17:30');
        $punch($bekele, '2026-10-17', '10:00', '14:00');
        $punch($bekele, '2026-10-19', '08:30', '17:30');
        $punch($bekele, '2026-10-20', '08:30', '17:30');
        $punch($bekele, '2026-10-21', '08:30', '17:30');
        $punch($bekele, '2026-10-22', '08:30', '16:00', AttendanceStatus::EARLY_LEAVE);

        // Chaltu: hired on the 14th, so two days are before her time; an absence on the 20th; annual leave on the 22nd.
        $punch($chaltu, '2026-10-14', '08:30', '17:30');
        $punch($chaltu, '2026-10-16', '08:30', '17:30');
        $punch($chaltu, '2026-10-19', '08:30', '17:30');
        $punch($chaltu, '2026-10-21', '08:20', '17:30');

        $annual = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'annual']);
        $sick = LeaveType::factory()->sick()->create(['tenant_id' => $tenant->id, 'follows_art85' => true]);
        foreach ([[$abebe, $annual, '2026-10-16'], [$abebe, $sick, '2026-10-22'], [$chaltu, $annual, '2026-10-22']] as [$employee, $type, $day]) {
            LeaveRequest::factory()->approved()->create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $day,
                'end_date' => $day,
                'days' => 1,
                'status' => LeaveStatus::APPROVED,
            ]);
        }

        return ['tenant' => $tenant, 'viewer' => $viewer, 'employees' => [$abebe, $bekele, $chaltu]];
    }
}
