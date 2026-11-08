<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Payroll\StatutoryRules;
use App\Support\TenantTime;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Sick leave as Labour Proclamation 1156/2019 Art. 85 grants it (A-Z review
 * E-08): up to six months within twelve, counted from the first day of
 * sickness; the first month at full pay, the next two at half pay, the last
 * three without pay.
 *
 * Days are the ones the leave charges (the person's working days, or every
 * day for a type counted in calendar days), and a month is the payroll rule's
 * working days per month, so a month of sick leave costs exactly a month's
 * basic. A twelve-month period starts on the first sick day not already in
 * one, and every day in it takes its tier from its place in the period. Only
 * types marked `follows_art85` count; any other paid type stays a bucket.
 *
 * Payroll and the request check both ask here, so a payslip and the refusal
 * of a seventh month cannot disagree. Neither runs with a tenant guaranteed
 * to be set, so each query states `tenant_id` itself.
 */
final class SickLeaveTiers
{
    public const FULL = 'full';

    public const HALF = 'half';

    public const UNPAID = 'unpaid';

    /** Past six months in the period: refused when asked for, unpaid if approved anyway. */
    public const BEYOND = 'beyond';

    public function __construct(
        private readonly LeaveDayCalculator $calculator,
        private readonly StatutoryRules $statutoryRules,
    ) {}

    /**
     * The tier of every day of this person's Art. 85 sick leave, oldest first.
     * Approved leave always counts; pending leave too when $withPending, as
     * a request being filed must fit beside the ones already waiting.
     *
     * @param  list<string>  $extraDates  days of a request not yet saved (Y-m-d)
     * @return array<string, string> Y-m-d => one of the tier constants
     */
    public function tiersOf(Employee $employee, int $monthDays, array $extraDates = [], bool $withPending = false): array
    {
        $dates = $extraDates;

        foreach ($this->requestsOf($employee, $withPending) as $request) {
            $dates = [...$dates, ...$this->datesOf($request, $employee)];
        }

        $dates = array_values(array_unique($dates));
        sort($dates);

        $tiers = [];
        $periodEnd = null;
        $n = 0;

        foreach ($dates as $date) {
            if ($periodEnd === null || $date > $periodEnd) {
                $periodEnd = Carbon::parse($date)->addYear()->subDay()->format('Y-m-d');
                $n = 0;
            }
            $n++;

            $tiers[$date] = match (true) {
                $n <= $monthDays => self::FULL,
                $n <= 3 * $monthDays => self::HALF,
                $n <= 6 * $monthDays => self::UNPAID,
                default => self::BEYOND,
            };
        }

        return $tiers;
    }

    /**
     * Where this person stands in the period that has not ended by $today
     * (the one running, or one an approved future leave opens): its approved
     * days, the six months it allows, and the tier the next sick day in it
     * would take. With no such period, nothing is taken and the next day is
     * at full pay. The balance shows this for an Art. 85 type in place of
     * the annual remainder, which a long illness drove below zero (K-50).
     *
     * @return array{days_taken: int, days_allowed: int, period_start: string|null, period_end: string|null, next_tier: string}
     */
    public function standing(Employee $employee, int $monthDays, CarbonInterface $today): array
    {
        $day = $today->format('Y-m-d');
        $start = null;
        $end = null;
        $taken = 0;

        foreach (array_keys($this->tiersOf($employee, $monthDays)) as $date) {
            if ($end !== null && $date <= $end) {
                $taken++;

                continue;
            }
            if ($end !== null && $end >= $day) {
                break;
            }
            $start = $date;
            $end = Carbon::parse($date)->addYear()->subDay()->format('Y-m-d');
            $taken = 1;
        }

        if ($end === null || $end < $day) {
            [$start, $end, $taken] = [null, null, 0];
        }

        return [
            'days_taken' => $taken,
            'days_allowed' => 6 * $monthDays,
            'period_start' => $start,
            'period_end' => $end,
            'next_tier' => match (true) {
                $taken < $monthDays => self::FULL,
                $taken < 3 * $monthDays => self::HALF,
                $taken < 6 * $monthDays => self::UNPAID,
                default => self::BEYOND,
            },
        ];
    }

    /**
     * standing() as of the organisation's today, with its month of working
     * days, for a balance row.
     *
     * @return array{days_taken: int, days_allowed: int, period_start: string|null, period_end: string|null, next_tier: string}
     */
    public function standingToday(Employee $employee): array
    {
        $tenant = app(CurrentTenant::class)->tenantFor($employee->tenant_id) ?? Tenant::query()->findOrFail($employee->tenant_id);
        $today = TenantTime::today($tenant);
        $monthDays = $this->statutoryRules->for($tenant, $today)->int('payroll.working_days_per_month');

        return $this->standing($employee, $monthDays, $today);
    }

    /**
     * Half-pay and unpaid days of approved sick leave inside a pay period, for
     * the payroll step. A day past six months that was approved anyway is
     * unpaid.
     *
     * @return array{half: float, unpaid: float}
     */
    public function inPeriod(Employee $employee, CarbonInterface $from, CarbonInterface $to, int $monthDays): array
    {
        $first = $from->format('Y-m-d');
        $last = $to->format('Y-m-d');
        $half = 0.0;
        $unpaid = 0.0;

        foreach ($this->tiersOf($employee, $monthDays) as $date => $tier) {
            if ($date < $first || $date > $last) {
                continue;
            }
            if ($tier === self::HALF) {
                $half++;
            } elseif ($tier === self::UNPAID || $tier === self::BEYOND) {
                $unpaid++;
            }
        }

        return ['half' => $half, 'unpaid' => $unpaid];
    }

    /** @return list<string> */
    public function datesOf(LeaveRequest $request, Employee $employee): array
    {
        return $this->datesBetween($employee, $request->leaveType, Carbon::parse($request->start_date), Carbon::parse($request->end_date));
    }

    /**
     * The days a leave of this type from $start to $end charges.
     *
     * @return list<string>
     */
    public function datesBetween(Employee $employee, ?LeaveType $type, CarbonInterface $from, CarbonInterface $to): array
    {
        $start = Carbon::parse($from->format('Y-m-d'));
        $end = Carbon::parse($to->format('Y-m-d'));

        if ($type?->counts_calendar_days) {
            $dates = [];
            for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                $dates[] = $day->format('Y-m-d');
            }

            return $dates;
        }

        return $this->calculator->chargedDates($start, $end, $employee->tenant_id, $employee->branch_id, $employee);
    }

    /** @return iterable<LeaveRequest> */
    private function requestsOf(Employee $employee, bool $withPending): iterable
    {
        $types = LeaveType::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $employee->tenant_id)
            ->where('follows_art85', true)
            ->get()
            ->keyBy('id');

        if ($types->isEmpty()) {
            return [];
        }

        $requests = LeaveRequest::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereIn('leave_type_id', $types->keys()->all())
            ->whereIn('status', $withPending ? [LeaveStatus::APPROVED, LeaveStatus::PENDING] : [LeaveStatus::APPROVED])
            ->orderBy('start_date')
            ->get();

        foreach ($requests as $request) {
            $request->setRelation('leaveType', $types->get($request->leave_type_id));
        }

        return $requests;
    }
}
