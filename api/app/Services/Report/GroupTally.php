<?php

declare(strict_types=1);

namespace App\Services\Report;

/**
 * Per-group row counts and sums for a grouped report, one row at a time, so a
 * pass over every matching row never holds them all.
 *
 * Sums any `*_cents`, `*_days`, `*_hours` or `*_minutes` column among the
 * selected ones: grouping the payroll source by `period` gives the monthly
 * income-tax and pension totals a statutory filing needs, and grouping the
 * timesheet by department gives absent days per department, without a
 * source-specific code path.
 */
final class GroupTally
{
    /** Columns summed per group: money, day counts and durations. */
    private const SUMMED_SUFFIXES = ['_cents', '_days', '_hours', '_minutes'];

    /** @var array<string, int> */
    private array $groups = [];

    /** @var array<string, array<string, int|float>> */
    private array $sums = [];

    /** @param  list<string>  $columns  The selected columns; none selects them all. */
    public function __construct(
        private readonly string $groupBy,
        private readonly array $columns = [],
    ) {}

    /** @param  array<string, mixed>  $row  The full row, before columns are cut. */
    public function add(array $row): void
    {
        $key = (string) ($row[$this->groupBy] ?? 'Unknown');
        $this->groups[$key] = ($this->groups[$key] ?? 0) + 1;

        $selected = $this->columns === [] ? $row : array_intersect_key($row, array_flip($this->columns));
        foreach ($selected as $field => $value) {
            if (is_numeric($value) && self::summed((string) $field)) {
                $this->sums[$key][$field] = str_ends_with((string) $field, '_hours')
                    ? round(($this->sums[$key][$field] ?? 0) + (float) $value, 1)
                    : ($this->sums[$key][$field] ?? 0) + (int) $value;
            }
        }
    }

    /**
     * The groups in the order `$order` names them, then any it does not.
     *
     * @param  list<string>  $order
     * @return array<string, int>
     */
    public function groups(array $order = []): array
    {
        return self::ordered($this->groups, $order);
    }

    /**
     * @param  list<string>  $order
     * @return array<string, array<string, int|float>>
     */
    public function sums(array $order = []): array
    {
        return self::ordered($this->sums, $order);
    }

    private static function summed(string $field): bool
    {
        foreach (self::SUMMED_SUFFIXES as $suffix) {
            if (str_ends_with($field, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T
     *
     * @param  array<string, T>  $values
     * @param  list<string>  $order
     * @return array<string, T>
     */
    private static function ordered(array $values, array $order): array
    {
        $out = [];
        foreach ($order as $key) {
            if (array_key_exists($key, $values)) {
                $out[$key] = $values[$key];
            }
        }

        return $out + $values;
    }
}
