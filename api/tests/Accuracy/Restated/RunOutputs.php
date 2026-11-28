<?php

declare(strict_types=1);

namespace Tests\Accuracy\Restated;

use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Services\Payroll\BankExportService;
use App\Services\Payroll\RunRegisterService;
use App\Services\Payroll\StatutorySchedules;
use App\Support\Csv;
use RuntimeException;

/**
 * The bank file and the statutory schedules of an approved run, read the way a
 * bank or an agency would read them: the CSV text, row by row. Also the stored
 * entries they restate. Reads only; no figure is computed here beyond adding
 * up a column of stored figures, which is what a total is.
 *
 * Static only: Pest loads every test file into one process.
 */
final class RunOutputs
{
    /**
     * Gives each person in the run bank accounts of a different shape, so the choice of
     * account is visible: a primary account behind a decoy, a primary account alone,
     * only non-primary accounts (the first on record is paid), and none.
     *
     * @param  array<string, Employee>  $employees  case key => employee
     * @return array<string, array{bank: string, account: string}> employee code => the account the bank file must show; empty strings for none
     */
    public static function addBankDetails(array $employees, int $tenantId): array
    {
        $shapes = [
            'primary alone' => [['P', true]],
            'decoy then primary' => [['D', false], ['P', true]],
            'two non-primary' => [['A', false], ['B', false]],
            'none' => [],
            'primary then spare' => [['P', true], ['S', false]],
            'spare then primary' => [['S', false], ['P', true]],
        ];

        $expected = [];
        $i = 0;
        foreach ($employees as $key => $employee) {
            $shape = array_values($shapes)[$i % count($shapes)];
            $shapeName = array_keys($shapes)[$i % count($shapes)];
            $i++;
            $made = [];
            foreach ($shape as $n => [$tag, $primary]) {
                $bank = "Bank {$key} {$tag} ({$shapeName})";
                $account = '10'.str_pad((string) ($i * 100 + $n), 8, '0', STR_PAD_LEFT).$tag;
                EmployeeBankDetail::query()->create([
                    'tenant_id' => $tenantId,
                    'employee_id' => $employee->id,
                    'bank_name' => $bank,
                    'account_number' => $account,
                    'is_primary' => $primary,
                ]);
                $made[] = ['bank' => $bank, 'account' => $account, 'primary' => $primary];
            }
            // The designated account if there is one, else the first on record, else none.
            $primaries = array_values(array_filter($made, static fn (array $m): bool => $m['primary']));
            $pick = $primaries[0] ?? $made[0] ?? ['bank' => '', 'account' => ''];
            $chosen = ['bank' => $pick['bank'], 'account' => $pick['account']];
            $expected[(string) $employee->employee_code] = $chosen;
        }

        return $expected;
    }

    /**
     * The bank CSV's data rows.
     *
     * @return list<array{code: string, name: string, bank: string, account: string, cents: int, currency: string}>
     */
    public static function bankRows(PayrollRun $run): array
    {
        $csv = app(BankExportService::class)->generateCsv($run);
        $lines = array_values(array_filter(explode("\r\n", $csv), static fn (string $l): bool => $l !== ''));
        array_shift($lines);

        return array_map(static function (string $line): array {
            $cells = str_getcsv($line, ',', '"', '');

            return [
                'code' => (string) $cells[0], 'name' => (string) $cells[1], 'bank' => (string) $cells[2],
                'account' => (string) $cells[3], 'cents' => self::cents((string) $cells[4]), 'currency' => (string) $cells[5],
            ];
        }, $lines);
    }

    /**
     * The register's rows, in the register's order.
     *
     * @return list<array<string, mixed>>
     */
    public static function register(PayrollRun $run): array
    {
        return app(RunRegisterService::class)->register($run, checks: false)['rows'];
    }

    /**
     * The stored entries of the run in id order, each with the employee loaded and the
     * log's pension verdict.
     *
     * @return list<array{code: string, name: string, tax: int, employee_pension: int, employer_pension: int, net: int, exempt: bool|null}>
     */
    public static function entries(PayrollRun $run): array
    {
        $out = [];
        foreach (PayrollEntry::query()->where('payroll_run_id', $run->id)->with('employee')->orderBy('id')->get() as $entry) {
            $exempt = null;
            foreach ((array) ($entry->getAttribute('calculation_log')['steps'] ?? []) as $step) {
                if (($step['step'] ?? null) === 'pension') {
                    $exempt = isset($step['exempt']) ? (bool) $step['exempt'] : null;
                }
            }
            $out[] = [
                'code' => (string) $entry->employee->employee_code,
                'name' => (string) $entry->employee->name,
                'tax' => (int) $entry->income_tax_cents,
                'employee_pension' => (int) $entry->employee_pension_cents,
                'employer_pension' => (int) $entry->employer_pension_cents,
                'net' => (int) $entry->net_cents,
                'exempt' => $exempt,
            ];
        }

        return $out;
    }

    /**
     * A statutory schedule's CSV, read: the data rows keyed by the person, and the totals line.
     *
     * @return array{rows: array<string, array<string, int>>, total: array<string, int>} money columns by their key, in cents
     */
    public static function scheduleCsv(PayrollRun $run, string $schedule): array
    {
        $schedules = app(StatutorySchedules::class);
        $columns = $schedules->build($run, $schedule)['columns'];
        $lines = array_values(array_filter(explode("\r\n", $schedules->csv($run, $schedule)), static fn (string $l): bool => $l !== ''));

        $header = Csv::row(array_column($columns, 'label'));
        $at = array_search($header, $lines, true);
        if ($at === false) {
            throw new RuntimeException('the schedule CSV has no header line equal to '.$header);
        }

        $keys = array_column($columns, 'key');
        $by = in_array('employee_code', $keys, true) ? 'employee_code' : 'employee_name';
        $rows = [];
        $total = [];
        $data = array_slice($lines, $at + 1);
        $last = array_pop($data);
        $read = static function (string $line) use ($keys): array {
            $cells = str_getcsv($line, ',', '"', '');

            return array_combine($keys, array_map(static fn (int $i): string => (string) ($cells[$i] ?? ''), array_keys($keys)));
        };
        foreach ($data as $line) {
            $cells = $read($line);
            $rows[$cells[$by]] = self::money($columns, $cells);
        }
        $total = self::money($columns, $read((string) $last));

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param  list<array{key: string, label: string, money: bool}>  $columns
     * @param  array<string, string>  $cells
     * @return array<string, int>
     */
    private static function money(array $columns, array $cells): array
    {
        $out = [];
        foreach ($columns as $column) {
            if ($column['money']) {
                $out[$column['key']] = self::cents($cells[$column['key']]);
            }
        }

        return $out;
    }

    /** "7490.00" as 749000, by text: money in a CSV is spelt with a decimal point. */
    public static function cents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $digits = (int) str_replace(['-', '.'], '', $amount);

        return $negative ? -$digits : $digits;
    }
}
