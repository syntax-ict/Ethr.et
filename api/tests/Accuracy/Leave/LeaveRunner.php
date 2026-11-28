<?php

declare(strict_types=1);

namespace Tests\Accuracy\Leave;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveDayCalculator;
use App\Services\Leave\LeaveYear;
use App\Services\Leave\SickLeaveTiers;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Asks the engine what a case asked and returns every disagreement with the
 * hand value, one line each, instead of stopping at the first. It reads the
 * engine only through its own entry points and decides nothing.
 */
final class LeaveRunner
{
    /**
     * @param  array<string, mixed>  $case  merged over the defaults
     * @param  array<string, mixed>  $expect  the hand values (after any ruling)
     * @return list<string>
     */
    public static function run(array $case, array $expect, TestCase $t): array
    {
        $family = LeaveCases::family($case['id']);
        $miss = [];
        $say = self::reporter($case, $miss);

        if ($family === 'YEAR') {
            self::year($case, $expect, $say);

            return $miss;
        }

        $ctx = LeaveFixture::build($case, $t);

        match ($family) {
            'ENT' => self::entitlement($ctx, $case, $expect, $say),
            'DAYS' => self::days($ctx, $case, $expect, $say),
            'PAYDAYS' => self::payDays($ctx, $case, $expect, $say),
            'SICK' => self::sick($ctx, $case, $expect, $say),
            default => self::acts($ctx, $case, $expect, $say),
        };

        return $miss;
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  list<string>  $miss
     */
    private static function reporter(array $case, array &$miss): \Closure
    {
        $rules = implode(', ', $case['rule']);
        $fmt = fn (mixed $v): string => is_array($v) ? (string) json_encode($v, JSON_UNESCAPED_SLASHES) : var_export($v, true);

        return function (string $field, mixed $want, mixed $got) use (&$miss, $case, $rules, $fmt): void {
            $miss[] = "[{$case['id']}] rule {$rules}: {$field} expected {$fmt($want)}, engine said {$fmt($got)}";
        };
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $expect
     */
    private static function entitlement(array $ctx, array $case, array $expect, \Closure $say): void
    {
        $got = (int) round(app(LeaveBalanceService::class)->yearlyEntitlement($ctx['employee'], $ctx['type'], Carbon::parse($case['as_of'])) * 10);
        if ($got !== $expect['entitlement_tenths']) {
            $say("entitlement on {$case['as_of']} (tenths)", $expect['entitlement_tenths'], $got);
        }
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $expect
     */
    private static function days(array $ctx, array $case, array $expect, \Closure $say): void
    {
        [$from, $to] = $case['span'];
        $employee = $ctx['employee'];

        if ($case['type']['counts_calendar_days']) {
            // A calendar-days type is counted where a request is filed, not by the day calculator.
            LeaveBalance::query()->create([
                'tenant_id' => $ctx['tenant']->id, 'employee_id' => $employee->id, 'leave_type_id' => $ctx['type']->id,
                'year' => LeaveFixture::leaveYear($ctx)->yearOf(Carbon::parse($from)),
                'entitled_days' => 1000, 'used_days' => 0, 'carried_days' => 0, 'pending_days' => 0,
            ]);
            $result = LeaveFixture::act($ctx, ['do' => 'file', 'key' => 'R1', 'from' => $from, 'to' => $to, 'by' => 'hr']);
            if ($result['refused']) {
                $say('the request was accepted', true, false);

                return;
            }
            $days = (int) round((float) $ctx['requests']['R1']->days);
            $dates = app(SickLeaveTiers::class)->datesBetween($employee, $ctx['type'], Carbon::parse($from), Carbon::parse($to));
        } else {
            $calculator = app(LeaveDayCalculator::class);
            $days = (int) round($calculator->calculateDays(Carbon::parse($from), Carbon::parse($to), $ctx['tenant']->id, $employee->branch_id, $employee));
            $dates = $calculator->chargedDates(Carbon::parse($from), Carbon::parse($to), $ctx['tenant']->id, $employee->branch_id, $employee);
        }

        if ($days !== $expect['days']) {
            $say("days charged {$from} to {$to}", $expect['days'], $days);
        }
        if ($dates !== $expect['dates']) {
            $say("dates charged {$from} to {$to}", $expect['dates'], $dates);
        }
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $expect
     */
    private static function payDays(array $ctx, array $case, array $expect, \Closure $say): void
    {
        [$from, $to] = $case['pay_period'];
        $got = (int) round(app(PayrollEngine::class)->gatherUnpaidLeaveDays($ctx['employee'], Carbon::parse($from), Carbon::parse($to)) * 1000);
        if ($got !== $expect['unpaid_thousandths']) {
            $say("unpaid days in {$from} to {$to} (thousandths)", $expect['unpaid_thousandths'], $got);
        }
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $expect
     */
    private static function year(array $case, array $expect, \Closure $say): void
    {
        $leaveYear = LeaveYear::of($case['org']['leave_year']);
        $date = Carbon::parse($case['date']);
        $year = $leaveYear->yearOf($date);
        $month = $leaveYear->monthOf($date);

        $got = [
            'year' => $year,
            'month' => $month,
            'starts' => $leaveYear->starts($year)->format('Y-m-d'),
            'month_end' => $leaveYear->monthEnds($year, $month)->format('Y-m-d'),
        ];
        foreach ($expect as $field => $want) {
            if ($want !== $got[$field]) {
                $say("{$case['date']} {$field}", $want, $got[$field]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $expect
     */
    private static function sick(array $ctx, array $case, array $expect, \Closure $say): void
    {
        if ($case['act'] !== []) {
            self::acts($ctx, $case, $expect, $say);
        }

        foreach ($expect['tiers'] ?? [] as $set) {
            $tiers = app(SickLeaveTiers::class)->tiersOf($ctx['employee'], $set['m']);
            foreach ($set['dates'] as $date => $want) {
                if (($tiers[$date] ?? null) !== $want) {
                    $say("tier of {$date} with a month of {$set['m']} days", $want, $tiers[$date] ?? null);
                }
            }
        }
    }

    /**
     * Carries out the case's acts in order and checks what each expects.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $expect
     */
    private static function acts(array $ctx, array $case, array $expect, \Closure $say): void
    {
        if (isset($expect['initial'])) {
            self::check($ctx, $expect['initial'], ['refused' => false, 'kept_days' => null], 'before any act', $say);
        }

        foreach ($case['act'] as $i => $act) {
            $result = LeaveFixture::act($ctx, $act);
            $want = $expect['after'][$i] ?? null;
            if ($want !== null) {
                $at = $act['at'] ?? $case['now'];
                self::check($ctx, $want, $result, 'after act '.($i + 1)." ({$act['do']} {$at})", $say);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $want
     * @param  array<string, mixed>  $result
     */
    private static function check(array $ctx, array $want, array $result, string $label, \Closure $say): void
    {
        if (array_key_exists('refused', $want) && $want['refused'] !== $result['refused']) {
            $say("{$label}: refused", $want['refused'], $result['refused']);
        }
        if (array_key_exists('kept_days', $want) && (float) $want['kept_days'] !== (float) $result['kept_days']) {
            $say("{$label}: kept days", $want['kept_days'], $result['kept_days']);
        }

        foreach ($want['requests'] ?? [] as $key => $fields) {
            $request = LeaveRequest::query()->withoutGlobalScope('tenant')->find($ctx['requests'][$key]->id);
            foreach ($fields as $field => $value) {
                $got = $field === 'status' ? $request?->status->value : (float) $request?->days;
                if ($field === 'days' ? (float) $value !== $got : $value !== $got) {
                    $say("{$label}: request {$key} {$field}", $value, $got);
                }
            }
        }

        foreach ($want['balance'] ?? [] as $year => $fields) {
            $balance = LeaveFixture::balance($ctx, (int) $year);
            foreach ($fields as $field => $value) {
                if ($balance[$field] !== $value) {
                    $say("{$label}: balance {$year} {$field}", $value, $balance[$field]);
                }
            }
        }
    }
}
