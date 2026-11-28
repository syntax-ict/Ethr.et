<?php

declare(strict_types=1);

namespace Tests\Accuracy\Payroll;

use App\Models\Employee;
use App\Models\EmployeeCostSharing;
use App\Models\EmployeeLoan;
use App\Models\EmployeeSalaryHistory;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Services\Accounting\AccountingExportService;
use App\Services\CurrentTenant;
use App\Services\Payroll\BankExportService;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\RunRegisterService;
use App\Services\Payroll\StatutorySchedules;
use Carbon\Carbon;
use Throwable;

/**
 * Runs the engine on a built case and maps what it wrote to the field names of
 * the case format (spec 3.2). It reads, it never computes a figure the engine
 * did not itself write; the one decode is `days_employed`, the engine's own
 * float factor times the days of the period.
 *
 * Steps are found by their `step` key, never by position: the lateness step is
 * optional, and `proration` is logged before `pagumen_proration`.
 */
final class EngineReader
{
    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $built  PayrollFixture::build()
     * @return array{
     *     answer: array{employees: array<string, array<string, int>>, run: array<string, int>},
     *     echo: list<string>,
     *     consistency: list<string>,
     *     notes: list<string>
     * }
     */
    public static function read(array $case, array $built): array
    {
        $period = $case['period'];
        $start = Carbon::parse($period['start']);
        $end = Carbon::parse($period['end']);
        $tenantId = $built['tenant']->id;
        $engine = app(PayrollEngine::class);
        $approve = ($case['run'] ?? 'preview') === 'approve';
        $consistency = [];
        $echo = [];

        $preview = $engine->preview($tenantId, $start, $end, null, null, 1000);
        $byId = [];
        foreach ($built['employees'] as $key => $employee) {
            $byId[$employee->id] = $key;
        }
        $attributes = [];
        foreach ($preview['entries'] as $entry) {
            $attributes[$byId[$entry['employee']->id]] = $entry['attributes'];
        }

        $run = null;
        if ($approve) {
            try {
                $run = $engine->process($tenantId, $start, $end, $built['user']->id)->run;
                $run = $engine->approve($run->fresh(), $built['user']->id);
            } catch (Throwable $e) {
                throw FixtureError::fixture('the run was refused: '.$e->getMessage());
            }

            $stored = [];
            foreach (PayrollEntry::query()->where('payroll_run_id', $run->id)->get() as $entry) {
                $stored[$byId[$entry->employee_id]] = self::storedAttributes($entry);
            }
            foreach (array_unique([...array_keys($attributes), ...array_keys($stored)]) as $key) {
                $a = self::normalise($attributes[$key] ?? null);
                $b = self::normalise($stored[$key] ?? null);
                if ($a !== $b) {
                    $consistency[] = "PYR-RUN-01: employee {$key}: the preview and the stored entry differ: ".self::firstDifference($a, $b);
                }
            }
            $attributes = $stored;
        }

        $employees = [];
        foreach ($case['employees'] as $key => $row) {
            $employee = $built['employees'][$key];
            $echo = [...$echo, ...self::historyEcho($key, $employee, $row)];

            if (! isset($attributes[$key])) {
                $employees[$key] = ['in_run' => 0];

                continue;
            }

            [$fields, $problems, $input] = self::fields($case, $key, $row, $attributes[$key], $built, $approve);
            $employees[$key] = $fields;
            $consistency = [...$consistency, ...$problems];
            $echo = [...$echo, ...$input];
        }

        $runFields = [];
        if ($approve && $run !== null) {
            $runFields = self::runFields($case, $built, $run);
        }

        return [
            'answer' => ['employees' => $employees, 'run' => $runFields],
            'echo' => $echo,
            'consistency' => $consistency,
            'notes' => $built['notes'],
        ];
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $built
     * @return array{0: array<string, int>, 1: list<string>, 2: list<string>}
     */
    private static function fields(array $case, string $key, array $row, array $attributes, array $built, bool $approve): array
    {
        $period = $case['period'];
        $org = $case['org'] ?? [];
        $inputs = $row['inputs'] ?? [];
        $log = $attributes['calculation_log'];
        $step = static fn (string $name): ?array => collect($log['steps'])->firstWhere('step', $name);
        $days = (int) Carbon::parse($period['start'])->diffInDays(Carbon::parse($period['end'])) + 1;

        $proration = $step('proration');
        $pagume = $step('pagumen_proration');
        $unpaid = $step('unpaid_leave');
        $sick = $step('sick_leave');
        $lateness = $step('lateness');
        $overtime = $step('overtime');
        $allowances = $step('allowances');
        $gross = $step('gross');
        $tax = $step('income_tax');
        $pension = $step('pension');
        $loanStep = $step('loan_deductions');
        $costSharing = $step('cost_sharing');
        $net = $step('net_calculation');
        $outputs = $log['outputs'];

        $f = [];
        $problems = [];
        $echo = [];
        $same = static function (string $what, mixed $value, array $others) use (&$problems, $key): void {
            foreach ($others as $name => $other) {
                if ($other !== $value) {
                    $problems[] = "employee {$key}: {$what} is ".json_encode($value)." but {$name} is ".json_encode($other);
                }
            }
        };

        $f['in_run'] = 1;
        $f['salary_changed_in_period'] = (int) $log['inputs']['salary_changed_in_period'];
        $f['monthly_basic'] = $log['inputs']['basic_salary_cents'];
        $same('monthly_basic', $f['monthly_basic'], ['proration.original_basic_cents' => $proration['original_basic_cents'], 'overtime.hourly_rate_base_cents' => $overtime['hourly_rate_base_cents']]);
        $f['days_employed'] = (int) round($proration['proration_factor'] * $days);
        $f['period_basic'] = $proration['period_basic_cents'];
        $same('period_basic', $f['period_basic'], ['pagumen_proration.period_basic_cents' => $pagume['period_basic_cents']]);
        $f['prorated_basic'] = $proration['prorated_basic_cents'];
        $f['daily_rate'] = $unpaid['daily_rate_cents'];
        $same('daily_rate', $f['daily_rate'], ['sick_leave.daily_rate_cents' => $sick['daily_rate_cents']]);
        $f['unpaid_leave_deduction'] = $unpaid['deduction_cents'];
        $f['basic_after_unpaid_leave'] = $unpaid['basic_after_unpaid_leave_cents'];
        $f['sick_deduction'] = $sick['deduction_cents'];
        $f['basic_after_sick_leave'] = $sick['basic_after_sick_leave_cents'];
        $f['lateness_step_logged'] = $lateness === null ? 0 : 1;
        if ($lateness !== null) {
            $f['lateness_uncapped'] = $lateness['uncapped_cents'];
            $f['lateness_ceiling'] = $lateness['third_of_basic_cents'];
            $f['lateness_loans_and_cost_sharing'] = $lateness['other_deductions_cents'];
            $f['lateness_cap'] = $lateness['cap_cents'];
            $f['lateness_deduction'] = $lateness['deduction_cents'];
            $f['basic_after_lateness'] = $lateness['basic_after_lateness_cents'];
        }

        $f['basic'] = $attributes['basic_salary_cents'];
        $same('basic', $f['basic'], ['gross.prorated_basic_cents' => $gross['prorated_basic_cents'], 'pension.pensionable_basic_cents' => $pension['pensionable_basic_cents']]);

        $byKind = 0;
        foreach (PayrollFields::OVERTIME_KINDS as $kind) {
            $f['overtime_'.$kind] = $overtime['by_type'][$kind]['amount_cents'];
            $byKind += $f['overtime_'.$kind];
        }
        $f['overtime'] = $overtime['overtime_amount_cents'];
        $same('overtime', $f['overtime'], ['gross.overtime_cents' => $gross['overtime_cents'], 'the five kinds added' => $byKind]);

        foreach (array_keys($org['allowances'] ?? []) as $name) {
            $line = collect($allowances['items'])->firstWhere('name', (string) $name);
            $f['allowance.'.$name] = $line === null ? 0 : $line['amount_cents'];
        }
        $f['allowances_total'] = $allowances['total_allowances_cents'];
        $f['allowances_taxable'] = $allowances['taxable_allowances_cents'];
        $f['allowances_non_taxable'] = $allowances['non_taxable_allowances_cents'];
        $same('allowances_total', $f['allowances_total'], ['gross.allowances_cents' => $gross['allowances_cents']]);
        $same('allowances_non_taxable', $f['allowances_non_taxable'], ['income_tax.non_taxable_allowances_cents' => $tax['non_taxable_allowances_cents']]);

        $f['gross'] = $attributes['gross_cents'];
        $same('gross', $f['gross'], [
            'gross.gross_cents' => $gross['gross_cents'], 'outputs.gross_cents' => $outputs['gross_cents'],
            'income_tax.gross_cents' => $tax['gross_cents'], 'cost_sharing.gross_cents' => $costSharing['gross_cents'],
            'net_calculation.gross_cents' => $net['gross_cents'],
        ]);
        $f['taxable'] = $tax['taxable_amount_cents'];
        $f['income_tax'] = $attributes['income_tax_cents'];
        $same('income_tax', $f['income_tax'], ['income_tax.income_tax_cents' => $tax['income_tax_cents'], 'outputs.income_tax_cents' => $outputs['income_tax_cents']]);

        $f['pension_exempt'] = (int) $pension['exempt'];
        $f['pensionable'] = $pension['pensionable_cents'];
        $f['employee_pension'] = $attributes['employee_pension_cents'];
        $f['employer_pension'] = $attributes['employer_pension_cents'];
        $same('employee_pension', $f['employee_pension'], ['pension.employee_pension_cents' => $pension['employee_pension_cents'], 'outputs.employee_pension_cents' => $outputs['employee_pension_cents']]);
        $same('employer_pension', $f['employer_pension'], ['pension.employer_pension_cents' => $pension['employer_pension_cents'], 'outputs.employer_pension_cents' => $outputs['employer_pension_cents']]);

        $loanIds = $built['loans'][$key] ?? [];
        foreach ($row['loans'] ?? [] as $loanKey => $loan) {
            $line = collect($loanStep['loans'])->firstWhere('loan_id', $loanIds[$loanKey]);
            $f["loan.{$loanKey}"] = $line === null ? 0 : $line['deduction_cents'];
            $f["loan.{$loanKey}.remaining_after"] = $line === null ? $loan['remaining_cents'] : $line['remaining_after_cents'];
            if ($line !== null) {
                $same("loan.{$loanKey} remaining_before", $line['remaining_before_cents'], ['the case remaining_cents' => $loan['remaining_cents']]);
            }
        }
        $f['loans'] = $loanStep['loan_deduction_cents'];
        $byType = static fn (string $type): int => (int) collect($attributes['deductions'] ?? [])->where('type', $type)->sum('amount_cents');
        $same('loans', $f['loans'], ['outputs.loan_deduction_cents' => $outputs['loan_deduction_cents'], 'deductions[loan]' => $byType('loan')]);

        $f['cost_sharing'] = $costSharing['cost_sharing_cents'];
        if ($costSharing['outstanding_after_cents'] !== null) {
            $f['cost_sharing_outstanding_after'] = $costSharing['outstanding_after_cents'];
        }
        $same('cost_sharing', $f['cost_sharing'], ['outputs.cost_sharing_cents' => $outputs['cost_sharing_cents'], 'deductions[cost_sharing]' => $byType('cost_sharing')]);

        $f['other_deductions'] = $attributes['other_deductions_cents'];
        $f['total_deductions'] = $net['total_deductions_cents'];
        $f['net'] = $attributes['net_cents'];
        $same('net', $f['net'], ['outputs.net_cents' => $outputs['net_cents'], 'net_calculation.net_cents' => $net['net_cents']]);

        if ($approve) {
            foreach ($row['loans'] ?? [] as $loanKey => $loan) {
                $stored = EmployeeLoan::query()->where('public_id', $loanIds[$loanKey])->first();
                if ($stored !== null) {
                    $f["loan.{$loanKey}.remaining_after_approval"] = $stored->remaining_cents;
                    $f["loan.{$loanKey}.closed_after_approval"] = $stored->status === 'completed' ? 1 : 0;
                }
            }
            if (($costSharing['obligation_id'] ?? null) !== null) {
                $obligation = EmployeeCostSharing::query()->where('public_id', $costSharing['obligation_id'])->first()?->refresh();
                if ($obligation !== null) {
                    $f['cost_sharing_outstanding_after_approval'] = $obligation->outstanding_cents;
                }
            }
        }

        // What the case stated must be what the engine took (doc 12 section 6).
        $expectedPagume = $org['pagume'] ?? 'full_month';
        $check = static function (string $what, mixed $mine, mixed $stated) use (&$echo, $key): void {
            if ($mine !== $stated) {
                $echo[] = "employee {$key}: {$what} is ".json_encode($mine).', the case states '.json_encode($stated);
            }
        };
        $check('pagumen_proration.pagume_days_in_period', $pagume['pagume_days_in_period'], $period['pagume_days']);
        $check('pagumen_proration.strategy', $pagume['strategy'], $expectedPagume);
        $check('unpaid_leave_days x 1000', (int) round($unpaid['unpaid_leave_days'] * 1000), (int) ($inputs['unpaid_leave_thousandths'] ?? 0));
        $check('sick_leave.half_pay_days', (int) $sick['half_pay_days'], (int) ($inputs['sick_days']['half'] ?? 0));
        $check('sick_leave.unpaid_days', (int) $sick['unpaid_days'], (int) ($inputs['sick_days']['unpaid'] ?? 0));
        foreach (PayrollFields::OVERTIME_KINDS as $kind) {
            $check("overtime.by_type.{$kind}.minutes", $overtime['by_type'][$kind]['minutes'], (int) ($inputs['overtime_minutes'][$kind] ?? 0));
        }
        if ($lateness !== null) {
            $check('lateness.deducted_minutes', $lateness['deducted_minutes'], (int) ($inputs['lateness_minutes'] ?? 0));
        }

        return [$f, $problems, $echo];
    }

    /**
     * The live history rows must be the ones the case lists, or none.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private static function historyEcho(string $key, Employee $employee, array $row): array
    {
        $mine = EmployeeSalaryHistory::query()
            ->where('employee_id', $employee->id)
            ->whereNull('superseded_by')
            ->orderBy('id')
            ->get()
            ->map(static fn (EmployeeSalaryHistory $h): array => ['effective' => $h->effective_date->toDateString(), 'cents' => $h->salary_cents])
            ->all();
        $stated = array_map(static fn (array $h): array => ['effective' => $h['effective'], 'cents' => $h['cents']], $row['salary_history'] ?? []);

        return $mine === $stated ? [] : ["employee {$key}: the live salary history is ".json_encode($mine).', the case states '.json_encode($stated)];
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $built
     * @return array<string, int>
     */
    private static function runFields(array $case, array $built, PayrollRun $run): array
    {
        foreach ($case['after_approval']['delete'] ?? [] as $key) {
            $built['employees'][$key]->delete();
        }
        app(CurrentTenant::class)->set($built['tenant']);
        $run = $run->fresh();

        $f = [];
        $f['run.entries'] = PayrollEntry::query()->where('payroll_run_id', $run->id)->count();
        $f['run.skipped'] = (int) $run->skipped_no_salary;

        $totals = app(RunRegisterService::class)->register($run)['totals'];
        $f['register.gross_total'] = $totals['gross_cents'];
        $f['register.income_tax_total'] = $totals['income_tax_cents'];
        $f['register.employee_pension_total'] = $totals['employee_pension_cents'];
        $f['register.employer_pension_total'] = $totals['employer_pension_cents'];
        $f['register.net_total'] = $totals['net_cents'];

        $journal = app(AccountingExportService::class)->journalEntries($run);
        $line = static fn (string $code, string $side): int => (int) collect($journal['entries'])->where('account_code', $code)->sum($side.'_cents');
        foreach (['5100.debit', '5200.debit', '2100.credit', '2200.credit', '2201.credit', '2300.credit', '1400.credit', '2400.credit'] as $name) {
            [$code, $side] = explode('.', $name);
            $f['journal.'.$name] = $line($code, $side);
        }
        $f['journal.balanced'] = $journal['is_balanced'] ? 1 : 0;

        $schedules = app(StatutorySchedules::class);
        $f['schedule.income_tax_total'] = (int) ($schedules->build($run, StatutorySchedules::INCOME_TAX)['totals']['income_tax_cents'] ?? 0);
        $pension = $schedules->build($run, StatutorySchedules::PENSION);
        $f['schedule.pension_rows'] = count($pension['rows']);
        $f['schedule.pension_employee_total'] = (int) ($pension['totals']['employee_pension_cents'] ?? 0);

        $csv = app(BankExportService::class)->generateCsv($run);
        $rows = array_values(array_filter(explode("\r\n", $csv), static fn (string $l): bool => $l !== ''));
        array_shift($rows);
        $f['bank.rows'] = count($rows);
        $f['bank.total'] = array_sum(array_map(static fn (string $l): int => self::cents((string) (str_getcsv($l)[4] ?? '0')), $rows));

        $page = test()->getJson("/api/v1/payroll/runs/{$run->public_id}/export/bank");
        $page->assertOk();
        $f['bank_page.rows'] = (int) $page->json('total_entries');
        $f['bank_page.total'] = (int) $page->json('total_amount_cents');
        app(CurrentTenant::class)->set($built['tenant']);

        return $f;
    }

    /** "7490.00" as 749000, by text: the bank file is money spelt with a decimal point. */
    private static function cents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $digits = (int) str_replace(['-', '.'], '', $amount);

        return $negative ? -$digits : $digits;
    }

    /**
     * @return array<string, mixed>
     */
    private static function storedAttributes(PayrollEntry $entry): array
    {
        return [
            'basic_salary_cents' => $entry->basic_salary_cents,
            'allowances' => $entry->allowances,
            'deductions' => $entry->deductions,
            'gross_cents' => $entry->gross_cents,
            'income_tax_cents' => $entry->income_tax_cents,
            'employee_pension_cents' => $entry->employee_pension_cents,
            'employer_pension_cents' => $entry->employer_pension_cents,
            'other_deductions_cents' => $entry->other_deductions_cents,
            'net_cents' => $entry->net_cents,
            'calculation_log' => $entry->calculation_log,
        ];
    }

    /**
     * An entry as the database would hold it: through JSON, without the fields
     * a preview and a run legitimately differ in.
     *
     * @param  array<string, mixed>|null  $attributes
     * @return array<string, mixed>|null
     */
    private static function normalise(?array $attributes): ?array
    {
        if ($attributes === null) {
            return null;
        }
        $keep = ['basic_salary_cents', 'allowances', 'deductions', 'gross_cents', 'income_tax_cents', 'employee_pension_cents', 'employer_pension_cents', 'other_deductions_cents', 'net_cents', 'calculation_log'];
        $attributes = array_intersect_key($attributes, array_flip($keep));
        $attributes = json_decode((string) json_encode($attributes), true);
        unset($attributes['calculation_log']['calculated_at']);

        return $attributes;
    }

    /**
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     */
    private static function firstDifference(?array $a, ?array $b): string
    {
        if ($a === null || $b === null) {
            return $a === null ? 'no preview line' : 'no stored entry';
        }
        foreach (self::flatten($a) as $path => $value) {
            $other = self::flatten($b)[$path] ?? null;
            if ($value !== $other) {
                return "{$path}: preview ".json_encode($value).', stored '.json_encode($other);
            }
        }

        return 'a field only the stored entry has';
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private static function flatten(array $value, string $prefix = ''): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            $path = $prefix === '' ? (string) $k : "{$prefix}.{$k}";
            if (is_array($v)) {
                $out = [...$out, ...self::flatten($v, $path)];
            } else {
                $out[$path] = $v;
            }
        }

        return $out;
    }
}
