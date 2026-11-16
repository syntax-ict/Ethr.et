<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Device;
use App\Services\Device\DeviceIdRules;

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
 *
 * Another system's export names its columns its own way. The reader says
 * which is which (`$mapping`, by column name), and then the first line is
 * column names because they said so, whatever it looks like. A name the
 * mapping does not mention is still read as ETHR reads it.
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
     * @param  array<string, string|null>|null  $mapping  what the reader said each column name is (ImportMapping::mapOf), null for a column left out; null when they said nothing
     * @param  string  $source  the saved source the list is read as: its ids from the other system are kept against it
     */
    public function read(array $grid, string $calendar = 'gregorian', ?array $mapping = null, string $source = EmployeeImportRow::DEFAULT_SOURCE): PeopleRead
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

        // A column ETHR wrote for the reader (the lines to fix) is dropped
        // whole before anything else reads the list: it is never a person's
        // field, never listed, and never needs a mapping.
        $dropped = array_keys(array_filter($lines[$first], EmployeeImportRow::isIgnoredHeader(...)));
        if ($dropped !== []) {
            foreach ($lines as $number => $cells) {
                $lines[$number] = array_values(array_diff_key($cells, array_flip($dropped)));
            }
        }

        $named = $this->named($lines[$first], $mapping ?? []);
        $header = $mapping !== null ? $named : $this->headerOf($lines[$first]);
        $headers = $this->headers($lines[$first], $named, $mapping ?? []);
        $calendars = [];
        $ignored = [];

        if ($header === null) {
            $keys = EmployeeImportRow::DEFAULT_ORDER;
        } else {
            $keys = [];
            foreach ($header as $position => [$key, $calendarNamed]) {
                $known = in_array($key, EmployeeImportRow::COLUMNS, true);
                $keys[$position] = $known ? $key : null;
                if ($known && $calendarNamed !== null) {
                    $calendars[$key] = $calendarNamed;
                }
                if (! $known && $key !== '' && $lines[$first][$position] !== '' && ! EmployeeImportRow::isIgnoredHeader($lines[$first][$position])) {
                    $ignored[] = $lines[$first][$position];
                }
            }

            // Told which column is which, and none of them is the name:
            // every line would be refused for the same reason. Say it once.
            if (! in_array('name', $keys, true)) {
                return new PeopleRead([], [], true, [], $ignored, $calendar, false, 0, $headers, false, true);
            }

            unset($lines[$first]);
        }

        $rows = [];
        $problems = [];
        $warnings = [];
        $terminals = $this->terminalTypes();
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

            $found = [
                ...EmployeeImportRow::problems($row, $rules),
                ...$this->relationProblems($row),
                ...$this->profileProblems($row),
                ...$this->duplicateProblems($row, $firstSeen, $line),
            ];
            foreach ($found as $problem) {
                $problems[] = ['line' => $line, ...$problem];
            }
            if ($found === []) {
                $rows[] = EmployeeImportRow::staged($row, $source);
                foreach ($this->terminalWarnings($row, $terminals) as $warning) {
                    $warnings[] = ['line' => $line, ...$warning];
                }
            }
        }

        if (count($rows) > self::MAX_PEOPLE) {
            $problems[] = ['line' => $last, 'message' => __('onboarding.migration.too_many', [
                'count' => count($rows),
                'max' => self::MAX_PEOPLE,
            ]), 'column' => null, 'code' => 'too_many'];
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
            headers: $headers,
            headerLikely: $header === null && $this->looksLikeNames($lines[$first]) && count($lines) > 1,
            warnings: $warnings,
        );
    }

    /**
     * Each cell of the first line as a column name: what the reader said it
     * is, or what ETHR reads it as. A column the reader left out is `''`,
     * which is no column and is not reported as unknown either.
     *
     * @param  list<string>  $cells
     * @param  array<string, string|null>  $mapping
     * @return list<array{0: string, 1: 'gregorian'|'ethiopian'|null}>
     */
    private function named(array $cells, array $mapping): array
    {
        return array_map(function (string $cell) use ($mapping): array {
            $key = EmployeeImportRow::headerKey($cell);
            if (! array_key_exists($key, $mapping)) {
                return EmployeeImportRow::column($cell);
            }

            $column = $mapping[$key] ?? '';

            return [$column, in_array($column, EmployeeImportRow::DATES, true) ? EmployeeImportRow::calendarMark($cell) : null];
        }, $cells);
    }

    /**
     * The first line described for someone matching columns: each name as
     * written, the ETHR column it is read as, and for one that is not read,
     * what it might be. A guess is offered once: two columns are not both
     * suggested as the name.
     *
     * @param  list<string>  $cells
     * @param  list<array{0: string, 1: 'gregorian'|'ethiopian'|null}>  $named
     * @param  array<string, string|null>  $mapping
     * @return list<array{header: string, column: string|null, suggested: string|null}>
     */
    private function headers(array $cells, array $named, array $mapping): array
    {
        $taken = [];
        foreach ($named as [$key]) {
            if (in_array($key, EmployeeImportRow::COLUMNS, true)) {
                $taken[$key] = true;
            }
        }

        $headers = [];
        foreach ($cells as $position => $cell) {
            if ($cell === '' || (! array_key_exists(EmployeeImportRow::headerKey($cell), $mapping) && EmployeeImportRow::isIgnoredHeader($cell))) {
                continue;
            }

            $key = $named[$position][0];
            $column = in_array($key, EmployeeImportRow::COLUMNS, true) ? $key : null;
            $suggested = null;
            if ($column === null && ! array_key_exists(EmployeeImportRow::headerKey($cell), $mapping)) {
                $guess = EmployeeImportRow::suggest($cell);
                if ($guess !== null && ! isset($taken[$guess])) {
                    $suggested = $guess;
                    $taken[$guess] = true;
                }
            }

            $headers[] = ['header' => $cell, 'column' => $column, 'suggested' => $suggested];
        }

        return $headers;
    }

    /**
     * A first line ETHR did not take for column names that reads like some
     * all the same: several cells, none with a digit or an email in it. A
     * person's line has a number, a date or an address somewhere.
     *
     * @param  list<string>  $cells
     */
    private function looksLikeNames(array $cells): bool
    {
        $filled = array_filter($cells, fn (string $cell): bool => $cell !== '');

        if (count($filled) < 2) {
            return false;
        }

        foreach ($filled as $cell) {
            if (preg_match('/\d|@/', $cell) === 1) {
                return false;
            }
        }

        return true;
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
     * The adapter types with a rule of their own for a person number that
     * this organisation has a terminal of. One query for the whole list.
     *
     * @return list<string>
     */
    private function terminalTypes(): array
    {
        return Device::query()
            ->where('tenant_id', $this->tenantId)
            ->whereIn('adapter_type', DeviceIdRules::restrictedTypes())
            ->distinct()
            ->orderBy('adapter_type')
            ->pluck('adapter_type')
            ->all();
    }

    /**
     * A badge number a terminal of this organisation would refuse. The person
     * is staged all the same: the number is theirs, and the terminal is told
     * nothing it would not take (DeviceCommandQueue).
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $terminals
     * @return list<array{column: string, code: string, message: string}>
     */
    private function terminalWarnings(array $row, array $terminals): array
    {
        $badge = $row['badge_number'] ?? null;
        if (! is_string($badge) || $badge === '' || $terminals === []) {
            return [];
        }

        $refusing = array_values(array_filter($terminals, fn (string $type): bool => ! DeviceIdRules::accepts($type, $badge)));
        if ($refusing === []) {
            return [];
        }

        return [[
            'column' => 'badge_number',
            'code' => 'terminal_number_not_accepted',
            'message' => __('onboarding.migration.terminal_number_digits_only', ['value' => $badge, 'types' => implode(', ', array_unique(array_map(DeviceIdRules::label(...), $refusing)))]),
        ]];
    }

    /**
     * A department, branch or position the organisation does not have. It
     * used to become a silent null: the person landed unassigned and nobody
     * knew.
     *
     * @param  array<string, mixed>  $row
     * @return list<array{column: string, code: string, message: string}>
     */
    private function relationProblems(array $row): array
    {
        $found = [];
        foreach (self::RELATIONS as $column) {
            $value = $row[$column] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }

            $match = $this->relation($column);
            if ($match->id($value) !== null) {
                continue;
            }

            $code = $match->ambiguous($value) ? 'relation_ambiguous' : 'relation_not_found';
            $found[] = [
                'column' => EmployeeImportRow::templateColumn($column),
                'code' => $code,
                'message' => __('onboarding.migration.'.$code, ['field' => __('employee.import_field_'.$column), 'value' => $value]),
            ];
        }

        return $found;
    }

    /**
     * What a new person's profile columns cannot say one by one: a grade or
     * cost centre the organisation does not have, a probation that ends before
     * the person starts, a contract that ends before it begins.
     *
     * @param  array<string, mixed>  $row
     * @return list<array{column: string, code: string, message: string}>
     */
    private function profileProblems(array $row): array
    {
        return EmployeeImportProfile::problems($row, $this->relation(...));
    }

    /** Read on first use: most lists name a department and nothing else. */
    private function relation(string $column): RelationMatch
    {
        return $this->relations[$column] ??= match ($column) {
            'branch_code' => RelationMatch::branches($this->tenantId),
            'position_code' => RelationMatch::positions($this->tenantId),
            'grade' => RelationMatch::grades($this->tenantId),
            'cost_center' => RelationMatch::costCenters($this->tenantId),
            default => RelationMatch::departments($this->tenantId),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, int>>  $firstSeen
     * @return list<array{column: string, code: string, message: string}>
     */
    private function duplicateProblems(array $row, array &$firstSeen, int $line): array
    {
        $found = [];
        foreach (self::UNIQUE_IN_LIST as $column) {
            $value = $row[$column] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }

            $key = mb_strtolower($value);
            if (isset($firstSeen[$column][$key])) {
                $found[] = [
                    'column' => EmployeeImportRow::templateColumn($column),
                    'code' => 'duplicate_in_list',
                    'message' => __('onboarding.migration.duplicate_in_list', [
                        'field' => __('employee.import_field_'.$column),
                        'value' => $value,
                        'first' => $firstSeen[$column][$key],
                    ]),
                ];

                continue;
            }

            $firstSeen[$column][$key] = $line;
        }

        return $found;
    }
}
