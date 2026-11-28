<?php

declare(strict_types=1);

namespace Tests\Accuracy\Payroll;

/**
 * The field catalogue of the payroll case format (spec 1.3 and 1.4): every
 * name a case may state in `expect`, with the rule ids that govern it. The
 * validator, the engine reader and the comparer all read this one list, so a
 * field exists in all three or in none.
 *
 * Names are the other builders' contract: do not rename one here.
 */
final class PayrollFields
{
    public const OVERTIME_KINDS = ['normal', 'night', 'rest_day', 'holiday', 'holiday_night'];

    /** Fields that exist only when the organisation switched lateness deductions on. */
    public const LATENESS = [
        'lateness_uncapped', 'lateness_ceiling', 'lateness_loans_and_cost_sharing', 'lateness_cap',
        'lateness_deduction', 'basic_after_lateness',
    ];

    /**
     * Per-employee fixed names, in the engine's order of steps, with their rule ids.
     *
     * @return array<string, list<string>>
     */
    public static function employee(): array
    {
        static $fields = null;

        return $fields ??= [
            'in_run' => ['PYR-SAL-04', 'PYR-SAL-05', 'PYR-SAL-06', 'PYR-PRO-04'],
            'salary_changed_in_period' => ['PYR-SAL-03'],
            'monthly_basic' => ['PYR-SAL-01', 'PYR-SAL-02', 'PYR-SAL-03'],
            'days_in_period' => ['PYR-PER-01'],
            'days_employed' => ['PYR-PRO-01', 'PYR-PRO-03', 'PYR-PRO-04'],
            'period_basic' => ['PYR-PAG-02', 'PYR-PAG-03', 'PYR-PAG-04', 'PYR-PAG-06'],
            'prorated_basic' => ['PYR-PRO-02', 'PYR-PAG-05'],
            'daily_rate' => ['PYR-RATE-01', 'PYR-ROUND-04'],
            'unpaid_leave_deduction' => ['PYR-LEAVE-02', 'PYR-LEAVE-03', 'PYR-LEAVE-05'],
            'basic_after_unpaid_leave' => ['PYR-LEAVE-02'],
            'sick_deduction' => ['PYR-SICK-02'],
            'basic_after_sick_leave' => ['PYR-SICK-02'],
            'lateness_step_logged' => ['PYR-LATE-01'],
            'lateness_uncapped' => ['PYR-LATE-02', 'PYR-ROUND-04', 'PYR-ROUND-05'],
            'lateness_ceiling' => ['PYR-LATE-03', 'PYR-LATE-06'],
            'lateness_loans_and_cost_sharing' => ['PYR-LATE-04'],
            'lateness_cap' => ['PYR-LATE-04'],
            'lateness_deduction' => ['PYR-LATE-04', 'PYR-LATE-05'],
            'basic_after_lateness' => ['PYR-LATE-05'],
            'basic' => ['PYR-GROSS-01', 'PYR-PEN-03', 'PYR-RST-04'],
            'overtime_normal' => ['PYR-OT-01', 'PYR-OT-02', 'PYR-OT-03', 'PYR-OT-04'],
            'overtime_night' => ['PYR-OT-01', 'PYR-OT-02', 'PYR-OT-03', 'PYR-OT-04'],
            'overtime_rest_day' => ['PYR-OT-01', 'PYR-OT-02', 'PYR-OT-03', 'PYR-OT-04'],
            'overtime_holiday' => ['PYR-OT-01', 'PYR-OT-02', 'PYR-OT-03', 'PYR-OT-04'],
            'overtime_holiday_night' => ['PYR-OT-01', 'PYR-OT-02', 'PYR-OT-03', 'PYR-OT-04'],
            'overtime' => ['PYR-OT-01'],
            'allowances_total' => ['PYR-ALW-04'],
            'allowances_taxable' => ['PYR-ALW-04'],
            'allowances_non_taxable' => ['PYR-ALW-04'],
            'gross' => ['PYR-GROSS-01'],
            'taxable' => ['PYR-GROSS-02'],
            'income_tax' => ['PYR-TAX-01', 'PYR-TAX-02', 'PYR-TAX-03', 'PYR-TAX-04', 'PYR-TAX-05', 'PYR-TAX-06'],
            'pension_exempt' => ['PYR-PEN-07', 'PYR-PEN-08', 'PYR-PEN-11', 'PYR-PEN-14'],
            'pensionable' => ['PYR-PEN-03', 'PYR-PEN-04', 'PYR-PEN-06'],
            'employee_pension' => ['PYR-PEN-01', 'PYR-PEN-02', 'PYR-PEN-05', 'PYR-PEN-09', 'PYR-PEN-10', 'PYR-PEN-13'],
            'employer_pension' => ['PYR-PEN-01', 'PYR-PEN-02', 'PYR-PEN-05', 'PYR-PEN-09', 'PYR-PEN-10', 'PYR-PEN-13'],
            'loans' => ['PYR-LOAN-01', 'PYR-LOAN-02'],
            'cost_sharing' => ['PYR-CS-01', 'PYR-CS-03', 'PYR-CS-04', 'PYR-CS-05'],
            'cost_sharing_outstanding_after' => ['PYR-CS-01'],
            'other_deductions' => ['PYR-NET-01'],
            'total_deductions' => ['PYR-NET-01'],
            'net' => ['PYR-NET-01', 'PYR-NET-02'],
            'cost_sharing_outstanding_after_approval' => ['PYR-CS-01'],
        ];
    }

    /**
     * Run-level names (only with run = approve).
     *
     * @return array<string, list<string>>
     */
    public static function run(): array
    {
        static $fields = null;

        if ($fields !== null) {
            return $fields;
        }

        $fields = [
            'run.entries' => ['PYR-SAL-04', 'PYR-SAL-05', 'PYR-SAL-06'],
            'run.skipped' => ['PYR-SAL-04', 'PYR-SAL-06'],
        ];
        foreach (['gross_total', 'income_tax_total', 'employee_pension_total', 'employer_pension_total', 'net_total'] as $name) {
            $fields['register.'.$name] = ['PYR-RST-01'];
        }
        foreach (['5100.debit', '5200.debit', '2100.credit', '2200.credit', '2201.credit', '2300.credit', '1400.credit', '2400.credit', 'balanced'] as $name) {
            $fields['journal.'.$name] = ['PYR-RST-02'];
        }
        foreach (['income_tax_total', 'pension_rows', 'pension_employee_total'] as $name) {
            $fields['schedule.'.$name] = ['PYR-RST-03'];
        }
        $fields['bank.rows'] = ['PYR-BANK-02', 'PYR-BANK-03', 'PYR-BANK-05', 'PYR-BANK-06'];
        $fields['bank.total'] = ['PYR-BANK-02', 'PYR-BANK-03', 'PYR-BANK-05', 'PYR-BANK-06'];
        $fields['bank_page.rows'] = ['PYR-BANK-03', 'PYR-BANK-06'];
        $fields['bank_page.total'] = ['PYR-BANK-03', 'PYR-BANK-06'];

        return $fields;
    }

    /**
     * What a stated employee field is: a fixed name, an allowance line, or one
     * of the four loan fields. Null for a name the format does not list.
     *
     * @return array{kind: string, key: ?string}|null
     */
    public static function classify(string $field): ?array
    {
        if (array_key_exists($field, self::employee())) {
            return ['kind' => 'fixed', 'key' => null];
        }

        if (str_starts_with($field, 'allowance.') && strlen($field) > 10) {
            return ['kind' => 'allowance', 'key' => substr($field, 10)];
        }

        if (str_starts_with($field, 'loan.') && strlen($field) > 5) {
            $rest = substr($field, 5);
            $suffixes = [
                '.remaining_after_approval' => 'loan_remaining_after_approval',
                '.closed_after_approval' => 'loan_closed_after_approval',
                '.remaining_after' => 'loan_remaining_after',
            ];
            foreach ($suffixes as $suffix => $kind) {
                if (str_ends_with($rest, $suffix) && strlen($rest) > strlen($suffix)) {
                    return ['kind' => $kind, 'key' => substr($rest, 0, -strlen($suffix))];
                }
            }

            return ['kind' => 'loan', 'key' => $rest];
        }

        return null;
    }

    /** True for a name that only a case with run = approve may state. */
    public static function needsApproval(string $field): bool
    {
        if ($field === 'cost_sharing_outstanding_after_approval') {
            return true;
        }
        $class = self::classify($field);

        return $class !== null && in_array($class['kind'], ['loan_remaining_after_approval', 'loan_closed_after_approval'], true);
    }

    /**
     * The rule ids that govern a field, for the failure message.
     *
     * @return list<string>
     */
    public static function rulesOf(string $field): array
    {
        $fixed = self::employee();
        if (isset($fixed[$field])) {
            return $fixed[$field];
        }
        $run = self::run();
        if (isset($run[$field])) {
            return $run[$field];
        }

        return match (self::classify($field)['kind'] ?? null) {
            'allowance' => ['PYR-ALW-02', 'PYR-ALW-03', 'PYR-ALW-05', 'PYR-ALW-07'],
            'loan' => ['PYR-LOAN-01', 'PYR-LOAN-04', 'PYR-LOAN-05'],
            'loan_remaining_after', 'loan_remaining_after_approval', 'loan_closed_after_approval' => ['PYR-LOAN-03'],
            default => [],
        };
    }
}
