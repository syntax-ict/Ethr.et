<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The date and origin a salary change is recorded under.
 *
 * The Employee model writes one history row whenever its salary changes, and
 * it learns the date and source from the innermost frame here. A frame lives
 * only for the duration of the callable, popped in `finally`, so a date can
 * never leak into the next save of a bulk loop. Not a container binding: a
 * binding is state that outlives the call.
 *
 * `suppressed()` pushes a frame that means "write no row": for a writer whose
 * row already exists (a scheduled action applying on its day).
 */
final class SalaryChangeContext
{
    /** @var list<array{date: string|null, source: string|null, source_id: int|null, suppressed: bool}> */
    private static array $stack = [];

    public static function during(CarbonInterface|string $effectiveDate, string $source, ?int $sourceId, callable $fn): mixed
    {
        self::$stack[] = [
            'date' => $effectiveDate instanceof CarbonInterface ? $effectiveDate->toDateString() : ($effectiveDate === '' ? null : substr($effectiveDate, 0, 10)),
            'source' => $source,
            'source_id' => $sourceId,
            'suppressed' => false,
        ];

        try {
            return $fn();
        } finally {
            array_pop(self::$stack);
        }
    }

    public static function suppressed(callable $fn): mixed
    {
        self::$stack[] = ['date' => null, 'source' => null, 'source_id' => null, 'suppressed' => true];

        try {
            return $fn();
        } finally {
            array_pop(self::$stack);
        }
    }

    /** @return array{date: string|null, source: string|null, source_id: int|null, suppressed: bool}|null */
    public static function current(): ?array
    {
        return self::$stack === [] ? null : self::$stack[array_key_last(self::$stack)];
    }
}
