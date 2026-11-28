<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle\Payroll;

use Tests\Accuracy\Oracle\NoOpinion;
use Tests\Accuracy\Oracle\Trace;
use Tests\Accuracy\Oracle\Undecided;

/**
 * The payroll oracle: an independent implementation of the rule register in
 * integers. Every field is computed under the baseline reading and again under
 * each alternative reading alone; a field that moves is answered as Undecided.
 */
final class PayslipOracle
{
    /** Fields of a person with no expected value when net pay is below 0 (PYR-NET-02). */
    private const NEGATIVE_NET_FIELDS = [
        'net', 'loans', 'cost_sharing', 'cost_sharing_outstanding_after', 'other_deductions', 'total_deductions',
        'cost_sharing_outstanding_after_approval',
    ];

    /** Run figures built on net, loans and cost sharing (PYR-NET-02). */
    private const NEGATIVE_NET_RUN_FIELDS = [
        'register.net_total', 'journal.2300.credit', 'journal.1400.credit', 'journal.2400.credit', 'journal.balanced',
        'bank.total', 'bank_page.total',
    ];

    private const BANK_RULES = ['PYR-BANK-04', 'PYR-BANK-05', 'PYR-BANK-06'];

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $statutory
     * @return array{
     *   employees: array<string, array<string, int|Undecided|NoOpinion>>,
     *   run: array<string, int|Undecided|NoOpinion>,
     *   traces: array<string, array{decided: list<string>, implemented: list<string>, undecided: list<string>}>,
     *   meta: array{decided: list<string>, implemented: list<string>, undecided: list<string>}
     * }
     */
    public static function compute(array $question, array $statutory): array
    {
        $ctx = PayslipCalculator::context($question, $statutory);
        $employeeTraces = [];
        $runTrace = new Trace;
        $base = self::under($question, $ctx, new PayrollReadings, $employeeTraces, $runTrace);

        $employees = $base['employees'];
        $run = $base['run'];

        foreach (PayrollReadings::alternatives() as [$rule, $readings]) {
            $alt = self::under($question, $ctx, $readings);
            foreach ($base['employees'] as $code => $fields) {
                foreach ($fields as $name => $value) {
                    if (is_int($value) && is_int($alt['employees'][$code][$name]) && $value !== $alt['employees'][$code][$name]) {
                        $employees[$code][$name] = self::blank($employees[$code][$name], [$rule], $value);
                    }
                }
            }
            foreach ($base['run'] as $name => $value) {
                if (is_int($value) && is_int($alt['run'][$name]) && $value !== $alt['run'][$name]) {
                    $run[$name] = self::blank($run[$name], [$rule], $value);
                }
            }
        }

        // PYR-NET-02: a person whose net pay is below 0 has no expected value for these.
        $anyNegative = false;
        foreach ($base['employees'] as $code => $fields) {
            if (($fields['in_run'] ?? 0) !== 1 || ! is_int($fields['net']) || $fields['net'] >= 0) {
                continue;
            }
            $anyNegative = true;
            $names = self::NEGATIVE_NET_FIELDS;
            foreach (array_keys($fields) as $name) {
                if (str_starts_with($name, 'loan.')) {
                    $names[] = $name;
                }
            }
            foreach ($names as $name) {
                if (is_int($fields[$name])) {
                    $employees[$code][$name] = self::blank($employees[$code][$name], ['PYR-NET-02'], $fields[$name]);
                }
            }
        }
        if ($anyNegative) {
            foreach (self::NEGATIVE_NET_RUN_FIELDS as $name) {
                if (isset($base['run'][$name]) && is_int($base['run'][$name])) {
                    $run[$name] = self::blank($run[$name], ['PYR-NET-02'], $base['run'][$name]);
                }
            }
        }

        // PYR-BANK-04, -05, -06: a person deleted after approval leaves every run figure without a value.
        if (($question['run'] ?? 'preview') === 'approve' && count($question['after_approval']['delete'] ?? []) > 0) {
            foreach ($base['run'] as $name => $value) {
                if (is_int($value)) {
                    $run[$name] = self::blank($run[$name], self::BANK_RULES, $value);
                }
            }
        }

        // Traces.
        $traces = [];
        $meta = ['decided' => [], 'implemented' => [], 'undecided' => []];
        $union = new Trace;
        foreach ($employees as $code => $fields) {
            $t = $employeeTraces[$code];
            $union->merge($t);
            $traces[$code] = [
                'decided' => $t->decidedRules(),
                'implemented' => $t->implementedRules(),
                'undecided' => self::undecidedRules($fields),
            ];
            $meta['undecided'] = array_merge($meta['undecided'], $traces[$code]['undecided']);
        }
        $union->merge($runTrace);
        $meta['undecided'] = array_merge($meta['undecided'], self::undecidedRules($run));
        $meta['undecided'] = array_values(array_unique($meta['undecided']));
        sort($meta['undecided']);
        $meta['decided'] = $union->decidedRules();
        $meta['implemented'] = $union->implementedRules();

        return ['employees' => $employees, 'run' => $run, 'traces' => $traces, 'meta' => $meta];
    }

    /**
     * Every figure under one reading, with no Undecided marks.
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $statutory
     * @return array{employees: array<string, array<string, int|NoOpinion>>, run: array<string, int|NoOpinion>}
     */
    public static function computeUnder(array $question, array $statutory, PayrollReadings $readings): array
    {
        return self::under($question, PayslipCalculator::context($question, $statutory), $readings);
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $ctx
     * @param  array<string, Trace>|null  $employeeTraces
     * @return array{employees: array<string, array<string, int|NoOpinion>>, run: array<string, int|NoOpinion>}
     */
    private static function under(array $question, array $ctx, PayrollReadings $readings, ?array &$employeeTraces = null, ?Trace $runTrace = null): array
    {
        $employees = [];
        $payableNotDeleted = 0;
        foreach ($question['employees'] as $code => $e) {
            $t = new Trace;
            $employees[$code] = PayslipCalculator::employee($e, $ctx, $readings, $t);
            if ($employeeTraces !== null) {
                $employeeTraces[$code] = $t;
            }
            if (! (bool) ($e['deleted'] ?? false) && PayslipCalculator::payable($e, $ctx)) {
                $payableNotDeleted++;
            }
        }

        $run = [];
        if ($ctx['approve']) {
            $entries = 0;
            $sum = ['gross' => 0, 'income_tax' => 0, 'employee_pension' => 0, 'employer_pension' => 0, 'net' => 0,
                'loans' => 0, 'cost_sharing' => 0];
            $pensionRows = 0;
            $pensionEmployee = 0;
            foreach ($employees as $f) {
                if ($f['in_run'] !== 1) {
                    continue;
                }
                $entries++;
                foreach (array_keys($sum) as $k) {
                    $sum[$k] += $f[$k];
                }
                if ($f['pension_exempt'] === 0) {
                    $pensionRows++;
                    $pensionEmployee += $f['employee_pension'];
                }
            }
            $run['run.entries'] = $entries;
            $run['run.skipped'] = $payableNotDeleted - $entries;
            $run['register.gross_total'] = $sum['gross'];
            $run['register.income_tax_total'] = $sum['income_tax'];
            $run['register.employee_pension_total'] = $sum['employee_pension'];
            $run['register.employer_pension_total'] = $sum['employer_pension'];
            $run['register.net_total'] = $sum['net'];
            $run['journal.5100.debit'] = $sum['gross'];
            $run['journal.5200.debit'] = $sum['employer_pension'];
            $run['journal.2100.credit'] = $sum['income_tax'];
            $run['journal.2200.credit'] = $sum['employee_pension'];
            $run['journal.2201.credit'] = $sum['employer_pension'];
            $run['journal.2300.credit'] = $sum['net'];
            $run['journal.1400.credit'] = $sum['loans'];
            $run['journal.2400.credit'] = $sum['cost_sharing'];
            $debits = $sum['gross'] + $sum['employer_pension'];
            $credits = $sum['income_tax'] + $sum['employee_pension'] + $sum['employer_pension'] + $sum['net']
                + $sum['loans'] + $sum['cost_sharing'];
            $run['journal.balanced'] = $debits === $credits ? 1 : 0;
            $run['schedule.income_tax_total'] = $sum['income_tax'];
            $run['schedule.pension_rows'] = $pensionRows;
            $run['schedule.pension_employee_total'] = $pensionEmployee;
            $run['bank.rows'] = $entries;
            $run['bank.total'] = $sum['net'];
            $run['bank_page.rows'] = $entries;
            $run['bank_page.total'] = $sum['net'];
            PayslipCalculator::mark($runTrace, 'PYR-RST-01', 'PYR-RST-02', 'PYR-RST-03', 'PYR-BANK-02', 'PYR-BANK-03',
                'PYR-SAL-04', 'PYR-SAL-05');
        }

        return ['employees' => $employees, 'run' => $run];
    }

    /**
     * @param  list<string>  $rules
     */
    private static function blank(mixed $current, array $rules, int $baseline): Undecided
    {
        $have = $current instanceof Undecided ? $current->rules : [];
        foreach ($rules as $rule) {
            if (! in_array($rule, $have, true)) {
                $have[] = $rule;
            }
        }

        return new Undecided($have, $baseline);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    private static function undecidedRules(array $fields): array
    {
        $rules = [];
        foreach ($fields as $value) {
            if ($value instanceof Undecided) {
                $rules = array_merge($rules, $value->rules);
            }
        }
        $rules = array_values(array_unique($rules));
        sort($rules);

        return $rules;
    }
}
