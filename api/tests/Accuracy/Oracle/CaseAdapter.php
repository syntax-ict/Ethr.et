<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

use DateTimeZone;
use InvalidArgumentException;

/**
 * Runs the oracle on one golden case exactly as the case files define it and
 * answers in the shape of the case's `expect`. A field it does not model is a
 * NoOpinion; a field that depends on an Undecided rule is an Undecided.
 */
final class CaseAdapter
{
    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, array<string, mixed>>  $shifts  fixtures/shifts.php
     * @return array{outcomes: list<string|null>, live: int, conflicts: int, days: array<string, array<string, mixed>>, meta: array{undecided: list<string>, implemented: list<string>}}
     */
    public static function run(array $case, array $shifts): array
    {
        $tz = new DateTimeZone($case['zone'] ?? 'Africa/Addis_Ababa');
        $day = $case['day'] ?? '2026-10-13';
        $setup = $case['setup'] ?? [];
        $shift = isset($case['shift']) ? Shifts::normalise($shifts[$case['shift']]) : null;
        $trace = new Trace;

        $resolver = new Resolver($shift, $tz);
        $lastPunch = null;

        foreach ($case['punches'] ?? [] as $p) {
            $method = (string) ($p['method'] ?? 'web');
            if ($p['type'] === 'manual') {
                [$in] = self::time($p['in'], $day, $tz);
                [$out, $explicit] = self::time($p['out'], $day, $tz);
                if (! $explicit && $out < $in) {
                    // AR-NIGHT-04: earlier on the clock than the check-in is the next morning.
                    [$out] = self::time($p['out'], Calendar::addDays($day, 1), $tz);
                }
                $resolver->punch(['type' => 'manual', 'method' => $method, 'in' => $in, 'out' => $out]);
                $lastPunch = max($lastPunch ?? $out, $out);

                continue;
            }

            [$ts] = self::time($p['at'], $day, $tz);
            $resolver->punch([
                'type' => $p['type'],
                'method' => $method,
                'ts' => $ts,
                ...(isset($p['key']) ? ['key' => (string) $p['key']] : []),
            ]);
            $lastPunch = max($lastPunch ?? $ts, $ts);
        }

        $runAt = isset($case['auto_run_at']) ? self::time($case['auto_run_at'], $day, $tz)[0] : null;
        if (isset($setup['auto_checkout']) && $runAt !== null) {
            $resolver->runAutoCheckout((int) $setup['auto_checkout'], $runAt);
        }

        // The day is read at `now`, else at the job's run, else at the last punch.
        $now = isset($case['now']) ? self::time($case['now'], $day, $tz)[0] : ($runAt ?? $lastPunch);

        $records = $resolver->records();
        $classifier = new DayClassifier(self::person($setup, $day, $shift), $trace);
        $rater = new Rater($trace);

        $dates = array_keys($case['expect']['days'] ?? []);
        foreach ($records as $r) {
            $dates[] = $r['wd'];
        }
        $dates = array_values(array_unique($dates));
        sort($dates);

        // AR-OT-07: of records that overlap, only the longest pays overtime.
        $paying = self::payingRecords($records);

        // AR-ABS-05: attendance is recorded when a second person or this one has a record.
        $organisationRecords = ! empty($setup['peer']) || $records !== [];

        $days = [];
        foreach ($dates as $date) {
            $class = $classifier->classify($date);
            $mine = array_filter($records, static fn (array $r): bool => $r['wd'] === $date);
            uasort($mine, static fn (array $a, array $b): int => $a['in'] <=> $b['in']);

            $hand = $case['expect']['days'][$date] ?? [];
            $days[$date] = self::day($date, $class, $mine, $paying, $shift, $rater, $tz, $hand)
                + ['absent' => $classifier->absent($date, $class, $mine !== [], $organisationRecords, $now, $tz)];
        }

        return [
            'outcomes' => $resolver->outcomes(),
            'live' => count($records),
            'conflicts' => $resolver->conflicts(),
            'days' => $days,
            'meta' => ['undecided' => $trace->undecidedRules(), 'implemented' => $trace->implementedRules()],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $mine  records of the date, keyed by their index
     * @param  list<int>  $paying  record indexes that pay overtime
     * @param  array<string, mixed>|null  $shift
     * @param  array<string, mixed>  $hand  the case's hand values for the date (to choose time formats)
     * @return array<string, mixed>
     */
    private static function day(string $date, string $class, array $mine, array $paying, ?array $shift, Rater $rater, DateTimeZone $tz, array $hand): array
    {
        $day = ['live' => count($mine), 'class' => $class];

        $overtime = [];
        $first = null;
        $firstRated = null;
        foreach ($mine as $i => $r) {
            $rated = $rater->rate($r['in'], $r['out'], $shift, $date, $class, $tz);
            if ($first === null) {
                $first = $r;
                $firstRated = $rated;
            }
            if (in_array($i, $paying, true)) {
                foreach ($rated['overtime'] as $bucket => $minutes) {
                    $overtime[$bucket] = ($overtime[$bucket] ?? 0) + $minutes;
                }
            }
        }
        $day['overtime'] = $overtime;

        if ($first === null) {
            $none = new NoOpinion('no record on this date');

            return $day + ['status' => $none, 'late' => $none, 'early' => $none, 'worked' => $none, 'check_in' => null, 'check_out' => null, 'breaks' => [], 'metadata' => ['auto_checkout' => false]];
        }

        // Per-record fields are read from the first record of the date.
        return $day + [
            'status' => $firstRated['status'],
            'late' => $firstRated['late'],
            'early' => $firstRated['early'],
            'worked' => $firstRated['worked'],
            'check_in' => self::clock($first['in'], $tz, $hand['check_in'] ?? null),
            'check_out' => $first['out'] === null ? null : self::clock($first['out'], $tz, $hand['check_out'] ?? null),
            'breaks' => $first['breaks'],
            'metadata' => ['auto_checkout' => $first['auto']],
        ];
    }

    /** Local `H:i`, or `H:i:s` when the hand value has seconds. */
    private static function clock(int $ts, DateTimeZone $tz, mixed $hand): string
    {
        $withSeconds = is_string($hand) && strlen($hand) === 8;

        return Calendar::format($ts, $tz, $withSeconds ? 'H:i:s' : 'H:i');
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<int>
     */
    private static function payingRecords(array $records): array
    {
        $closed = [];
        foreach ($records as $i => $r) {
            if ($r['out'] !== null) {
                $closed[] = $i;
            }
        }

        // Group records that overlap, directly or through a chain.
        $group = [];
        foreach ($closed as $i) {
            $group[$i] = $i;
        }
        $root = static function (int $i) use (&$group): int {
            while ($group[$i] !== $i) {
                $i = $group[$i];
            }

            return $i;
        };
        foreach ($closed as $a) {
            foreach ($closed as $b) {
                if ($a < $b && $records[$a]['in'] < $records[$b]['out'] && $records[$b]['in'] < $records[$a]['out']) {
                    $group[$root($b)] = $root($a);
                }
            }
        }

        $best = [];
        foreach ($closed as $i) {
            $g = $root($i);
            $span = $records[$i]['out'] - $records[$i]['in'];
            if (! isset($best[$g]) || $span > $records[$best[$g]]['out'] - $records[$best[$g]]['in']) {
                $best[$g] = $i;
            }
        }

        // Records still open earn no overtime, so they never need to be excluded.
        return array_values($best);
    }

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>|null  $shift
     * @return array{hire: string, termination: ?string, holidays: list<string>, leave: ?array{0: string, 1: string}, shift: ?array<string, mixed>}
     */
    private static function person(array $setup, string $day, ?array $shift): array
    {
        $holiday = $setup['holiday'] ?? false;
        $holidays = [];
        if ($holiday === true) {
            $holidays[] = $day;
        } elseif (is_string($holiday)) {
            $holidays[] = $holiday;
        }
        // `holiday_elsewhere` is another branch's holiday (AR-ABS-03): not this person's.

        return [
            'hire' => $setup['hire_date'] ?? '2024-01-01',
            'termination' => null,
            'holidays' => $holidays,
            'leave' => $setup['leave'] ?? null,
            'shift' => $shift,
        ];
    }

    /**
     * `08:46`, `08:45:30`, `+1 06:05` or `-1 22:00` on `$day` in `$tz`.
     *
     * @return array{0: int, 1: bool} the moment, and whether a day offset was written
     */
    private static function time(string $text, string $day, DateTimeZone $tz): array
    {
        if (! preg_match('/^(?:([+-]\d+)\s+)?(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($text), $m)) {
            throw new InvalidArgumentException("unreadable time: {$text}");
        }

        $offset = ($m[1] ?? '') === '' ? 0 : (int) $m[1];
        $second = ((int) $m[2]) * 3600 + ((int) $m[3]) * 60 + (int) ($m[4] ?? 0);

        return [Calendar::at(Calendar::addDays($day, $offset), $second, $tz), ($m[1] ?? '') !== ''];
    }
}
