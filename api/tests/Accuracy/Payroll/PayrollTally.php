<?php

declare(strict_types=1);

namespace Tests\Accuracy\Payroll;

/**
 * What the golden test could not compare against the engine, counted over the
 * whole run and printed once at the end, so nothing is withheld silently.
 */
final class PayrollTally
{
    /** @var array<string, int> field => times stated by a case and not comparable */
    public static array $notComparable = [];

    /** @var array<string, list<string>> case id => fields withheld because the oracle marks them Undecided */
    public static array $withheld = [];

    /** @param list<string> $fields */
    public static function notComparable(array $fields): void
    {
        foreach ($fields as $field) {
            self::$notComparable[$field] = (self::$notComparable[$field] ?? 0) + 1;
        }
    }

    /** @param list<string> $fields */
    public static function withheld(string $id, array $fields): void
    {
        if ($fields !== []) {
            self::$withheld[$id] = $fields;
        }
    }

    public static function report(): string
    {
        ksort(self::$notComparable);
        $parts = [];
        foreach (self::$notComparable as $field => $n) {
            $parts[] = "{$field} x{$n}";
        }
        $withheld = array_sum(array_map('count', self::$withheld));

        return sprintf(
            'PAYROLL TALLY: %d stated fields not comparable against the engine (%s); %d fields withheld in %d undecided cases (%s)',
            array_sum(self::$notComparable),
            $parts === [] ? 'none' : implode(', ', $parts),
            $withheld,
            count(self::$withheld),
            implode(', ', array_keys(self::$withheld)) ?: 'none',
        );
    }
}
