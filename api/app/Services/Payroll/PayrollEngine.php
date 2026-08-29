<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\EmployeeStatus;
use App\Enums\LeaveStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Services\Calendar\EthiopianCalendar;
use App\Services\Holiday\HolidayService;
use App\Services\Leave\LeaveDayCalculator;
use Carbon\Carbon;

final class PayrollEngine
{
    /** Standard Ethiopian working days per month, used to derive the OT hourly rate. */
    private const WORKING_DAYS_PER_MONTH = 22;

    private const HOURS_PER_DAY = 8;

    public function __construct(
        private readonly TaxCalculator $taxCalculator,
        private readonly PensionCalculator $pensionCalculator,
        private readonly OvertimeCalculator $overtimeCalculator,
        private readonly LoanService $loanService,
        private readonly CostSharingService $costSharingService,
        private readonly AllowanceService $allowanceService,
        private readonly OvertimeClassifier $overtimeClassifier,
        private readonly HolidayService $holidayService,
        private readonly LeaveDayCalculator $leaveDayCalculator,
        private readonly EthiopianCalendar $calendar,
    ) {}

    public function process(
        int $tenantId,
        Carbon $periodStart,
        Carbon $periodEnd,
        int $processedBy,
        ?string $idempotencyKey = null,
    ): PayrollProcessResult {
        if ($idempotencyKey) {
            $existing = PayrollRun::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return new PayrollProcessResult($existing, wasDuplicate: true);
            }
        }

        $run = PayrollRun::create([
            'tenant_id' => $tenantId,
            'period_label' => $periodStart->format('F Y'),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'idempotency_key' => $idempotencyKey,
            'status' => 'processing',
            'processed_by' => $processedBy,
            'processed_at' => now(),
        ]);

        $settings = Tenant::findOrFail($tenantId)->settings ?? [];
        $pagumenStrategy = $settings['pagumen_proration_strategy'] ?? 'full_month';
        $fiscalYearStartMonth = (int) ($settings['fiscal_year_start_month'] ?? 1);

        $pagume = $this->calendar->pagumeDaysInRange($periodStart, $periodEnd);

        $totalGross = 0;
        $totalNet = 0;
        $totalTax = 0;
        $count = 0;

        Employee::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [
                EmployeeStatus::HIRED,
                EmployeeStatus::PROBATION,
                EmployeeStatus::CONFIRMED,
            ])
            ->chunkById(100, function ($employees) use (
                $run, $periodStart, $periodEnd,
                $pagumenStrategy, $pagume, $fiscalYearStartMonth,
                &$totalGross, &$totalNet, &$totalTax, &$count,
            ) {
                foreach ($employees as $employee) {
                    $entry = $this->processEmployee(
                        $employee,
                        $run,
                        $periodStart,
                        $periodEnd,
                        $pagumenStrategy,
                        $pagume,
                        $fiscalYearStartMonth,
                    );
                    if ($entry) {
                        $totalGross += $entry->gross_cents;
                        $totalNet += $entry->net_cents;
                        $totalTax += $entry->income_tax_cents;
                        $count++;
                    }
                }
            });

        $run->update([
            'status' => 'completed',
            'employee_count' => $count,
            'gross_total_cents' => $totalGross,
            'net_total_cents' => $totalNet,
            'tax_total_cents' => $totalTax,
        ]);

        return new PayrollProcessResult($run);
    }

    public function void(PayrollRun $run, int $voidedBy, string $reason): PayrollRun
    {
        $run->update([
            'status' => 'voided',
            'voided_at' => now(),
            'voided_by' => $voidedBy,
            'void_reason' => $reason,
        ]);

        return $run->fresh();
    }

    public function reprocess(PayrollRun $voidedRun, int $processedBy, ?string $idempotencyKey = null): PayrollProcessResult
    {
        $result = $this->process(
            $voidedRun->tenant_id,
            Carbon::parse($voidedRun->period_start),
            Carbon::parse($voidedRun->period_end),
            $processedBy,
            $idempotencyKey,
        );

        if (! $result->wasDuplicate) {
            $result->run->update(['reprocessed_from_id' => $voidedRun->id]);
        }

        return $result;
    }

    /**
     * @param  array{days: int, ethiopian_year: int|null, pagume_length: int|null}  $pagume
     */
    private function processEmployee(
        Employee $employee,
        PayrollRun $run,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $pagumenStrategy,
        array $pagume,
        int $fiscalYearStartMonth,
    ): ?PayrollEntry {
        $basicSalary = $employee->salary_cents;

        if ($basicSalary <= 0) {
            return null;
        }

        $log = [
            'version' => '1.1',
            'calculated_at' => now()->toIso8601String(),
            'inputs' => [
                'employee_id' => $employee->public_id,
                'basic_salary_cents' => $basicSalary,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'hire_date' => Carbon::parse($employee->hire_date)->toDateString(),
                'status' => $employee->status,
                'pagumen_proration_strategy' => $pagumenStrategy,
                'fiscal_year_start_month' => $fiscalYearStartMonth,
            ],
            'steps' => [],
        ];

        // Mid-period-hire proration: an employee who joined after the period
        // began is paid only for the fraction of the period they worked.
        $totalPeriodDays = $periodStart->diffInDays($periodEnd) + 1;
        $hireDate = $employee->hire_date;
        $hireProrationFactor = 1.0;
        if ($hireDate && $hireDate->gt($periodStart)) {
            $workedDays = $hireDate->diffInDays($periodEnd) + 1;
            $hireProrationFactor = $workedDays / $totalPeriodDays;
        }

        // Pagumen (13th month) proration. Under `daily_rate` the days of the
        // period that fall in Pagume are paid at annual/365 per day instead of
        // the full monthly salary; under `full_month` (or when the period does
        // not touch Pagume) the monthly salary applies unchanged. The hire
        // factor composes multiplicatively on top of whichever base applies,
        // so an employee hired mid-Pagume is prorated on both axes.
        $pagumeDaysInPeriod = $pagume['days'];
        $pagumenApplied = $pagumenStrategy === 'daily_rate' && $pagumeDaysInPeriod > 0;

        if ($pagumenApplied) {
            $nonPagumeDays = $totalPeriodDays - $pagumeDaysInPeriod;
            // Pagume days: annual (12 x monthly) / 365, per actual Pagume day.
            $pagumeBasic = (int) round($basicSalary * 12 * $pagumeDaysInPeriod / 365);
            // Any non-Pagume days in the same run (uncommon — Pagume normally
            // has its own boundaried run) keep the monthly basis, scaled to
            // their share of the period.
            $nonPagumeBasic = $nonPagumeDays > 0
                ? (int) round($basicSalary * $nonPagumeDays / $totalPeriodDays)
                : 0;
            $periodBasic = $pagumeBasic + $nonPagumeBasic;
        } else {
            $periodBasic = $basicSalary;
        }

        $proratedBasic = (int) round($periodBasic * $hireProrationFactor);

        $log['steps'][] = [
            'step' => 'proration',
            'proration_factor' => $hireProrationFactor,
            'original_basic_cents' => $basicSalary,
            'period_basic_cents' => $periodBasic,
            'prorated_basic_cents' => $proratedBasic,
        ];

        $log['steps'][] = [
            'step' => 'pagumen_proration',
            'strategy' => $pagumenStrategy,
            'applied' => $pagumenApplied,
            'pagume_days_in_period' => $pagumeDaysInPeriod,
            'pagume_total_days_in_year' => $pagume['pagume_length'],
            'ethiopian_year' => $pagume['ethiopian_year'],
            'period_basic_cents' => $periodBasic,
        ];

        // Unpaid leave: approved leave of non-paid types reduces earned basic by
        // the contractual daily rate (full monthly basic / working days) for each
        // unpaid working day in the period. Paid leave has no effect on pay.
        $unpaidLeaveDays = $this->gatherUnpaidLeaveDays($employee, $periodStart, $periodEnd);
        $dailyRate = (int) round($basicSalary / self::WORKING_DAYS_PER_MONTH);
        $unpaidLeaveDeduction = $unpaidLeaveDays > 0
            ? min((int) round($dailyRate * $unpaidLeaveDays), $proratedBasic)
            : 0;
        $proratedBasic -= $unpaidLeaveDeduction;

        $log['steps'][] = [
            'step' => 'unpaid_leave',
            'unpaid_leave_days' => $unpaidLeaveDays,
            'daily_rate_cents' => $dailyRate,
            'deduction_cents' => $unpaidLeaveDeduction,
            'basic_after_unpaid_leave_cents' => $proratedBasic,
        ];

        $overtimeByType = $this->gatherOvertimeByType($employee, $periodStart, $periodEnd);
        $overtimeRates = $this->overtimeCalculator->ratesFor($employee->tenant_id);

        $overtimeAmount = 0;
        $overtimeMinutes = 0;
        $overtimeBreakdown = [];
        foreach ($overtimeByType as $type => $minutes) {
            $amount = $this->overtimeCalculator->calculate(
                $proratedBasic,
                self::WORKING_DAYS_PER_MONTH,
                self::HOURS_PER_DAY,
                $minutes,
                $type,
                $employee->tenant_id,
            );
            $overtimeAmount += $amount;
            $overtimeMinutes += $minutes;
            $overtimeBreakdown[$type] = [
                'minutes' => $minutes,
                'amount_cents' => $amount,
                'rate' => $overtimeRates[$type] ?? null,
            ];
        }

        $log['steps'][] = [
            'step' => 'overtime',
            'overtime_minutes' => $overtimeMinutes,
            'overtime_amount_cents' => $overtimeAmount,
            'by_type' => $overtimeBreakdown,
        ];

        $allowanceResult = $this->allowanceService->resolve($employee, $proratedBasic);
        $allowances = $allowanceResult['items'];
        $totalAllowances = $allowanceResult['total_cents'];
        $nonTaxableAllowances = $allowanceResult['non_taxable_cents'];

        $log['steps'][] = [
            'step' => 'allowances',
            'items' => $allowances,
            'total_allowances_cents' => $totalAllowances,
            'taxable_allowances_cents' => $allowanceResult['taxable_cents'],
            'non_taxable_allowances_cents' => $nonTaxableAllowances,
        ];

        $grossSalary = $proratedBasic + $overtimeAmount + $totalAllowances;

        $log['steps'][] = [
            'step' => 'gross',
            'prorated_basic_cents' => $proratedBasic,
            'overtime_cents' => $overtimeAmount,
            'allowances_cents' => $totalAllowances,
            'gross_cents' => $grossSalary,
        ];

        // Taxable income excludes non-taxable allowances (spec S23 step 8).
        $taxableAmount = $grossSalary - $nonTaxableAllowances;
        // The period start, not the run date. Payroll is re-runnable — `void()`
        // reprocesses a past period — and since Proclamation 1395/2025 there are
        // two tax ladders in force at different times. Resolving against "now"
        // would retax a June-2025 period at July-2025 rates and silently produce
        // a different payslip from the one the employee was actually paid on.
        $incomeTax = $this->taxCalculator->calculate($taxableAmount, $employee->tenant_id, $periodStart);

        $log['steps'][] = [
            'step' => 'income_tax',
            'gross_cents' => $grossSalary,
            'non_taxable_allowances_cents' => $nonTaxableAllowances,
            'taxable_amount_cents' => $taxableAmount,
            'income_tax_cents' => $incomeTax,
        ];

        $pension = $this->pensionCalculator->calculate($proratedBasic);

        $log['steps'][] = [
            'step' => 'pension',
            'pensionable_basic_cents' => $proratedBasic,
            'employee_pension_cents' => $pension['employee_cents'],
            'employer_pension_cents' => $pension['employer_cents'],
        ];

        $loanDeduction = $this->loanService->calculateMonthlyDeduction($employee);

        $activeLoans = $this->loanService->getActiveLoans($employee);
        foreach ($activeLoans as $loan) {
            $this->loanService->applyDeduction($loan, $loan->monthly_deduction_cents);
        }

        $log['steps'][] = [
            'step' => 'loan_deductions',
            'loan_deduction_cents' => $loanDeduction,
        ];

        // Cost sharing is a percentage of prorated gross, so it is computed after
        // gross is final and recorded before the net roll-up. The log carries the
        // rate and both balances, not just the amount: convention #11 requires the
        // trace to explain *why* a figure is what it is, and a bare deduction
        // cannot be re-derived once the row's balance has moved on.
        $costSharing = $this->costSharingService->getActiveObligation($employee);
        $costSharingDeduction = $this->costSharingService->calculateDeduction($costSharing, $grossSalary);
        $costSharingOutstandingBefore = $costSharing?->outstanding_cents;

        if ($costSharing !== null && $costSharingDeduction > 0) {
            $this->costSharingService->applyDeduction($costSharing, $costSharingDeduction);
        }

        $log['steps'][] = [
            'step' => 'cost_sharing',
            'gross_cents' => $grossSalary,
            'rate_percent' => $costSharing?->deduction_rate_percent,
            'cost_sharing_cents' => $costSharingDeduction,
            'outstanding_before_cents' => $costSharingOutstandingBefore,
            'outstanding_after_cents' => $costSharing?->outstanding_cents,
        ];

        $totalDeductions = $incomeTax + $pension['employee_cents'] + $loanDeduction + $costSharingDeduction;
        $netSalary = $grossSalary - $totalDeductions;

        $log['steps'][] = [
            'step' => 'net_calculation',
            'gross_cents' => $grossSalary,
            'total_deductions_cents' => $totalDeductions,
            'net_cents' => $netSalary,
        ];

        $log['outputs'] = [
            'gross_cents' => $grossSalary,
            'income_tax_cents' => $incomeTax,
            'employee_pension_cents' => $pension['employee_cents'],
            'employer_pension_cents' => $pension['employer_cents'],
            'loan_deduction_cents' => $loanDeduction,
            'cost_sharing_cents' => $costSharingDeduction,
            'net_cents' => $netSalary,
        ];

        $deductions = [];
        if ($loanDeduction > 0) {
            $deductions[] = ['type' => 'loan', 'amount_cents' => $loanDeduction];
        }
        if ($costSharingDeduction > 0) {
            $deductions[] = ['type' => 'cost_sharing', 'amount_cents' => $costSharingDeduction];
        }

        return PayrollEntry::create([
            'tenant_id' => $employee->tenant_id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'basic_salary_cents' => $proratedBasic,
            'allowances' => $allowances,
            'deductions' => $deductions,
            'gross_cents' => $grossSalary,
            'income_tax_cents' => $incomeTax,
            'employee_pension_cents' => $pension['employee_cents'],
            'employer_pension_cents' => $pension['employer_cents'],
            // The generic "everything that isn't tax or pension" bucket: the
            // accounting export sums it into a single journal line and the
            // payslip prints it, so cost sharing has to land here or net stops
            // reconciling against gross − tax − pension − other.
            'other_deductions_cents' => $loanDeduction + $costSharingDeduction,
            'net_cents' => $netSalary,
            'calculation_log' => $log,
        ]);
    }

    /**
     * Working days of approved, unpaid-type leave that fall within the pay
     * period. When a request lies entirely inside the period its stored `days`
     * (half-day aware) is used; a request spanning the period boundary is
     * clipped and recounted against holidays/weekends.
     */
    private function gatherUnpaidLeaveDays(Employee $employee, Carbon $periodStart, Carbon $periodEnd): float
    {
        $requests = LeaveRequest::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('status', LeaveStatus::APPROVED)
            ->whereDate('start_date', '<=', $periodEnd->format('Y-m-d'))
            ->whereDate('end_date', '>=', $periodStart->format('Y-m-d'))
            ->with(['leaveType' => fn ($q) => $q->withoutGlobalScope('tenant')])
            ->get();

        $days = 0.0;

        foreach ($requests as $request) {
            // Only unpaid leave reduces pay; skip paid leave and orphaned rows.
            $leaveType = $request->leaveType;
            if (! $leaveType instanceof LeaveType || $leaveType->is_paid) {
                continue;
            }

            $start = Carbon::parse($request->start_date);
            $end = Carbon::parse($request->end_date);
            $clipStart = $start->lt($periodStart) ? $periodStart->copy() : $start;
            $clipEnd = $end->gt($periodEnd) ? $periodEnd->copy() : $end;

            if ($clipStart->equalTo($start) && $clipEnd->equalTo($end)) {
                $days += (float) $request->days;
            } else {
                $days += $this->leaveDayCalculator->calculateDays(
                    $clipStart, $clipEnd, $employee->tenant_id, $employee->branch_id,
                );
            }
        }

        return $days;
    }

    /**
     * Sum the period's overtime minutes for an employee, split by rate type
     * (normal / night / holiday / holiday_night). Holidays are pre-fetched once
     * for the period so classification is a memory lookup per record.
     *
     * @return array{normal: int, night: int, holiday: int, holiday_night: int}
     */
    private function gatherOvertimeByType(Employee $employee, Carbon $periodStart, Carbon $periodEnd): array
    {
        $buckets = ['normal' => 0, 'night' => 0, 'holiday' => 0, 'holiday_night' => 0];

        $holidayDates = $this->holidayService->getHolidayDates(
            $employee->tenant_id,
            $periodStart,
            $periodEnd,
            $employee->branch_id,
        );

        $records = AttendanceRecord::query()
            ->withoutGlobalScope('tenant')
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $periodStart->format('Y-m-d'))
            ->whereDate('date', '<=', $periodEnd->format('Y-m-d'))
            ->whereNotNull('check_in')
            ->whereNotNull('check_out')
            ->with('shift')
            ->get();

        foreach ($records as $record) {
            $isHoliday = isset($holidayDates[Carbon::parse($record->date)->format('Y-m-d')]);

            foreach ($this->overtimeClassifier->classify($record, $isHoliday) as $type => $minutes) {
                $buckets[$type] += $minutes;
            }
        }

        return $buckets;
    }
}
