<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads a list of people that arrives as cells: rows pasted from a
 * spreadsheet, a CSV the browser split, a sheet of a workbook.
 *
 * The browser decides only where one cell ends and the next begins. What a
 * column means, how a date is read and whether a line can be staged are
 * decided here, from EmployeeImportRow, so every way a list comes in agrees.
 * The guided setup's paste box made those decisions itself, in the browser,
 * and differently (2026-11-11).
 *
 * A first line of column names is recognised and says which column is which,
 * in any order. Without one the columns are read in
 * EmployeeImportRow::DEFAULT_ORDER. A line that cannot be staged is reported
 * with its number as the reader counts it and left out; nothing is guessed.
 */
final class EmployeeImportGrid
{
    /** The most people one list brings (StageRowsRequest). */
    public const MAX_PEOPLE = 2000;

    /** Columns two lines of one list cannot share. */
    private const UNIQUE_IN_LIST = ['employee_code', 'email', 'badge_number', 'external_id'];

    private const RELATIONS = ['department_code', 'branch_code', 'position_code'];

    /** @var array<string, RelationMatch> */
    private array $relations = [];

    public function __construct(private readonly int $tenantId) {}

    /**
     * @param  array<int, mixed>  $grid  lines of cells; an empty line keeps its place so the numbers stay the reader's
     * @param  'gregorian'|'ethiopian'  $calendar  the calendar dates are written in, unless a column name says
     */
    public function read(array $grid, string $calendar = 'gregorian'): PeopleRead
    {
        $lines = [];
        foreach (array_values($grid) as $index => $cells) {
            $cells = array_map(
                fn (mixed $cell): string => is_scalar($cell) ? trim((string) $cell) : '',
                is_array($cells) ? array_values($cells) : [],
            );
            if (implode('', $cells) !== '') {
                $lines[$index + 1] = $cells;
            }
        }

        if ($lines === []) {
            return new PeopleRead([], [], false, [], [], $calendar, false, 0);
        }

        $first = array_key_first($lines);
        $header = $this->headerOf($lines[$first]);
        $calendars = [];
        $ignored = [];

        if ($header === null) {
            $keys = EmployeeImportRow::DEFAULT_ORDER;
        } else {
            $keys = [];
            foreach ($header as $position => [$key, $named]) {
                $known = in_array($key, EmployeeImportRow::COLUMNS, true);
                $keys[$position] = $known ? $key : null;
                if ($known && $named !== null) {
                    $calendars[$key] = $named;
                }
                if (! $known && $lines[$first][$position] !== '') {
                    $ignored[] = $lines[$first][$position];
                }
            }
            unset($lines[$first]);
        }

        $rows = [];
        $problems = [];
        $firstSeen = [];
        $rules = EmployeeImportRow::stagedRules();
        $last = $first;

        foreach ($lines as $line => $cells) {
            $last = $line;
            $row = [];
            foreach ($keys as $position => $key) {
                $value = $cells[$position] ?? '';
                // A second column with the same meaning ("Department" beside
                // "department_code") fills a gap and never overwrites.
                if ($key !== null && $value !== '' && ! isset($row[$key])) {
                    $row[$key] = $value;
                }
            }

            $row = EmployeeImportRow::normalize($row, $calendar, $calendars);

            $messages = [
                ...EmployeeImportRow::messages($row, $rules),
                ...$this->relationMessages($row),
                ...$this->duplicateMessages($row, $firstSeen, $line),
            ];
            foreach ($messages as $message) {
                $problems[] = ['line' => $line, 'message' => $message];
            }
            if ($messages === []) {
                $rows[] = EmployeeImportRow::staged($row);
            }
        }

        if (count($rows) > self::MAX_PEOPLE) {
            $problems[] = ['line' => $last, 'message' => __('onboarding.migration.too_many', [
                'count' => count($rows),
                'max' => self::MAX_PEOPLE,
            ])];
        }

        $hireDateCalendar = $calendars['hire_date'] ?? null;

        return new PeopleRead(
            rows: $rows,
            problems: $problems,
            header: $header !== null,
            columns: array_values(array_unique(array_filter($keys, fn (?string $key): bool => $key !== null))),
            ignored: $ignored,
            calendar: $hireDateCalendar ?? $calendar,
            calendarFromHeader: $hireDateCalendar !== null,
            withoutHireDate: count(array_filter($rows, fn (array $row): bool => ! isset($row['hire_date']))),
        );
    }

    /**
     * A first line is column names when one of its cells names the name
     * column and none of them looks like a person's details. "Abebe Kebede,
     * abebe@acme.et" is a person; "Name, Email" is not.
     *
     * @param  list<string>  $cells
     * @return list<array{0: string, 1: 'gregorian'|'ethiopian'|null}>|null
     */
    private function headerOf(array $cells): ?array
    {
        $columns = array_map(EmployeeImportRow::column(...), $cells);

        if (! in_array('name', array_column($columns, 0), true)) {
            return null;
        }

        foreach ($cells as $cell) {
            if (filter_var($cell, FILTER_VALIDATE_EMAIL) !== false
                || preg_match('#^\d{4}[-/.]\d{1,2}[-/.]\d{1,2}\b|^\d{1,2}[/.]\d{1,2}[/.]\d{4}\b#', $cell) === 1) {
                return null;
            }
        }

        return $columns;
    }

    /**
     * A department, branch or position the organisation does not have. It
     * used to become a silent null: the person landed unassigned and nobody
     * knew.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function relationMessages(array $row): array
    {
        $messages = [];
        foreach (self::RELATIONS as $column) {
            $value = $row[$column] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }

            $match = $this->relation($column);
            if ($match->id($value) !== null) {
                continue;
            }

            $messages[] = __(
                $match->ambiguous($value) ? 'onboarding.migration.relation_ambiguous' : 'onboarding.migration.relation_not_found',
                ['field' => __('employee.import_field_'.$column), 'value' => $value],
            );
        }

        return $messages;
    }

    /** Read on first use: most lists name a department and nothing else. */
    private function relation(string $column): RelationMatch
    {
        return $this->relations[$column] ??= match ($column) {
            'branch_code' => RelationMatch::branches($this->tenantId),
            'position_code' => RelationMatch::positions($this->tenantId),
            default => RelationMatch::departments($this->tenantId),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, int>>  $firstSeen
     * @return list<string>
     */
    private function duplicateMessages(array $row, array &$firstSeen, int $line): array
    {
        $messages = [];
        foreach (self::UNIQUE_IN_LIST as $column) {
            $value = $row[$column] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }

            $key = mb_strtolower($value);
            if (isset($firstSeen[$column][$key])) {
                $messages[] = __('onboarding.migration.duplicate_in_list', [
                    'field' => __('employee.import_field_'.$column),
                    'value' => $value,
                    'first' => $firstSeen[$column][$key],
                ]);

                continue;
            }

            $firstSeen[$column][$key] = $line;
        }

        return $messages;
    }
}
