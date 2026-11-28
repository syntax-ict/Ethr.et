<?php

declare(strict_types=1);

namespace Tests\Accuracy\Restated;

use Illuminate\Support\Facades\Lang;

/**
 * What a payslip PDF prints, as rows of label and cents, read out of the PDF
 * itself (PdfText), and the comparison of those rows with the stored entry.
 *
 * Nothing here computes a payslip figure. The expected side is the entry's own
 * columns and the engine's calculation log; the labels come from the same
 * `payslip.*` language strings the view prints with. The only arithmetic is the
 * last two checks, which add up what the page itself printed.
 *
 * Static only: Pest loads every test file into one process.
 */
final class PayslipPrint
{
    /**
     * The money rows of the page, top to bottom: a line whose last run is an amount.
     *
     * @return list<array{label: string, cents: int}>
     */
    public static function rows(string $pdf): array
    {
        $currency = (string) __('payslip.currency', [], 'en');
        $rows = [];
        foreach (PdfText::lines(PdfText::runs($pdf)) as $line) {
            if (count($line) < 2) {
                continue;
            }
            $cents = PdfText::cents($line[array_key_last($line)]['text'], $currency);
            if ($cents === null) {
                continue;
            }
            $rows[] = [
                'label' => trim(implode(' ', array_map(static fn (array $run): string => $run['text'], array_slice($line, 0, -1)))),
                'cents' => $cents,
            ];
        }

        return $rows;
    }

    /**
     * The printed rows against the entry. Every difference is a line of text naming
     * the field, what the entry holds and what the page shows.
     *
     * @param  list<array{label: string, cents: int}>  $rows
     * @param  array<string, mixed>  $entry  see RestatedEntries::read()
     * @param  list<string>  $allowanceNames  the allowance names the organisation set up, from the case
     * @return array{compared: int, misses: list<string>}
     */
    public static function compare(array $rows, array $entry, array $allowanceNames, string $who): array
    {
        $compared = 0;
        $misses = [];
        $miss = static function (string $field, mixed $engine, mixed $page) use (&$misses, $who): void {
            $misses[] = "{$who} payslip, {$field}: the entry has ".json_encode($engine).' but the page shows '.json_encode($page);
        };
        $label = static fn (string $key): string => (string) __('payslip.'.$key, [], 'en');

        $position = ['basic' => null, 'gross' => null, 'tax' => null, 'pension' => null, 'net' => null, 'employer' => null];
        $overtime = [];
        foreach ($rows as $i => $row) {
            $text = $row['label'];
            if ($text === $label('basic_salary') && $position['basic'] === null) {
                $position['basic'] = $i;
            } elseif ($text === $label('overtime') && $position['gross'] === null) {
                $overtime[] = $i;
            } elseif ($text === $label('gross') && $position['gross'] === null) {
                $position['gross'] = $i;
            } elseif ($text === $label('income_tax') && $position['tax'] === null) {
                $position['tax'] = $i;
            } elseif (str_starts_with($text, $label('employee_pension')) && $position['pension'] === null) {
                $position['pension'] = $i;
            } elseif ($text === $label('net') && $position['net'] === null) {
                $position['net'] = $i;
            } elseif (str_starts_with($text, $label('employer_pension')) && $position['employer'] === null) {
                $position['employer'] = $i;
            }
        }
        foreach ($position as $name => $at) {
            if ($at === null) {
                $misses[] = "{$who} payslip: no row for '{$name}' was printed (rows read: ".json_encode(array_column($rows, 'label')).')';
            }
        }
        if ($misses !== []) {
            return ['compared' => 0, 'misses' => $misses];
        }

        $equal = static function (string $field, int $engine, int $page) use (&$compared, $miss): void {
            $compared++;
            if ($engine !== $page) {
                $miss($field, $engine, $page);
            }
        };

        // Basic: the entry column, and the figure the log's leave and lateness steps left.
        $equal('basic (the entry column)', $entry['basic_salary_cents'], $rows[$position['basic']]['cents']);
        $equal('basic (the log, after unpaid leave, sick leave and lateness)', $entry['basic_after_reductions'], $rows[$position['basic']]['cents']);

        // Overtime: a line when overtime was paid, and no line when it was not.
        $paid = $entry['overtime_cents'];
        $lines = count($overtime);
        $compared++;
        if (($paid > 0) !== ($lines === 1) || $lines > 1) {
            $miss('overtime line', $paid > 0 ? 'overtime paid, one line expected' : 'no overtime, no line expected', "{$lines} lines");
        } elseif ($lines === 1) {
            $equal('overtime', $paid, $rows[$overtime[0]]['cents']);
        }

        // Allowances: each by name, and nothing else between the earnings and the gross.
        $between = array_slice($rows, $position['basic'] + 1, $position['gross'] - $position['basic'] - 1);
        $printedAllowances = array_values(array_filter($between, static fn (array $r): bool => $r['label'] !== $label('overtime')));
        $compared++;
        if (count($printedAllowances) !== count($entry['allowances'])) {
            $miss('allowance lines', array_column($entry['allowances'], 'name'), array_column($printedAllowances, 'label'));
        } else {
            foreach ($entry['allowances'] as $i => $allowance) {
                $equal("allowance '{$allowance['name']}'", (int) $allowance['amount_cents'], $printedAllowances[$i]['cents']);
                $compared++;
                if ($printedAllowances[$i]['label'] !== $allowance['name']) {
                    $miss("allowance name #{$i}", $allowance['name'], $printedAllowances[$i]['label']);
                }
            }
        }
        foreach ($allowanceNames as $name) {
            $has = array_filter($entry['allowances'], static fn (array $a): bool => $a['name'] === $name) !== [];
            if ($has) {
                $compared++;
                if (array_filter($printedAllowances, static fn (array $r): bool => $r['label'] === $name) === []) {
                    $miss("allowance '{$name}' by name", 'a line named '.$name, array_column($printedAllowances, 'label'));
                }
            }
        }

        $equal('gross', $entry['gross_cents'], $rows[$position['gross']]['cents']);
        $equal('income tax', $entry['income_tax_cents'], $rows[$position['tax']]['cents']);
        $equal('employee pension', $entry['employee_pension_cents'], $rows[$position['pension']]['cents']);

        // Deductions: every itemised line in the entry's order, else the one unitemised row.
        $deductionRows = array_slice($rows, $position['pension'] + 1, $position['net'] - $position['pension'] - 1);
        $itemised = $entry['deductions'];
        $expectedRows = $itemised !== [] ? count($itemised) : ($entry['other_deductions_cents'] > 0 ? 1 : 0);
        $compared++;
        if (count($deductionRows) !== $expectedRows) {
            $miss('deduction lines', $expectedRows, array_column($deductionRows, 'label'));
        } elseif ($itemised !== []) {
            foreach ($itemised as $i => $deduction) {
                $equal("deduction '{$deduction['type']}'", (int) $deduction['amount_cents'], $deductionRows[$i]['cents']);
                $expectedLabel = Lang::has('payslip.deduction_types.'.$deduction['type'], 'en')
                    ? (string) __('payslip.deduction_types.'.$deduction['type'], [], 'en')
                    : ucfirst(str_replace('_', ' ', $deduction['type']));
                $compared++;
                if ($deductionRows[$i]['label'] !== $expectedLabel) {
                    $miss("deduction label #{$i}", $expectedLabel, $deductionRows[$i]['label']);
                }
            }
        } elseif ($expectedRows === 1) {
            $equal('other deductions', $entry['other_deductions_cents'], $deductionRows[0]['cents']);
        }

        $equal('net', $entry['net_cents'], $rows[$position['net']]['cents']);
        $equal('employer pension', $entry['employer_pension_cents'], $rows[$position['employer']]['cents']);

        // The lines add up to the printed gross and the printed net.
        $earnings = $rows[$position['basic']]['cents']
            + ($lines === 1 ? $rows[$overtime[0]]['cents'] : 0)
            + array_sum(array_column($printedAllowances, 'cents'));
        $equal('printed earnings lines added up, against the printed gross', $rows[$position['gross']]['cents'], $earnings);
        $withheld = $rows[$position['tax']]['cents'] + $rows[$position['pension']]['cents'] + array_sum(array_column($deductionRows, 'cents'));
        $equal('printed gross less the printed deductions, against the printed net', $rows[$position['net']]['cents'], $rows[$position['gross']]['cents'] - $withheld);

        return ['compared' => $compared, 'misses' => $misses];
    }
}
