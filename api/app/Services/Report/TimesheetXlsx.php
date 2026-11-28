<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Tenant;
use App\Models\User;
use App\Support\ReaderDates;
use App\Support\Xlsx;

/**
 * The monthly timesheet as an Excel workbook: a sheet of the days, one row a
 * person and one column a day, and a sheet of the summary figures payroll
 * reads, in the same order of people.
 *
 * It writes what `TimesheetPdf::data()` hands the printed sheet, which is
 * what `TimesheetReport::grid()` returns and the screen shows, so the file
 * cannot say what the page does not. The ledger is not computed a second
 * time. The limit is the screen's own, since a workbook has no page to
 * overflow; a list longer than that is a 422 that says how to narrow it.
 *
 * @phpstan-import-type SheetSpec from Xlsx
 */
final class TimesheetXlsx
{
    public function __construct(
        private readonly TimesheetPdf $sheet,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function render(Tenant $tenant, User $viewer, array $filters, ReaderDates $reader, bool $withPay): string
    {
        return Xlsx::render($this->sheets($tenant, $viewer, $filters, $reader, $withPay));
    }

    /**
     * The sheets as the writer takes them. Public so a test can read the
     * figures without unpicking a workbook.
     *
     * @param  array<string, mixed>  $filters
     * @return list<SheetSpec>
     */
    public function sheets(Tenant $tenant, User $viewer, array $filters, ReaderDates $reader, bool $withPay): array
    {
        $data = $this->sheet->data($tenant, $viewer, $filters, $reader, $withPay, TimesheetReport::MAX_PEOPLE, 'too_many_for_excel');

        $locale = $reader->locale;
        $labels = $data['labels'];
        $named = static fn (array $row): string => $locale === 'am' && $row['name_am'] ? $row['name_am'] : (string) $row['name'];

        $range = $data['range'] ?? null;
        $both = is_array($range)
            ? collect(['gregorian', 'ethiopian'])->map(fn (string $calendar): string => (string) __('report.period_range', [
                'from' => $reader->withCalendar($calendar)->date($range['from']),
                'to' => $reader->withCalendar($calendar)->date($range['to']),
            ], $locale))->implode(' | ')
            : $data['period'];

        $lead = [
            (string) $data['letterhead']['name'],
            $data['titles']['en'].' / '.$data['titles']['am'],
            $both.'  ·  '.$data['generated'],
            collect([$data['status'] === null ? null : $labels['status'].': '.$data['status'], ...$data['filters']])->filter()->implode('  ·  '),
        ];
        $lead = array_values(array_filter($lead, static fn (string $line): bool => $line !== ''));

        $days = [
            'name' => $labels['days'],
            'lead' => $lead,
            'header' => [
                $labels['no'], $labels['id'], $labels['name'], $labels['department'], $labels['position'], $labels['shift'], $labels['device'],
                ...array_map(static fn (array $day): string => (string) $day['number'], $data['days']),
                '✓',
            ],
            'freeze_columns' => 3,
            'rows' => (function () use ($data, $named): iterable {
                foreach ($data['rows'] as $row) {
                    yield [
                        $row['no'], $row['code'], $named($row), $row['department'], $row['position'], $row['shift'], $row['device'],
                        ...array_map(static fn (array $cell): string => (string) $cell['short'], $row['cells']),
                        $row['check_ok'] ? '✓' : '!',
                    ];
                }
            })(),
        ];

        $summaryRows = (function () use ($data, $named): iterable {
            foreach ($data['rows'] as $row) {
                yield [$row['no'], $named($row), ...self::numbers($row['figures'])];
            }
            if (count($data['departments']) > 1) {
                foreach ($data['departments'] as $group) {
                    yield [null, $group['name'].' ('.$group['employees'].')', ...self::numbers($group['figures'])];
                }
            }
            yield [null, $data['labels']['grand_total'].' ('.$data['people'].')', ...self::numbers($data['totals'])];
        })();

        $summary = [
            'name' => $labels['summary'],
            'lead' => $lead,
            'header' => [$labels['no'], $labels['name'], ...array_map(static fn (array $column): string => $column['label'], $data['columns'])],
            'freeze_columns' => 2,
            'bold_last' => true,
            'rows' => $summaryRows,
        ];

        return [$days, $summary];
    }

    /**
     * The figures the page prints as text, as numbers a column can add up.
     *
     * @param  list<string>  $figures
     * @return list<int|float|string>
     */
    private static function numbers(array $figures): array
    {
        return array_map(static function (string $figure): int|float|string {
            if (! is_numeric($figure)) {
                return $figure;
            }

            return str_contains($figure, '.') ? (float) $figure : (int) $figure;
        }, $figures);
    }
}
