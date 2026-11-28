<?php

declare(strict_types=1);

namespace Tests\Accuracy\Payroll;

use App\Enums\CostSharingStatus;
use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeCostSharing;
use App\Models\EmployeeLoan;
use App\Models\EmployeeSalaryHistory;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRule;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Payroll\StatutoryRules;
use App\Support\SalaryChangeContext;
use Carbon\Carbon;
use Database\Seeders\TaxBracketSeeder;

/**
 * Builds, from a payroll case, the organisation, people, salary history,
 * allowances, loans, cost sharing, leave, overtime and lateness the engine
 * reads (spec 3.3). It states inputs and implements no rule: every figure it
 * writes is a number the case gave, spelt the way a column wants it.
 *
 * A case input the engine cannot be made to take through public code fails with
 * FixtureError::notCoverable(); an input it takes wrongly is found afterwards
 * by EngineReader's echo check.
 */
final class PayrollFixture
{
    public const ZONE = 'Africa/Addis_Ababa';

    /**
     * @param  array<string, mixed>  $case
     * @return array{
     *     tenant: Tenant,
     *     user: User,
     *     employees: array<string, Employee>,
     *     loans: array<string, array<string, string>>,
     *     obligations: array<string, list<array{public_id: string, stored_rate: string}>>,
     *     notes: list<string>
     * }
     */
    public static function build(array $case): array
    {
        $period = $case['period'];
        $org = $case['org'] ?? [];
        $notes = [];

        // Not coverable first: nothing is written for a case that cannot be built.
        foreach ($case['employees'] as $key => $employee) {
            if ($employee['domestic_worker'] ?? false) {
                throw FixtureError::notCoverable("employee {$key}: domestic_worker has no field in the application (PYR-PEN-14)");
            }
        }

        // The clock sits after the period, so a past period is calculated as it would be then.
        Carbon::setTestNow(Carbon::parse($period['end'].' 12:00', self::ZONE)->addDays(5)->utc());
        test()->seed(TaxBracketSeeder::class);

        $settings = ['leave_year' => 'gregorian'];
        if (isset($org['working_days_per_month'])) {
            $settings['working_days_per_month'] = $org['working_days_per_month'];
        }
        if (isset($org['pagume'])) {
            $settings['pagumen_proration_strategy'] = $org['pagume'];
        }
        if (isset($org['pension_scheme'])) {
            $settings['pension_scheme'] = $org['pension_scheme'];
        }

        $tenant = createTenant([
            'organization_type' => $org['organization_type'] ?? 'private',
            'timezone' => self::ZONE,
            'settings' => $settings,
        ]);
        $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

        if ($org['lateness_deductions'] ?? false) {
            AttendanceSetting::create([
                ...AttendanceSetting::defaults(),
                'tenant_id' => $tenant->id,
                'lateness_deduction_enabled' => true,
                'lateness_deduction_basis' => 'accuracy case',
            ]);
        }

        if (($org['overtime_rates_x100'] ?? []) !== []) {
            PayrollRule::query()->create([
                'tenant_id' => $tenant->id, 'name' => 'Overtime rates', 'type' => 'rate', 'category' => 'overtime',
                'formula' => array_map(static fn (int $x): string => self::twoDecimals($x), $org['overtime_rates_x100']),
                'is_taxable' => true, 'is_active' => true, 'sort_order' => 0,
            ]);
        }

        $position = 0;
        foreach ($org['allowances'] ?? [] as $name => $allowance) {
            $position++;
            PayrollRule::query()->create([
                'tenant_id' => $tenant->id,
                'name' => (string) $name,
                'type' => $allowance['type'],
                'category' => 'allowance',
                'formula' => $allowance['type'] === 'fixed'
                    ? ['amount_cents' => $allowance['amount_cents']]
                    : ['percent' => self::twoDecimals($allowance['percent_x100'])],
                'is_taxable' => $allowance['taxable'],
                'is_active' => true,
                'sort_order' => 10 * $position,
            ]);
        }

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $state = ['types' => [], 'shift' => null, 'holidays' => []];

        $employees = [];
        $loans = [];
        $obligations = [];
        $deleted = [];

        foreach ($case['employees'] as $key => $row) {
            $employee = SalaryChangeContext::suppressed(fn (): Employee => Employee::factory()->create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'employee_code' => $key,
                'status' => $row['status'],
                'hire_date' => $row['hire_date'],
                'termination_date' => $row['termination_date'] ?? null,
                'nationality_status' => $row['nationality'] ?? 'ethiopian',
                'salary_cents' => $row['salary_cents'],
            ]));
            $employees[$key] = $employee;

            foreach ($row['salary_history'] ?? [] as $history) {
                EmployeeSalaryHistory::query()->create([
                    'tenant_id' => $tenant->id,
                    'employee_id' => $employee->id,
                    'salary_cents' => $history['cents'],
                    'effective_date' => $history['effective'],
                    'source' => 'edit',
                ]);
            }

            foreach ($row['contracts'] ?? [] as $contract) {
                SalaryChangeContext::suppressed(fn () => EmployeeContract::factory()->create([
                    'tenant_id' => $tenant->id,
                    'employee_id' => $employee->id,
                    'contract_type' => $contract['type'],
                    'start_date' => $contract['start'],
                    'end_date' => $contract['end'] ?? null,
                    'ended_at' => $contract['ended_at'] ?? null,
                    'salary_cents' => $contract['salary_cents'],
                ]));
            }

            self::leave($tenant, $employee, $period, (int) ($org['working_days_per_month'] ?? 22), $row['inputs'] ?? [], $state);
            self::attendance($tenant, $employee, $period, $row['inputs'] ?? [], $state);

            foreach ($row['loans'] ?? [] as $loanKey => $loan) {
                $made = EmployeeLoan::factory()->create([
                    'tenant_id' => $tenant->id,
                    'employee_id' => $employee->id,
                    'amount_cents' => max($loan['remaining_cents'], $loan['instalment_cents']),
                    'remaining_cents' => $loan['remaining_cents'],
                    'monthly_deduction_cents' => $loan['instalment_cents'],
                    'status' => $loan['status'],
                ]);
                $loans[$key][$loanKey] = $made->public_id;
            }

            foreach ($row['cost_sharing'] ?? [] as $share) {
                $made = EmployeeCostSharing::factory()->create([
                    'tenant_id' => $tenant->id,
                    'employee_id' => $employee->id,
                    'deduction_rate_percent' => sprintf('%d.%03d', intdiv($share['rate_pct_x1000'], 1000), $share['rate_pct_x1000'] % 1000),
                    'outstanding_cents' => $share['outstanding_cents'],
                    'total_obligation_cents' => $share['outstanding_cents'],
                    'status' => CostSharingStatus::from($share['status']),
                    'started_on' => $share['started_on'],
                ]);
                $stored = (string) $made->refresh()->deduction_rate_percent;
                $obligations[$key][] = ['public_id' => $made->public_id, 'stored_rate' => $stored];
                if ($share['rate_pct_x1000'] % 10 !== 0) {
                    $notes[] = "employee {$key}: cost-sharing rate {$share['rate_pct_x1000']}/1000 % was stored as {$stored}; SQLite-only, recheck on MariaDB";
                }
            }

            if ($row['deleted'] ?? false) {
                $deleted[] = $employee;
            }
        }

        foreach ($deleted as $employee) {
            $employee->delete();
        }

        // A parameter changed after its rules were read must be read again.
        app(StatutoryRules::class)->forget();
        app(CurrentTenant::class)->set($tenant);

        return compact('tenant', 'user', 'employees', 'loans', 'obligations', 'notes');
    }

    /**
     * Unpaid leave and Art. 85 sick days: approved requests lying wholly inside
     * the period, so the engine uses the stored days (spec 3.3 items 7 and 8).
     *
     * @param  array<string, mixed>  $period
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $state
     */
    private static function leave(Tenant $tenant, Employee $employee, array $period, int $workingDays, array $inputs, array &$state): void
    {
        $periodDays = self::daysBetween($period['start'], $period['end']);
        $thousandths = (int) ($inputs['unpaid_leave_thousandths'] ?? 0);

        if ($thousandths > 0) {
            $dates = intdiv($thousandths + 999, 1000);
            if ($dates > $periodDays) {
                throw FixtureError::notCoverable("employee {$employee->employee_code}: {$thousandths} thousandths of unpaid leave do not fit inside the period");
            }
            $type = $state['types']['unpaid'] ??= LeaveType::factory()->create([
                'tenant_id' => $tenant->id, 'code' => 'unpaid', 'name' => 'Unpaid Leave', 'is_paid' => false,
                'follows_art85' => false, 'default_days' => 0,
            ]);
            LeaveRequest::factory()->approved()->create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $period['start'],
                'end_date' => self::addDays($period['start'], $dates - 1),
                'days' => sprintf('%d.%d', intdiv($thousandths, 1000), intdiv($thousandths % 1000, 100)),
                'status' => LeaveStatus::APPROVED,
            ]);
        }

        $full = (int) ($inputs['sick_days']['full'] ?? 0);
        $half = (int) ($inputs['sick_days']['half'] ?? 0);
        $unpaid = (int) ($inputs['sick_days']['unpaid'] ?? 0);
        $total = $full + $half + $unpaid;
        if ($total === 0) {
            return;
        }

        $m = $workingDays;
        $shape = "full {$full}, half {$half}, unpaid {$unpaid} with {$m} working days";
        if ($full > $m || $half > 2 * $m || $total > $periodDays
            || ($full > 0 && $unpaid > 0 && $half !== 2 * $m)) {
            throw FixtureError::notCoverable("employee {$employee->employee_code}: sick days ({$shape}) cannot be reached by one run of approved requests");
        }

        // The days already taken before the period put the first day of the period in the right tier.
        $prior = match (true) {
            $full > 0 => $m - $full,
            $half > 0 => $unpaid > 0 ? 3 * $m - $half : $m,
            default => 3 * $m,
        };

        $type = $state['types']['sick'] ??= LeaveType::factory()->create([
            'tenant_id' => $tenant->id, 'code' => 'sick', 'name' => 'Sick Leave', 'is_paid' => true,
            'follows_art85' => true, 'counts_calendar_days' => true,
        ]);

        if ($prior > 0) {
            LeaveRequest::factory()->approved()->create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => self::addDays($period['start'], -$prior),
                'end_date' => self::addDays($period['start'], -1),
                'days' => $prior,
                'status' => LeaveStatus::APPROVED,
            ]);
        }
        LeaveRequest::factory()->approved()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => $period['start'],
            'end_date' => self::addDays($period['start'], $total - 1),
            'days' => $total,
            'status' => LeaveStatus::APPROVED,
        ]);
    }

    /**
     * Overtime minutes as attendance records, and decided lateness as a
     * check-in-only record carrying `pay_deduct_minutes` (spec 3.3 items 9, 10).
     *
     * @param  array<string, mixed>  $period
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $state
     */
    private static function attendance(Tenant $tenant, Employee $employee, array $period, array $inputs, array &$state): void
    {
        $minutes = array_filter($inputs['overtime_minutes'] ?? [], static fn (int $n): bool => $n > 0);
        $lateness = (int) ($inputs['lateness_minutes'] ?? 0);
        if ($minutes === [] && $lateness === 0) {
            return;
        }

        $limits = ['normal' => 270, 'night' => 480, 'rest_day' => 840, 'holiday' => 840, 'holiday_night' => 480];
        foreach ($minutes as $kind => $n) {
            if ($n > $limits[$kind]) {
                throw FixtureError::notCoverable("employee {$employee->employee_code}: {$n} minutes of {$kind} overtime do not fit one record of the shift");
            }
        }

        $weekdays = [];
        $saturdays = [];
        $sundays = [];
        for ($day = $period['start']; strcmp($day, $period['end']) <= 0; $day = self::addDays($day, 1)) {
            $iso = (int) Carbon::parse($day)->dayOfWeekIso;
            if ($iso <= 5) {
                $weekdays[] = $day;
            } elseif ($iso === 6) {
                $saturdays[] = $day;
            } else {
                $sundays[] = $day;
            }
        }

        // Each kind has a date of its own, so no two records overlap or share a holiday.
        $dates = [];
        $next = 0;
        foreach (['normal', 'night', 'holiday', 'holiday_night'] as $kind) {
            if (isset($minutes[$kind])) {
                $dates[$kind] = $weekdays[$next++] ?? throw FixtureError::notCoverable("no weekday left in the period for {$kind} overtime");
            }
        }
        if (isset($minutes['rest_day'])) {
            $dates['rest_day'] = ($saturdays[0] ?? $sundays[0] ?? null) ?? throw FixtureError::notCoverable('no weekly rest day in the period for rest_day overtime');
        }
        $lateDate = null;
        if ($lateness > 0) {
            $lateDate = $weekdays[$next++] ?? throw FixtureError::notCoverable('no weekday left in the period for the decided lateness');
        }

        $shift = $state['shift'] ??= Shift::factory()->create([
            'tenant_id' => $tenant->id,
            ...(require __DIR__.'/../fixtures/shifts.php')['S1'],
        ]);
        ShiftAssignment::factory()->create([
            'tenant_id' => $tenant->id,
            'shift_id' => $shift->id,
            'assignable_type' => Employee::class,
            'assignable_id' => $employee->id,
            'effective_from' => '2020-01-01',
        ]);

        foreach (['holiday', 'holiday_night'] as $kind) {
            if (isset($dates[$kind]) && ! isset($state['holidays'][$dates[$kind]])) {
                Holiday::factory()->create([
                    'tenant_id' => $tenant->id, 'branch_id' => null, 'date' => $dates[$kind], 'recurring' => false, 'is_active' => true,
                ]);
                $state['holidays'][$dates[$kind]] = true;
            }
        }

        foreach ($minutes as $kind => $n) {
            $in = match ($kind) {
                'normal' => '08:30',
                'night', 'holiday_night' => '22:00',
                default => '08:00',
            };
            // A normal day's overtime is the time past the shift's 17:30 end.
            $from = Carbon::parse($dates[$kind].' '.($kind === 'normal' ? '17:30' : $in), self::ZONE);
            AttendanceRecord::factory()->create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'date' => $dates[$kind],
                'check_in' => Carbon::parse($dates[$kind].' '.$in, self::ZONE)->utc(),
                'check_out' => $from->copy()->addMinutes($n)->utc(),
            ]);
        }

        if ($lateness > 0) {
            AttendanceRecord::factory()->checkInOnly()->create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'date' => $lateDate,
                'check_in' => Carbon::parse($lateDate.' 09:30', self::ZONE)->utc(),
                'metadata' => ['pay_deduct_minutes' => $lateness],
            ]);
        }
    }

    /** 1600 is "16.00": a hundredths figure spelt as a decimal string, which the engine parses exactly. */
    private static function twoDecimals(int $x100): string
    {
        return sprintf('%d.%02d', intdiv($x100, 100), $x100 % 100);
    }

    private static function addDays(string $ymd, int $days): string
    {
        return Carbon::parse($ymd)->addDays($days)->format('Y-m-d');
    }

    private static function daysBetween(string $start, string $end): int
    {
        return (int) Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;
    }
}
