<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle\Payroll;

use InvalidArgumentException;
use Tests\Accuracy\Oracle\Calendar as Dates;
use Tests\Accuracy\Oracle\NoOpinion;
use Tests\Accuracy\Oracle\Trace;

/** One person's payslip under one set of readings. Internal to the oracle. */
final class PayslipCalculator
{
    /** State of each rule in the register: D decided, I implemented. */
    private const STATES = [
        'PYR-PER-01' => 'I', 'PYR-PER-03' => 'D', 'PYR-PER-06' => 'I',
        'PYR-SAL-01' => 'I', 'PYR-SAL-02' => 'D', 'PYR-SAL-03' => 'I', 'PYR-SAL-04' => 'D', 'PYR-SAL-05' => 'D',
        'PYR-SAL-06' => 'I', 'PYR-SAL-07' => 'I',
        'PYR-PRO-01' => 'D', 'PYR-PRO-02' => 'D', 'PYR-PRO-03' => 'I', 'PYR-PRO-04' => 'I',
        'PYR-PAG-01' => 'I', 'PYR-PAG-02' => 'I', 'PYR-PAG-03' => 'I', 'PYR-PAG-04' => 'I',
        'PYR-RATE-01' => 'I', 'PYR-LEAVE-01' => 'D', 'PYR-LEAVE-02' => 'I', 'PYR-LEAVE-03' => 'I',
        'PYR-LEAVE-05' => 'I', 'PYR-LEAVE-06' => 'I',
        'PYR-SICK-01' => 'I', 'PYR-SICK-02' => 'I', 'PYR-SICK-03' => 'D',
        'PYR-LATE-01' => 'D', 'PYR-LATE-02' => 'I', 'PYR-LATE-03' => 'I', 'PYR-LATE-04' => 'D', 'PYR-LATE-05' => 'D',
        'PYR-OT-01' => 'I', 'PYR-OT-02' => 'D', 'PYR-OT-03' => 'I', 'PYR-OT-04' => 'D',
        'PYR-ALW-01' => 'D', 'PYR-ALW-02' => 'D', 'PYR-ALW-03' => 'D', 'PYR-ALW-04' => 'D', 'PYR-ALW-05' => 'I',
        'PYR-ALW-06' => 'I',
        'PYR-GROSS-01' => 'D', 'PYR-GROSS-02' => 'D',
        'PYR-TAX-01' => 'I', 'PYR-TAX-02' => 'I', 'PYR-TAX-03' => 'D', 'PYR-TAX-04' => 'I', 'PYR-TAX-06' => 'I',
        'PYR-PEN-01' => 'D', 'PYR-PEN-02' => 'D', 'PYR-PEN-03' => 'D', 'PYR-PEN-04' => 'I', 'PYR-PEN-05' => 'D',
        'PYR-PEN-06' => 'I', 'PYR-PEN-07' => 'D', 'PYR-PEN-08' => 'I', 'PYR-PEN-09' => 'I', 'PYR-PEN-10' => 'I',
        'PYR-PEN-12' => 'D', 'PYR-PEN-13' => 'I', 'PYR-PEN-15' => 'I',
        'PYR-LOAN-01' => 'D', 'PYR-LOAN-02' => 'D', 'PYR-LOAN-03' => 'D', 'PYR-LOAN-04' => 'D', 'PYR-LOAN-05' => 'I',
        'PYR-CS-01' => 'D', 'PYR-CS-03' => 'I', 'PYR-CS-04' => 'D', 'PYR-CS-05' => 'I', 'PYR-CS-06' => 'I',
        'PYR-CS-07' => 'I',
        'PYR-NET-01' => 'D', 'PYR-ROUND-01' => 'D', 'PYR-ROUND-02' => 'D', 'PYR-ROUND-03' => 'I',
        'PYR-RST-01' => 'D', 'PYR-RST-02' => 'D', 'PYR-RST-03' => 'D', 'PYR-BANK-02' => 'D', 'PYR-BANK-03' => 'D',
    ];

    private const OT_KINDS = ['normal', 'night', 'rest_day', 'holiday', 'holiday_night'];

    public static function mark(?Trace $t, string ...$ids): void
    {
        if ($t === null) {
            return;
        }
        foreach ($ids as $id) {
            $state = self::STATES[$id] ?? null;
            if ($state === 'D') {
                $t->decided($id);
            } elseif ($state === 'I') {
                $t->implemented($id);
            }
        }
    }

    public static function countDays(string $from, string $to): int
    {
        $n = 0;
        $d = Dates::date($from);
        Dates::date($to);
        while (strcmp($d, $to) <= 0) {
            $n++;
            $d = Dates::addDays($d, 1);
        }

        return $n;
    }

    /**
     * @param  array<string, mixed>  $q
     * @param  array<string, mixed>  $stat
     * @return array<string, mixed>
     */
    public static function context(array $q, array $stat): array
    {
        $period = $q['period'];
        $start = Dates::date($period['start']);
        $end = Dates::date($period['end']);
        if (strcmp($start, $end) >= 0) {
            throw new InvalidArgumentException('period end must be after start');
        }
        $org = $q['org'] ?? [];
        $kind = $org['organization_type'] ?? 'private';
        $scheme = $org['pension_scheme'] ?? null;
        if ($scheme === null) {
            $scheme = $stat['pension_scheme_by_kind'][$kind] ?? $stat['pension_scheme_by_kind']['*'];
        }
        $mult = [];
        $own = $org['overtime_rates_x100'] ?? [];
        foreach ($stat['overtime_minimum_rates_x100'] as $k => $floor) {
            $mult[$k] = max($floor, $own[$k] ?? 0);
        }

        return [
            'start' => $start,
            'end' => $end,
            'days' => self::countDays($start, $end),
            'pagume_days' => (int) ($period['pagume_days'] ?? 0),
            'wd' => (int) ($org['working_days_per_month'] ?? $stat['working_days_per_month']),
            'hours' => (int) $stat['hours_per_day'],
            'pagume' => $org['pagume'] ?? $stat['pagume'],
            'late_on' => (bool) ($org['lateness_deductions'] ?? false),
            'mult' => $mult,
            'allowances' => $org['allowances'] ?? [],
            'kind' => $kind,
            'scheme' => $scheme,
            'approve' => ($q['run'] ?? 'preview') === 'approve',
            'stat' => $stat,
        ];
    }

    /**
     * @param  array<string, mixed>  $e
     * @param  array<string, mixed>  $ctx
     */
    public static function payable(array $e, array $ctx): bool
    {
        $status = $e['status'];
        if (in_array($status, ['hired', 'probation', 'confirmed'], true)) {
            return true;
        }
        if (in_array($status, ['resigned', 'terminated', 'retired'], true)) {
            $term = $e['termination_date'] ?? null;

            return $term !== null && strcmp($term, $ctx['start']) >= 0;
        }

        return false;
    }

    /**
     * PYR-SAL-01.
     *
     * @param  array<string, mixed>  $e
     * @param  array<string, mixed>  $ctx
     */
    public static function salary(array $e, array $ctx): int
    {
        $hist = $e['salary_history'] ?? [];
        if (count($hist) > 0) {
            $best = null;
            foreach ($hist as $i => $row) {
                if (strcmp($row['effective'], $ctx['end']) <= 0
                    && ($best === null || strcmp($row['effective'], $hist[$best]['effective']) >= 0)) {
                    $best = $i;
                }
            }
            if ($best === null) {
                foreach ($hist as $i => $row) {
                    if ($best === null || strcmp($row['effective'], $hist[$best]['effective']) < 0) {
                        $best = $i;
                    }
                }
            }
            $s = max(0, (int) $hist[$best]['cents']);
        } else {
            $s = (int) ($e['salary_cents'] ?? 0);
        }
        if ($s > 0) {
            return $s;
        }

        $over = [];
        foreach ($e['contracts'] ?? [] as $c) {
            $cEnd = $c['ended_at'] ?? $c['end'] ?? null;
            if ((int) $c['salary_cents'] > 0
                && strcmp($c['start'], $ctx['end']) <= 0
                && ($cEnd === null || strcmp($cEnd, $ctx['start']) >= 0)) {
                $over[] = ['start' => $c['start'], 'end' => $cEnd, 'cents' => (int) $c['salary_cents']];
            }
        }
        usort($over, static fn (array $a, array $b): int => strcmp($b['start'], $a['start']));
        foreach ($over as $c) {
            if ($c['end'] === null || strcmp($c['end'], $ctx['end']) >= 0) {
                return $c['cents'];
            }
        }
        if (count($over) > 0) {
            return $over[0]['cents'];
        }

        return 0;
    }

    /**
     * PYR-SAL-03 flag.
     *
     * @param  array<string, mixed>  $e
     * @param  array<string, mixed>  $ctx
     */
    private static function salaryChanged(array $e, array $ctx): int
    {
        $hist = $e['salary_history'] ?? [];
        if (count($hist) < 2) {
            return 0;
        }
        $first = 0;
        foreach ($hist as $i => $row) {
            if (strcmp($row['effective'], $hist[$first]['effective']) < 0) {
                $first = $i;
            }
        }
        foreach ($hist as $i => $row) {
            if ($i !== $first && strcmp($row['effective'], $ctx['start']) > 0 && strcmp($row['effective'], $ctx['end']) <= 0) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Every field key answered for this person.
     *
     * @param  array<string, mixed>  $e
     * @param  array<string, mixed>  $ctx
     * @return list<string>
     */
    public static function fieldNames(array $e, array $ctx): array
    {
        $names = [
            'in_run', 'salary_changed_in_period', 'monthly_basic', 'days_in_period', 'days_employed', 'period_basic',
            'prorated_basic', 'daily_rate', 'unpaid_leave_deduction', 'basic_after_unpaid_leave', 'sick_deduction',
            'basic_after_sick_leave', 'lateness_step_logged', 'lateness_uncapped', 'lateness_ceiling',
            'lateness_loans_and_cost_sharing', 'lateness_cap', 'lateness_deduction', 'basic_after_lateness', 'basic',
            'overtime_normal', 'overtime_night', 'overtime_rest_day', 'overtime_holiday', 'overtime_holiday_night',
            'overtime',
        ];
        foreach (array_keys($ctx['allowances']) as $k) {
            $names[] = 'allowance.'.$k;
        }
        array_push($names, 'allowances_total', 'allowances_taxable', 'allowances_non_taxable', 'gross', 'taxable',
            'income_tax', 'pension_exempt', 'pensionable', 'employee_pension', 'employer_pension');
        foreach (array_keys($e['loans'] ?? []) as $k) {
            $names[] = 'loan.'.$k;
            $names[] = 'loan.'.$k.'.remaining_after';
        }
        array_push($names, 'loans', 'cost_sharing', 'cost_sharing_outstanding_after', 'other_deductions',
            'total_deductions', 'net');
        foreach (array_keys($e['loans'] ?? []) as $k) {
            $names[] = 'loan.'.$k.'.remaining_after_approval';
            $names[] = 'loan.'.$k.'.closed_after_approval';
        }
        $names[] = 'cost_sharing_outstanding_after_approval';

        return $names;
    }

    /**
     * @param  array<string, mixed>  $e
     * @param  array<string, mixed>  $ctx
     * @return array<string, int|NoOpinion>
     */
    public static function employee(array $e, array $ctx, PayrollReadings $r, ?Trace $t): array
    {
        $out = [];
        $deleted = (bool) ($e['deleted'] ?? false);
        $payable = self::payable($e, $ctx);
        self::mark($t, 'PYR-SAL-05', 'PYR-SAL-06', 'PYR-SAL-07', 'PYR-SAL-04');
        $B = $deleted || ! $payable ? 0 : self::salary($e, $ctx);
        if ($B <= 0) {
            $why = new NoOpinion('not in the run');
            foreach (self::fieldNames($e, $ctx) as $n) {
                $out[$n] = $why;
            }
            $out['in_run'] = 0;

            return $out;
        }
        self::mark($t, 'PYR-SAL-01', 'PYR-SAL-02', 'PYR-SAL-03', 'PYR-PER-01', 'PYR-PER-03', 'PYR-PER-06');

        $stat = $ctx['stat'];
        $wd = $ctx['wd'];
        $hours = $ctx['hours'];
        $days = $ctx['days'];
        $P = $ctx['pagume_days'];
        $dailyMode = $ctx['pagume'] === 'daily_rate';
        $inputs = $e['inputs'] ?? [];

        // Days employed (PYR-PRO-01).
        $hire = Dates::date($e['hire_date']);
        $term = $e['termination_date'] ?? null;
        $first = strcmp($hire, $ctx['start']) > 0 ? $hire : $ctx['start'];
        $last = ($term !== null && strcmp($term, $ctx['end']) < 0) ? $term : $ctx['end'];
        $de = strcmp($first, $last) > 0 ? 0 : self::countDays($first, $last);
        self::mark($t, 'PYR-PRO-01', 'PYR-PRO-03', 'PYR-PRO-04', 'PYR-PRO-02', 'PYR-PAG-01', 'PYR-PAG-02');

        // The Pagume step (PYR-PAG-02, -03, -04, -06).
        $O = $days - $P;
        if ($dailyMode && $P > 0) {
            $periodBasic = Exact::mulDivHalfUp($B, 12 * $P, 365)
                + ($O > 0 ? Exact::mulDivHalfUp($B, $O, $days) : 0);
            self::mark($t, 'PYR-PAG-03', 'PYR-PAG-04');
        } else {
            $periodBasic = $B;
            if ($r->flag('pagume_only_period_pays_zero') === true && ! $dailyMode && $P > 0 && $P === $days) {
                $periodBasic = 0;
            }
        }

        // Proration (PYR-PRO-02, PYR-PAG-05).
        if ($r->flag('pagume_after_proration') === true && $dailyMode && $P > 0) {
            $bp = Exact::mulDivHalfUp($B, $de, $days);
            $prorated = Exact::mulDivHalfUp($bp, 12 * $P, 365)
                + ($O > 0 ? Exact::mulDivHalfUp($bp, $O, $days) : 0);
        } else {
            $prorated = Exact::mulDivHalfUp($periodBasic, $de, $days);
        }

        $daily = Exact::mulDivHalfUp($B, 1, $wd);
        $once = $r->flag('round_deductions_once') === true;
        self::mark($t, 'PYR-RATE-01', 'PYR-ROUND-01', 'PYR-ROUND-03');

        // Unpaid leave (PYR-LEAVE-02, -03, -05, -06).
        $th = (int) ($inputs['unpaid_leave_thousandths'] ?? 0);
        $unpaid = 0;
        if ($th > 0) {
            $raw = $once ? Exact::mulDivHalfUp($B, $th, $wd * 1000) : Exact::mulDivHalfUp($daily, $th, 1000);
            $unpaid = min($raw, $prorated);
        }
        $afterUnpaid = $prorated - $unpaid;
        self::mark($t, 'PYR-LEAVE-01', 'PYR-LEAVE-02', 'PYR-LEAVE-03', 'PYR-LEAVE-05', 'PYR-LEAVE-06');

        // Sick leave (PYR-SICK-02).
        $sd = $inputs['sick_days'] ?? [];
        [$hn, $hd] = $stat['sick_half_pay_ratio'];
        $thSick = Exact::mulDivHalfUp(
            Exact::mul((int) ($sd['unpaid'] ?? 0), 1000 * $hd) + Exact::mul((int) ($sd['half'] ?? 0), 1000 * ($hd - $hn)),
            1,
            $hd
        );
        $sick = 0;
        if ($thSick > 0) {
            $raw = $once ? Exact::mulDivHalfUp($B, $thSick, $wd * 1000) : Exact::mulDivHalfUp($daily, $thSick, 1000);
            $sick = min($raw, $afterUnpaid);
        }
        $afterSick = $afterUnpaid - $sick;
        self::mark($t, 'PYR-SICK-01', 'PYR-SICK-02', 'PYR-SICK-03');

        // Loans and cost sharing as they stand (needed by the ceiling, PYR-LATE-04).
        $loanDed = [];
        $L = 0;
        foreach ($e['loans'] ?? [] as $key => $loan) {
            $d = ($loan['status'] ?? 'active') === 'active'
                ? max(0, min((int) $loan['instalment_cents'], (int) $loan['remaining_cents']))
                : 0;
            $loanDed[$key] = $d;
            $L += $d;
        }
        $cs = null;
        foreach ($e['cost_sharing'] ?? [] as $ob) {
            if (($ob['status'] ?? 'active') === 'active'
                && ($cs === null || strcmp($ob['started_on'], $cs['started_on']) < 0)) {
                $cs = $ob;
            }
        }
        $csRate = $cs === null ? 0 : Exact::mulDivHalfUp((int) $cs['rate_pct_x1000'], 1, 10);
        self::mark($t, 'PYR-LOAN-01', 'PYR-LOAN-05', 'PYR-CS-05', 'PYR-CS-06', 'PYR-CS-03');

        // Lateness (PYR-LATE-*).
        $lateNA = new NoOpinion('lateness deductions are off');
        $lateStep = 0;
        $lateUncapped = $lateCeiling = $lateLC = $lateCap = $lateDed = $afterLate = $lateNA;
        $basic = $afterSick;
        self::mark($t, 'PYR-LATE-01');
        if ($ctx['late_on']) {
            $lateStep = 1;
            $minutes = (int) ($inputs['lateness_minutes'] ?? 0);
            $down = $r->flag('lateness_rounds_down') === true;
            $num = $once ? $B : $daily;
            $den = $once ? Exact::mul($wd, $hours * 60) : $hours * 60;
            $lateUncapped = $down ? Exact::mulDivDown($num, $minutes, $den) : Exact::mulDivHalfUp($num, $minutes, $den);
            $cBase = $afterSick;
            if ($r->flag('ceiling_base') === 'prorated_basic') {
                $cBase = $prorated;
            } elseif ($r->flag('ceiling_base') === 'monthly_basic') {
                $cBase = $B;
            }
            [$cn, $cd] = $stat['deduction_cap_ratio'];
            $lateCeiling = Exact::mulDivDown($cBase, $cn, $cd);
            $C = 0;
            if ($cs !== null && $afterSick > 0 && (int) $cs['outstanding_cents'] > 0) {
                $C = min(Exact::mulDivDown($afterSick, $csRate, 10000), (int) $cs['outstanding_cents']);
            }
            $lateLC = $L + $C;
            $lateCap = max(0, $lateCeiling - $lateLC);
            $lateDed = min($lateUncapped, $lateCap);
            $afterLate = $afterSick - $lateDed;
            $basic = $afterLate;
            self::mark($t, 'PYR-LATE-02', 'PYR-LATE-03', 'PYR-LATE-04', 'PYR-LATE-05');
        }

        // Overtime (PYR-OT-*).
        $ot = [];
        $otTotal = 0;
        foreach (self::OT_KINDS as $kind) {
            $mins = (int) (($inputs['overtime_minutes'] ?? [])[$kind] ?? 0);
            $pay = $mins > 0
                ? Exact::mulDivHalfUp($B, Exact::mul($mins, $ctx['mult'][$kind]), Exact::mul($wd * $hours * 60, 100))
                : 0;
            $ot[$kind] = $pay;
            $otTotal += $pay;
        }
        self::mark($t, 'PYR-OT-01', 'PYR-OT-02', 'PYR-OT-03', 'PYR-OT-04');

        // Allowances (PYR-ALW-*).
        $alw = [];
        $aTotal = $aTax = $aNon = 0;
        foreach ($ctx['allowances'] as $key => $rule) {
            if ($rule['type'] === 'fixed') {
                $f = (int) $rule['amount_cents'];
                if ($r->flag('fixed_allowance_follows_pagume') === true && $dailyMode && $P > 0) {
                    $f = Exact::mulDivHalfUp($f, 12 * $P, 365) + ($O > 0 ? Exact::mulDivHalfUp($f, $O, $days) : 0);
                }
                $amount = Exact::mulDivHalfUp($f, $de, $days);
            } else {
                $amount = Exact::mulDivHalfUp($basic, (int) $rule['percent_x100'], 10000);
            }
            if ($amount <= 0) {
                $amount = 0;
            }
            $alw[$key] = $amount;
            $aTotal += $amount;
            if ((bool) $rule['taxable']) {
                $aTax += $amount;
            } else {
                $aNon += $amount;
            }
        }
        self::mark($t, 'PYR-ALW-01', 'PYR-ALW-02', 'PYR-ALW-03', 'PYR-ALW-04', 'PYR-ALW-05', 'PYR-ALW-06');

        $gross = $basic + $otTotal + $aTotal;
        $taxable = $gross - $aNon;
        $tax = self::tax($taxable, $ctx['start'], $stat);
        self::mark($t, 'PYR-GROSS-01', 'PYR-GROSS-02', 'PYR-TAX-03', 'PYR-TAX-04', 'PYR-TAX-06',
            strcmp($ctx['start'], '2025-07-07') >= 0 ? 'PYR-TAX-01' : 'PYR-TAX-02');

        // Pension (PYR-PEN-*).
        $nat = $e['nationality'] ?? 'ethiopian';
        $exempt = in_array($nat, $stat['pension_exempt_nationality'], true);
        if ($ctx['scheme'] === 'private') {
            if ($r->flag('p9_kind_exempt') === true && in_array($ctx['kind'], $stat['pension_p9_kinds'], true)) {
                $exempt = true;
            }
            if ($r->flag('domestic_worker_exempt') === true && (bool) ($e['domestic_worker'] ?? false)) {
                $exempt = true;
            }
        }
        [$rEmp, $rEr] = $stat['pension_rates_pct'][$ctx['scheme']];
        $penEmp = $exempt ? 0 : Exact::mulDivHalfUp($basic, $rEmp, 100);
        $penEr = $exempt ? 0 : Exact::mulDivHalfUp($basic, $rEr, 100);
        self::mark($t, 'PYR-PEN-01', 'PYR-PEN-02', 'PYR-PEN-03', 'PYR-PEN-04', 'PYR-PEN-05', 'PYR-PEN-06',
            'PYR-PEN-09', 'PYR-PEN-10', 'PYR-PEN-12', 'PYR-PEN-13', 'PYR-PEN-15', 'PYR-PEN-07', 'PYR-PEN-08');

        // Cost sharing on gross (PYR-CS-01).
        $csDed = 0;
        $csAfter = new NoOpinion('no cost-sharing obligation');
        $csAfterApproval = new NoOpinion('no cost-sharing obligation');
        if ($cs !== null) {
            $out0 = (int) $cs['outstanding_cents'];
            if ($gross > 0 && $out0 > 0) {
                $csDed = min(Exact::mulDivDown($gross, $csRate, 10000), $out0);
            }
            $csAfter = $out0 - $csDed;
            $csAfterApproval = $ctx['approve'] ? max(0, $out0 - $csDed) : new NoOpinion('not an approved run');
            self::mark($t, 'PYR-CS-01', 'PYR-CS-04', 'PYR-CS-07');
        }

        $total = $tax + $penEmp + $L + $csDed;
        $net = $gross - $total;
        self::mark($t, 'PYR-NET-01', 'PYR-LOAN-02', 'PYR-LOAN-03', 'PYR-LOAN-04', 'PYR-ROUND-02');

        // Assemble in the order of the case format.
        $out['in_run'] = 1;
        $out['salary_changed_in_period'] = self::salaryChanged($e, $ctx);
        $out['monthly_basic'] = $B;
        $out['days_in_period'] = $days;
        $out['days_employed'] = $de;
        $out['period_basic'] = $periodBasic;
        $out['prorated_basic'] = $prorated;
        $out['daily_rate'] = $daily;
        $out['unpaid_leave_deduction'] = $unpaid;
        $out['basic_after_unpaid_leave'] = $afterUnpaid;
        $out['sick_deduction'] = $sick;
        $out['basic_after_sick_leave'] = $afterSick;
        $out['lateness_step_logged'] = $lateStep;
        $out['lateness_uncapped'] = $lateUncapped;
        $out['lateness_ceiling'] = $lateCeiling;
        $out['lateness_loans_and_cost_sharing'] = $lateLC;
        $out['lateness_cap'] = $lateCap;
        $out['lateness_deduction'] = $lateDed;
        $out['basic_after_lateness'] = $afterLate;
        $out['basic'] = $basic;
        foreach (self::OT_KINDS as $kind) {
            $out['overtime_'.$kind] = $ot[$kind];
        }
        $out['overtime'] = $otTotal;
        foreach ($alw as $key => $amount) {
            $out['allowance.'.$key] = $amount;
        }
        $out['allowances_total'] = $aTotal;
        $out['allowances_taxable'] = $aTax;
        $out['allowances_non_taxable'] = $aNon;
        $out['gross'] = $gross;
        $out['taxable'] = $taxable;
        $out['income_tax'] = $tax;
        $out['pension_exempt'] = $exempt ? 1 : 0;
        $out['pensionable'] = $basic;
        $out['employee_pension'] = $penEmp;
        $out['employer_pension'] = $penEr;
        foreach ($e['loans'] ?? [] as $key => $loan) {
            $out['loan.'.$key] = $loanDed[$key];
            $out['loan.'.$key.'.remaining_after'] = (int) $loan['remaining_cents'] - $loanDed[$key];
        }
        $out['loans'] = $L;
        $out['cost_sharing'] = $csDed;
        $out['cost_sharing_outstanding_after'] = $csAfter;
        $out['other_deductions'] = $L + $csDed;
        $out['total_deductions'] = $total;
        $out['net'] = $net;
        foreach ($e['loans'] ?? [] as $key => $loan) {
            $active = ($loan['status'] ?? 'active') === 'active';
            $rem = max(0, (int) $loan['remaining_cents'] - $loanDed[$key]);
            if (! $ctx['approve']) {
                $out['loan.'.$key.'.remaining_after_approval'] = new NoOpinion('not an approved run');
                $out['loan.'.$key.'.closed_after_approval'] = new NoOpinion('not an approved run');
            } else {
                $out['loan.'.$key.'.remaining_after_approval'] = $rem;
                $out['loan.'.$key.'.closed_after_approval'] = $active
                    ? ($rem === 0 ? 1 : 0)
                    : new NoOpinion('the register does not say what approval does to a loan that is not active');
            }
        }
        $out['cost_sharing_outstanding_after_approval'] = $csAfterApproval;

        return $out;
    }

    /**
     * @param  array<string, mixed>  $stat
     */
    private static function tax(int $taxable, string $start, array $stat): int
    {
        if ($taxable < 0) {
            return 0;
        }
        foreach ($stat['income_tax_ladders'] as $ladder) {
            if (strcmp($start, $ladder['from']) >= 0 && ($ladder['to'] === null || strcmp($start, $ladder['to']) <= 0)) {
                foreach ($ladder['bands'] as [$floor, $top, $rate, $deduction]) {
                    if ($taxable >= $floor && ($top === null || $taxable <= $top)) {
                        return Exact::mulDivHalfUp($taxable, $rate, 100) - $deduction;
                    }
                }

                return 0;
            }
        }
        throw new InvalidArgumentException('no income tax ladder in force on '.$start);
    }
}
