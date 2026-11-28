<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

use DateTimeZone;

/**
 * Register sections 5 to 7: the ordered punches of one person in, the records
 * that exist and each punch's outcome out. One person, one organisation. Every
 * Undecided rule it meets is read through the Readings it was given.
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

    private const AUTO_MAX_AGE = 2 * 86400; // AR-AUTO-02

    /** @var list<array<string, mixed>> */
    private array $records = [];

    /** @var list<string|null> */
    private array $outcomes = [];

    /** @var array<string, true> */
    private array $keys = [];

    /** @var array<string, Trace> by work date */
    private array $traces = [];

    /** @param array<string, mixed>|null $shift */
    public function __construct(
        private readonly ?array $shift,
        private readonly DateTimeZone $tz,
        private readonly Readings $readings,
    ) {}

    /**
     * @param  array{type: string, method: string, ts?: int, in?: int, out?: int, key?: string}  $punch
     */
    public function punch(array $punch): void
    {
        $key = $punch['key'] ?? null;
        if ($key !== null && isset($this->keys[$key])) {
            $this->outcomes[] = 'duplicate';

            return;
        }

        $outcome = match ($punch['type']) {
            'in' => $this->checkIn($punch['ts'], $punch['method']),
            'out' => $this->checkOut($punch['ts']),
            'auto' => $this->automatic($punch['ts'], $punch['method']),
            'manual' => $this->manual($punch['in'], $punch['out'], $punch['method']),
        };

        if ($key !== null) {
            $this->keys[$key] = true;
            $this->hit($this->workDateOf($punch['ts'] ?? $punch['in']), 'AR-DAY-01');
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
            // AR-AUTO-02: a check-in after the end is not closed; exactly at the end is AR-NIGHT-05.
            $afterEnd = $this->readings->flag('auto_at_end_open') ? $r['in'] >= $end : $r['in'] > $end;
            if ($afterEnd || ! $this->readings->atMost($runAt - $r['in'], self::AUTO_MAX_AGE) || $runAt < $end + $minutesAfterEnd * 60) {
                continue;
            }
            $this->records[$i]['out'] = $end;
            $this->records[$i]['auto'] = true;
            $this->hit($r['wd'], 'AR-AUTO-01');
            $this->hit($r['wd'], 'AR-AUTO-02');
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

    /** @return array<string, Trace> */
    public function traces(): array
    {
        return $this->traces;
    }

    // -- punches ---------------------------------------------------------

    private function checkIn(int $ts, string $method): ?string
    {
        $wd = $this->workDateOf($ts);
        $this->hit($wd, 'AR-CLOCK-02');

        // AR-DAY-09: only a record of this very work date can be open for it.
        $open = $this->openOn($wd, $ts);
        if ($open !== null) {
            $r = $this->records[$open];
            if ($r['method'] !== $method && $this->readings->atMost(abs($ts - $r['in']), self::MERGE)) {
                $this->hit($wd, 'AR-DAY-03');
                $this->records[$open]['in'] = min($r['in'], $ts);

                return null;
            }

            $this->hit($wd, 'AR-DAY-02');

            return 'duplicate';
        }
        $this->hit($wd, 'AR-DAY-09');

        $closed = $this->lastClosedOn($wd);
        if ($closed !== null) {
            $gap = $ts - $this->records[$closed]['out'];
            $beforeEnd = $this->shift === null || $ts < $this->shiftEnd($wd);
            if ($this->shift === null) {
                $this->hit($wd, 'AR-BREAK-04');
            }
            if ($gap >= 0 && $this->readings->atMost($gap, self::MAX_BREAK) && $beforeEnd) {
                $this->hit($wd, 'AR-BREAK-01');
                $this->records[$closed]['out'] = null;
                $this->records[$closed]['breaks'][] = 'out';
                $this->records[$closed]['breaks'][] = 'in';

                return 'ok';
            }
            $this->hit($wd, 'AR-BREAK-02');
        }

        $this->add($ts, null, $method, $wd, $this->hasRecordOn($wd));

        return 'ok';
    }

    private function checkOut(int $ts): string
    {
        $wd = $this->workDateOf($ts);
        $open = $this->openForCheckOut($ts, $wd);
        if ($open !== null) {
            $this->hit($this->records[$open]['wd'], 'AR-NIGHT-02');
            $this->records[$open]['out'] = $ts;

            return 'ok';
        }

        foreach ($this->records as $r) {
            if ($r['out'] !== null && $ts - $r['out'] >= 0 && $this->readings->atMost($ts - $r['out'], self::MERGE)) {
                $this->hit($r['wd'], 'AR-DAY-04');

                return 'duplicate';
            }
        }
        $this->hit($wd, 'AR-DAY-05');

        return 'refused:no_open_check_in';
    }

    private function automatic(int $ts, string $method): ?string
    {
        $wd = $this->workDateOf($ts);

        $open = $this->latestOpen($ts);
        if ($open !== null) {
            $r = $this->records[$open];
            if ($r['wd'] >= $wd) {
                $this->hit($r['wd'], 'AR-DAY-06');
                if ($this->readings->atMost($ts - $r['in'], self::MERGE)) {
                    return 'duplicate';
                }
                $this->records[$open]['out'] = $ts;

                return 'ok';
            }

            // Yesterday's open record. AR-NIGHT-03 needs a shift end; with no shift, AR-NIGHT-05.
            $closes = $this->shift !== null
                ? $this->readings->inside($ts - $this->shiftEnd($r['wd']), self::CLOSE_AFTER_END)
                : ($this->readings->flag('auto_noshift_closes') && $this->readings->inside($ts - $r['in'], self::MAX_OPEN));
            if ($closes) {
                $this->hit($r['wd'], 'AR-NIGHT-03');
                $this->records[$open]['out'] = $ts;

                return 'ok';
            }

            return $this->checkIn($ts, $method);
        }

        foreach ($this->records as $r) {
            if ($r['out'] !== null && $ts - $r['out'] >= 0 && $this->readings->atMost($ts - $r['out'], self::MERGE)) {
                $this->hit($r['wd'], 'AR-DAY-04');

                return 'duplicate';
            }
        }
        $this->hit($wd, 'AR-DAY-06');

        return $this->checkIn($ts, $method);
    }

    private function manual(int $in, int $out, string $method): ?string
    {
        if ($out - $in >= self::MAX_OPEN) {
            $this->hit($this->workDateOf($in), 'AR-NIGHT-04');

            return 'refused:span_too_long';
        }

        $wd = $this->workDateOf($in);
        $this->hit($wd, 'AR-NIGHT-04');

        foreach ($this->records as $i => $r) {
            if ($r['wd'] !== $wd) {
                continue;
            }
            if ($this->nearExisting($r, $in, $out)) {
                $this->hit($wd, 'AR-DAY-03');
                $this->records[$i]['in'] = min($r['in'], $in);
                $this->records[$i]['out'] = $r['out'] === null ? $out : max($r['out'], $out);

                return null;
            }
        }

        $this->hit($wd, 'AR-DAY-07');
        $this->add($in, $out, $method, $wd, $this->hasRecordOn($wd));

        return null;
    }

    /**
     * Whether a manual entry is within 5 minutes of a punch of the record.
     * Which pairs are measured is AR-DAY-08.
     *
     * @param  array<string, mixed>  $r
     */
    private function nearExisting(array $r, int $in, int $out): bool
    {
        if ($this->readings->flag('check_ins_only')) {
            return $this->readings->atMost(abs($r['in'] - $in), self::MERGE);
        }

        foreach (array_filter([$r['in'], $r['out']], static fn (?int $t): bool => $t !== null) as $punch) {
            if ($this->readings->atMost(abs($punch - $in), self::MERGE) || $this->readings->atMost(abs($punch - $out), self::MERGE)) {
                return true;
            }
        }

        return false;
    }

    // -- helpers ---------------------------------------------------------

    private function hit(string $workDate, string $rule): void
    {
        ($this->traces[$workDate] ??= new Trace)->decided($rule);
    }

    /** AR-NIGHT-01 and AR-SHIFT-05. */
    private function workDateOf(int $ts): string
    {
        $date = Calendar::localDate($ts, $this->tz);
        if ($this->shift !== null && $this->shift['crosses'] && Calendar::secondOfDay($ts, $this->tz) < $this->shift['end']) {
            $this->hit(Calendar::addDays($date, -1), 'AR-NIGHT-01');

            return Calendar::addDays($date, -1);
        }

        return $date;
    }

    private function shiftEnd(string $workDate): int
    {
        return Shifts::window($this->shift, $workDate, $this->tz)[1];
    }

    /** The latest record of work date `$wd` that is open at `$ts`. */
    private function openOn(string $wd, int $ts): ?int
    {
        foreach ($this->openIndexes($ts) as $i) {
            if ($this->records[$i]['wd'] === $wd) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The open record a check-out at `$ts` closes: one of its own work date, or
     * yesterday's inside the closing window (AR-NIGHT-02). A same-date record
     * has no limit; the windows are about yesterday's.
     */
    private function openForCheckOut(int $ts, string $wd): ?int
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
        if ($this->readings->inside($ts - $r['in'], self::MAX_OPEN)) {
            return true;
        }

        return $this->shift !== null
            && $this->shift['crosses']
            && $this->readings->inside($ts - $this->shiftEnd($r['wd']), self::CLOSE_AFTER_END);
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
