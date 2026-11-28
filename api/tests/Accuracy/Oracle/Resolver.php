<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

use DateTimeZone;

/**
 * Register sections 5 to 7: the ordered punches of one person in, the records
 * that exist and each punch's outcome out. One person, one organisation.
 *
 * A record is an array: in, out (null while open), method, wd (work date),
 * conflict, breaks (list of 'out' and 'in'), auto.
 */
final class Resolver
{
    private const MERGE = 300;           // AR-DAY-03/04/06: 5 minutes

    private const MAX_BREAK = 180 * 60;  // AR-BREAK-01/02

    private const MAX_OPEN = 14 * 3600;  // AR-NIGHT-02/04

    private const CLOSE_AFTER_END = 6 * 3600; // AR-NIGHT-02/03

    /** @var list<array<string, mixed>> */
    private array $records = [];

    /** @var list<string|null> */
    private array $outcomes = [];

    /** @var array<string, true> */
    private array $keys = [];

    /** @param array<string, mixed>|null $shift */
    public function __construct(
        private readonly ?array $shift,
        private readonly DateTimeZone $tz,
    ) {}

    /**
     * @param  array{type: string, method: string, ts?: int, in?: int, out?: int, key?: string}  $punch
     */
    public function punch(array $punch): void
    {
        $key = $punch['key'] ?? null;
        if ($key !== null && isset($this->keys[$key])) {
            // AR-DAY-01: the same key answers with the original and records nothing.
            $this->outcomes[] = 'duplicate';

            return;
        }

        $outcome = match ($punch['type']) {
            'in' => $this->checkIn($punch['ts'], $punch['method'], false),
            'out' => $this->checkOut($punch['ts']),
            'auto' => $this->automatic($punch['ts'], $punch['method']),
            'manual' => $this->manual($punch['in'], $punch['out'], $punch['method']),
        };

        if ($key !== null) {
            $this->keys[$key] = true;
        }
        $this->outcomes[] = $outcome;
    }

    /** AR-AUTO-01 and AR-AUTO-02: the job runs once, at `$runAt`. */
    public function runAutoCheckout(int $minutesAfterEnd, int $runAt): void
    {
        if ($this->shift === null) {
            return;
        }

        foreach ($this->records as $i => $r) {
            if ($r['out'] !== null) {
                continue;
            }
            $end = $this->shiftEnd($r['wd']);
            if ($r['in'] >= $end || $runAt - $r['in'] > 2 * 86400 || $runAt < $end + $minutesAfterEnd * 60) {
                continue;
            }
            $this->records[$i]['out'] = $end;
            $this->records[$i]['auto'] = true;
        }
    }

    /** @return list<array<string, mixed>> */
    public function records(): array
    {
        return $this->records;
    }

    /** @return list<string|null> null where the register gives no outcome. */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    public function conflicts(): int
    {
        return count(array_filter($this->records, static fn (array $r): bool => $r['conflict']));
    }

    // -- punches ---------------------------------------------------------

    private function checkIn(int $ts, string $method, bool $ignoreOpen): ?string
    {
        $wd = $this->workDateOf($ts);

        if (! $ignoreOpen) {
            $open = $this->openFor($ts, $wd);
            if ($open !== null) {
                $r = $this->records[$open];
                if ($r['method'] !== $method && abs($ts - $r['in']) <= self::MERGE) {
                    // AR-DAY-03: close sources are one arrival; the outcome is not in the register.
                    $this->records[$open]['in'] = min($r['in'], $ts);

                    return null;
                }

                // AR-DAY-02
                return 'duplicate';
            }
        }

        $closed = $this->lastClosedOn($wd);
        if ($closed !== null) {
            $gap = $ts - $this->records[$closed]['out'];
            $beforeEnd = $this->shift === null || $ts < $this->shiftEnd($wd); // AR-BREAK-04
            if ($gap >= 0 && $gap <= self::MAX_BREAK && $beforeEnd) {
                // AR-BREAK-01: the day is reopened.
                $this->records[$closed]['out'] = null;
                $this->records[$closed]['breaks'][] = 'out';
                $this->records[$closed]['breaks'][] = 'in';

                return 'ok';
            }
        }

        // AR-BREAK-02: a second session beside an existing record of the day is a conflict.
        $this->add($ts, null, $method, $wd, $this->hasRecordOn($wd));

        return 'ok';
    }

    private function checkOut(int $ts): string
    {
        $wd = $this->workDateOf($ts);
        $open = $this->openFor($ts, $wd);
        if ($open !== null) {
            $this->records[$open]['out'] = $ts;

            return 'ok';
        }

        // AR-DAY-04 / AR-DAY-05
        foreach ($this->records as $r) {
            if ($r['out'] !== null && $ts - $r['out'] >= 0 && $ts - $r['out'] <= self::MERGE) {
                return 'duplicate';
            }
        }

        return 'refused:no_open_check_in';
    }

    private function automatic(int $ts, string $method): ?string
    {
        $wd = $this->workDateOf($ts);

        $open = $this->latestOpen($ts);
        if ($open !== null) {
            $r = $this->records[$open];
            if ($r['wd'] >= $wd) {
                if ($ts - $r['in'] <= self::MERGE) {
                    return 'duplicate'; // AR-DAY-06
                }
                $this->records[$open]['out'] = $ts;

                return 'ok';
            }

            if ($this->shift !== null && $ts <= $this->shiftEnd($r['wd']) + self::CLOSE_AFTER_END) {
                $this->records[$open]['out'] = $ts; // AR-NIGHT-03

                return 'ok';
            }

            // AR-NIGHT-03: later than that it is a new arrival.
            return $this->checkIn($ts, $method, true);
        }

        foreach ($this->records as $r) {
            if ($r['out'] !== null && $ts - $r['out'] >= 0 && $ts - $r['out'] <= self::MERGE) {
                return 'duplicate'; // AR-DAY-04
            }
        }

        return $this->checkIn($ts, $method, false);
    }

    private function manual(int $in, int $out, string $method): ?string
    {
        if ($out - $in >= self::MAX_OPEN) {
            // AR-NIGHT-04: 14 hours or more is a mistyped time.
            return 'refused:span_too_long';
        }

        $wd = $this->workDateOf($in);

        foreach ($this->records as $i => $r) {
            if ($r['wd'] !== $wd) {
                continue;
            }
            $existing = array_filter([$r['in'], $r['out']], static fn (?int $t): bool => $t !== null);
            foreach ($existing as $punch) {
                if (abs($punch - $in) <= self::MERGE || abs($punch - $out) <= self::MERGE) {
                    // AR-DAY-03: kept as one, earliest check-in and latest check-out.
                    $this->records[$i]['in'] = min($r['in'], $in);
                    $this->records[$i]['out'] = $r['out'] === null ? $out : max($r['out'], $out);

                    return null;
                }
            }
        }

        // AR-DAY-07: further than 5 minutes from every punch of the day.
        $this->add($in, $out, $method, $wd, $this->hasRecordOn($wd));

        return null;
    }

    // -- helpers ---------------------------------------------------------

    /** AR-NIGHT-01 and AR-SHIFT-05. */
    private function workDateOf(int $ts): string
    {
        $date = Calendar::localDate($ts, $this->tz);
        if ($this->shift !== null && $this->shift['crosses'] && Calendar::secondOfDay($ts, $this->tz) < $this->shift['end']) {
            return Calendar::addDays($date, -1);
        }

        return $date;
    }

    private function shiftEnd(string $workDate): int
    {
        return Shifts::window($this->shift, $workDate, $this->tz)[1];
    }

    /**
     * The open record a punch at `$ts` (work date `$wd`) belongs to. A record of
     * an earlier work date counts only inside the closing window (AR-NIGHT-02);
     * the same window decides whether yesterday's open day is still the
     * person's current one when a check-in arrives.
     */
    private function openFor(int $ts, string $wd): ?int
    {
        foreach ($this->openIndexes($ts) as $i) {
            $r = $this->records[$i];
            if ($r['wd'] >= $wd || $this->withinCloseWindow($r, $ts)) {
                return $i;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $r */
    private function withinCloseWindow(array $r, int $ts): bool
    {
        if ($ts - $r['in'] <= self::MAX_OPEN) {
            return true;
        }

        return $this->shift !== null
            && $this->shift['crosses']
            && $ts <= $this->shiftEnd($r['wd']) + self::CLOSE_AFTER_END;
    }

    /** @return list<int> open records started at or before `$ts`, latest first. */
    private function openIndexes(int $ts): array
    {
        $indexes = [];
        foreach ($this->records as $i => $r) {
            if ($r['out'] === null && $r['in'] <= $ts) {
                $indexes[] = $i;
            }
        }
        usort($indexes, fn (int $a, int $b): int => $this->records[$b]['in'] <=> $this->records[$a]['in']);

        return $indexes;
    }

    private function latestOpen(int $ts): ?int
    {
        return $this->openIndexes($ts)[0] ?? null;
    }

    private function lastClosedOn(string $wd): ?int
    {
        $best = null;
        foreach ($this->records as $i => $r) {
            if ($r['wd'] === $wd && $r['out'] !== null && ($best === null || $r['out'] > $this->records[$best]['out'])) {
                $best = $i;
            }
        }

        return $best;
    }

    private function hasRecordOn(string $wd): bool
    {
        foreach ($this->records as $r) {
            if ($r['wd'] === $wd) {
                return true;
            }
        }

        return false;
    }

    private function add(int $in, ?int $out, string $method, string $wd, bool $conflict): void
    {
        $this->records[] = [
            'in' => $in,
            'out' => $out,
            'method' => $method,
            'wd' => $wd,
            'conflict' => $conflict,
            'breaks' => [],
            'auto' => false,
        ];
    }
}
