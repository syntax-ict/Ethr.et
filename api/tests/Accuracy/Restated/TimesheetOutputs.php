<?php

declare(strict_types=1);

namespace Tests\Accuracy\Restated;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attendance\TimesheetLedger;
use App\Services\Report\TimesheetPdf;
use App\Services\Report\TimesheetReport;
use App\Services\Report\TimesheetXlsx;
use App\Support\ReaderDates;
use Carbon\Carbon;
use RuntimeException;

/**
 * Reads each timesheet output into one shape, so TimesheetRestatedTest can hold
 * every output against the ledger the same way.
 *
 *   grid  : TimesheetReport::grid(), the screen's data (arrays, read as returned)
 *   xlsx  : the workbook TimesheetXlsx writes, read cell by cell (XlsxRead)
 *   pdf   : the PDF TimesheetPdf renders, read as text with positions (PdfText)
 *
 * Each result is ['people' => list<['name','figures' => key => text,'codes' => list<text>,'check' => text]>,
 * 'total' => key => text, 'departments' => ...]. Nothing here computes a figure.
 *
 * Static only: Pest loads every test file into one process.
 */
final class TimesheetOutputs
{
    /**
     * @param  list<array{rate: float, label: string, types: list<string>}>  $rateColumns
     * @return array{people: list<array<string, mixed>>, total: array<string, string>}
     */
    public static function grid(Tenant $tenant, User $viewer, array $filters, array $rateColumns): array
    {
        $grid = app(TimesheetReport::class)->grid($tenant, $viewer, $filters, 1, TimesheetPdf::MAX_PEOPLE, true);

        $figures = static function (array $totals) use ($rateColumns): array {
            $out = [];
            foreach (array_keys(TimesheetFigures::COLUMNS) as $key) {
                if (array_key_exists($key, $totals)) {
                    $out[$key] = self::printed($totals[$key]);
                }
            }
            foreach ($rateColumns as $i => $column) {
                $out['rate:'.$column['rate']] = self::printed($totals['overtime_by_rate'][$i] ?? 0);
            }

            return $out;
        };

        $people = [];
        foreach ($grid['data'] as $row) {
            $people[] = [
                'name' => $row['employee']->name,
                'figures' => $figures($row['totals']),
                'codes' => array_values(array_map(static fn (array $cell): string => $cell['code']?->short() ?? '·', $row['cells'])),
                'cells' => $row['cells'],
                'check' => $row['check_ok'] ? '✓' : '!',
            ];
        }

        return ['people' => $people, 'total' => $figures($grid['totals'] ?? [])];
    }

    /**
     * @param  list<array{rate: float, label: string, types: list<string>}>  $rateColumns
     * @return array{people: list<array<string, mixed>>, total: array<string, string>}
     */
    public static function xlsx(Tenant $tenant, User $viewer, array $filters, ReaderDates $reader, array $rateColumns): array
    {
        $sheets = XlsxRead::sheets(app(TimesheetXlsx::class)->render($tenant, $viewer, $filters, $reader, true));
        $daysName = (string) __('report.timesheet.section_days', [], 'en');
        $summaryName = (string) __('report.timesheet.section_summary', [], 'en');
        if (! isset($sheets[$daysName], $sheets[$summaryName])) {
            throw new RuntimeException('the workbook has the sheets '.json_encode(array_keys($sheets)).", not '{$daysName}' and '{$summaryName}'");
        }

        $no = (string) __('report.timesheet.no', [], 'en');
        $headerOf = static function (array $sheet) use ($no): int {
            foreach ($sheet as $n => $cells) {
                if (($cells['A'][1] ?? null) === $no) {
                    return $n;
                }
            }
            throw new RuntimeException("no header row starting '{$no}'");
        };

        // The days sheet: the cells of each person, from the column of the first day to the check mark.
        $days = $sheets[$daysName];
        $h = $headerOf($days);
        // The day columns run from the first number in the header to the check mark; an empty cell is not written.
        $dayLetters = [];
        foreach ($days[$h] as $letter => $cell) {
            if (ctype_digit($cell[1]) || $cell[1] === '✓') {
                $dayLetters[] = $letter;
            }
        }
        $people = [];
        for ($n = $h + 1; isset($days[$n]); $n++) {
            $cells = array_map(static fn (string $letter): string => (string) ($days[$n][$letter][1] ?? ''), $dayLetters);
            $check = array_pop($cells);
            $people[] = ['name' => $days[$n]['C'][1] ?? '', 'codes' => $cells, 'check' => $check, 'figures' => []];
        }

        // The summary sheet: a figure sits under the heading it is named by.
        $summary = $sheets[$summaryName];
        $h = $headerOf($summary);
        $column = [];
        foreach ($summary[$h] as $letter => $cell) {
            $column[$cell[1]] = $letter;
        }
        $grand = (string) __('report.timesheet.grand_total', [], 'en');
        $total = [];
        $row = 0;
        for ($n = $h + 1; isset($summary[$n]); $n++) {
            $figures = [];
            foreach (TimesheetFigures::layout($rateColumns) as $c) {
                $letter = $column[$c['label']] ?? null;
                if ($letter === null) {
                    throw new RuntimeException("the summary sheet has no column headed '{$c['label']}'; it has ".json_encode(array_keys($column)));
                }
                $figures[$c['key']] = (string) ($summary[$n][$letter][1] ?? '');
            }
            $label = (string) ($summary[$n]['B'][1] ?? '');
            if (str_starts_with($label, $grand)) {
                $total = $figures;
            } elseif (isset($people[$row]) && $label === $people[$row]['name']) {
                $people[$row]['figures'] = $figures;
                $row++;
            }
        }

        return ['people' => $people, 'total' => $total];
    }

    /**
     * @param  list<array{rate: float, label: string, types: list<string>}>  $rateColumns
     * @return array{people: list<array<string, mixed>>, total: array<string, string>}
     */
    public static function pdf(Tenant $tenant, User $viewer, array $filters, ReaderDates $reader, array $rateColumns, int $dayCount): array
    {
        $bytes = app(TimesheetPdf::class)->render($tenant, $viewer, $filters, $reader, true);
        $lines = PdfText::lines(PdfText::runs($bytes));

        $summaryHeading = (string) __('report.timesheet.section_summary', [], 'en');
        $totalsHeading = (string) __('report.timesheet.totals', [], 'en');
        $keys = array_column(TimesheetFigures::layout($rateColumns), 'key');

        $section = 'before';
        $people = [];
        $figureLines = [];
        foreach ($lines as $line) {
            $texts = array_map(static fn (array $r): string => $r['text'], $line);
            if (count($line) === 1 && $texts[0] === $summaryHeading) {
                $section = 'summary';

                continue;
            }
            if (count($line) === 1 && $texts[0] === $totalsHeading) {
                $section = 'after';

                continue;
            }

            // A person's days: one run per day and the check mark, nothing else on the line.
            $last = $texts[array_key_last($texts)];
            if ($section === 'before' && count($line) === $dayCount + 1 && in_array($last, ['✓', '!'], true)) {
                $people[] = ['name' => '', 'codes' => array_slice($texts, 0, $dayCount), 'check' => $last, 'figures' => []];
            }

            // A line of figures: the last runs are as many numbers as there are columns.
            if ($section === 'summary' && count($line) >= count($keys)) {
                $tail = array_slice($texts, -count($keys));
                if (count(array_filter($tail, static fn (string $t): bool => preg_match('/^\d+(\.\d+)?$/', $t) === 1)) === count($keys)) {
                    $figureLines[] = array_combine($keys, $tail);
                }
            }
        }

        $total = array_pop($figureLines) ?? [];
        foreach ($figureLines as $i => $figures) {
            if (isset($people[$i])) {
                $people[$i]['figures'] = $figures;
            }
        }
        if (count($figureLines) !== count($people)) {
            throw new RuntimeException('the PDF has '.count($people).' rows of days and '.count($figureLines).' rows of figures');
        }

        return ['people' => $people, 'total' => $total];
    }

    /**
     * The ledger over the same people and span, built directly.
     *
     * @param  list<Employee>  $employees
     * @return array{days: list<string>, employees: array<int, array<string, mixed>>}
     */
    public static function ledger(Tenant $tenant, array $employees, string $from, string $to): array
    {
        $rules = app(TimesheetReport::class)->rules($tenant, Carbon::parse($from));

        return app(TimesheetLedger::class)->build(collect($employees), Carbon::parse($from), Carbon::parse($to), $rules, true);
    }

    /** A grid figure as the page prints it: one decimal for hours, a trailing ".0" dropped. */
    private static function printed(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
    }
}
