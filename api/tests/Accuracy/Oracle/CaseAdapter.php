<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

use DateTimeZone;
use InvalidArgumentException;

/**
 * Runs the oracle on one golden case and answers with what the register says.
 *
 * The oracle never sees a hand value: it reads the case's `shift`, `zone`,
 * `day`, `setup`, `punches`, `now` and `auto_run_at`, and the dates to report
 * are a question passed in. It answers under a baseline reading of every
 * Undecided rule and again under each single alternative reading; a field that
 * changes between them is an Undecided value, a field that does not is
 * answered. A field the register does not cover is a NoOpinion.
 */
final class CaseAdapter
{
    private const SETUP_KEYS = ['holiday', 'holiday_elsewhere', 'leave', 'hire_date', 'termination_date', 'peer', 'auto_checkout'];

    private const DEFAULT_AUTO_CHECKOUT_MINUTES = 120;

    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, array<string, mixed>>  $shifts  fixtures/shifts.php
     * @param  list<string>|null  $dates  work dates to report; null: every work date a punch touches, and the case's day
     * @return array{outcomes: list<mixed>, live: mixed, conflicts: mixed, days: array<string, array<string, mixed>>, meta: array{decided: list<string>, implemented: list<string>, undecided: list<string>}}
     */
    public static function run(array $case, array $shifts, ?array $dates = null): array
    {
        $baseline = self::answer($case, $shifts, $dates, new Readings);
        $answer = $baseline;
        $reported = array_keys($baseline['days']);

        foreach (Readings::alternatives() as [$rule, $readings]) {
            $alternative = self::answer($case, $shifts, $reported, $readings);

            $paths = [];
            self::differences($baseline, $alternative, [], $paths);
            foreach ($paths as $path) {
                self::blank($answer, $path, $rule);
            }
        }

        $undecided = [];
        foreach ($answer['days'] as $date => &$day) {
            $rules = [];
            self::collect($day, $rules);
            $day['trace']['undecided'] = array_keys($rules);
            sort($day['trace']['undecided']);
            $undecided += $rules;
        }
        unset($day);
        foreach (['live', 'conflicts', 'outcomes'] as $key) {
            self::collect($answer[$key], $undecided);
        }
        $rules = array_keys($undecided);
        sort($rules);
        $answer['meta']['undecided'] = $rules;

        return $answer;
    }

    /**
     * One reading of the rules, with no Undecided values in it.
     *
     * @return array<string, mixed>
     */
    private static function answer(array $case, array $shifts, ?array $dates, Readings $readings): array
    {
        $tz = Calendar::zone((string) ($case['zone'] ?? 'Africa/Addis_Ababa'));
        $day = Calendar::date((string) ($case['day'] ?? '2026-10-13'));
        $setup = self::setup($case['setup'] ?? []);
        $shift = isset($case['shift']) ? Shifts::normalise($shifts[$case['shift']]) : null;

        $resolver = new Resolver($shift, $tz, $readings);
        $lastPunch = null;

        foreach ($case['punches'] ?? [] as $p) {
            $method = (string) ($p['method'] ?? 'web');
            if ($p['type'] === 'manual') {
                [$in] = self::time($p['in'], $day, $tz);
                [$out, $explicit] = self::time($p['out'], $day, $tz);
                if (! $explicit && $out < $in) {
                    [$out] = self::time($p['out'], Calendar::addDays($day, 1), $tz);
                }
                $resolver->punch(['type' => 'manual', 'method' => $method, 'in' => $in, 'out' => $out]);
                $lastPunch = max($lastPunch ?? $out, $out);

                continue;
            }
            if (! in_array($p['type'], ['in', 'out', 'auto'], true)) {
                throw new InvalidArgumentException("unknown punch type: {$p['type']}");
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
        if (isset($setup['auto_checkout'])) {
            if ($runAt === null) {
                throw new InvalidArgumentException('auto_checkout is set but the case has no auto_run_at');
            }
            $resolver->runAutoCheckout($setup['auto_checkout'], $runAt);
        }

        // The day is read at `now`, else at the job's run, else at the last punch.
        $now = isset($case['now']) ? self::time($case['now'], $day, $tz)[0] : ($runAt ?? $lastPunch);

        $records = $resolver->records();
        $classifier = new DayClassifier(self::person($setup, $day, $shift));

        if ($dates === null) {
            $dates = [$day];
            foreach ($records as $r) {
                $dates[] = $r['wd'];
            }
        }
        $dates = array_values(array_unique(array_map(Calendar::date(...), $dates)));
        sort($dates);

        // AR-ABS-05: attendance is recorded when a second person or this one has a record.
        $organisationRecords = ! empty($setup['peer']) || $records !== [];

        $traces = [];
        $classes = [];
        $raters = [];
        foreach ($dates as $date) {
            $traces[$date] = new Trace;
            if (isset($resolver->traces()[$date])) {
                $traces[$date]->merge($resolver->traces()[$date]);
            }
            $classes[$date] = $classifier->classify($date, $traces[$date]);
            $raters[$date] = new Rater($traces[$date], $readings, static fn (string $d): string => $classifier->classify($d, new Trace));
        }

        // Rate every record, then decide which overlapping records pay (AR-OT-07, AR-OT-14).
        $rated = [];
        foreach ($dates as $date) {
            $first = true;
            foreach (self::recordsOn($records, $date) as $i => $r) {
                $takeBreak = $first || ! $readings->flag('break_once_per_day'); // AR-MIN-08
                $rated[$i] = $raters[$date]->rate($r['in'], $r['out'], $shift, $date, $classes[$date], $tz, $takeBreak);
                $first = false;
            }
        }
        $paying = self::payingRecords($records, $rated, $readings);

        $days = [];
        foreach ($dates as $date) {
            $mine = self::recordsOn($records, $date);
            $days[$date] = self::day($date, $classes[$date], $mine, $rated, $paying, $tz)
                + ['absent' => $classifier->absent($date, $classes[$date], $mine !== [], $organisationRecords, $now, $tz, $traces[$date])]
                + ['trace' => ['decided' => $traces[$date]->decidedRules(), 'implemented' => $traces[$date]->implementedRules(), 'undecided' => []]];
        }

        $all = new Trace;
        $implemented = new Trace;
        foreach ($traces as $t) {
            $all->merge($t);
            $implemented->merge($t);
        }

        return [
            'outcomes' => $resolver->outcomes(),
            'live' => count($records),
            'conflicts' => $resolver->conflicts(),
            'days' => $days,
            'meta' => ['decided' => $all->decidedRules(), 'implemented' => $implemented->implementedRules(), 'undecided' => []],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $mine  records of the date, keyed by their index
     * @param  array<int, array<string, mixed>>  $rated
     * @param  list<int>  $paying
     * @return array<string, mixed>
     */
    private static function day(string $date, string $class, array $mine, array $rated, array $paying, DateTimeZone $tz): array
    {
        $notEmployed = $class === 'not_employed';
        $records = [];
        $overtime = [];
        $workedDay = 0;

        foreach ($mine as $i => $r) {
            $pays = in_array($i, $paying, true);
            $buckets = $pays ? $rated[$i]['overtime'] : [];
            foreach ($buckets as $bucket => $minutes) {
                $overtime[$bucket] = ($overtime[$bucket] ?? 0) + $minutes;
            }
            $workedDay += $rated[$i]['worked'];

            $records[] = [
                'status' => $rated[$i]['status'],
                'late' => $notEmployed ? new NoOpinion('not employed on this date') : $rated[$i]['late'],
                'early' => $notEmployed ? new NoOpinion('not employed on this date') : $rated[$i]['early'],
                'worked' => $rated[$i]['worked'],
                'check_in' => Calendar::format($r['in'], $tz, 'H:i:s'),
                'check_out' => $r['out'] === null ? null : Calendar::format($r['out'], $tz, 'H:i:s'),
                'breaks' => $r['breaks'],
                'metadata' => ['auto_checkout' => $r['auto']],
                'overtime' => $notEmployed ? new NoOpinion('not employed on this date') : $buckets,
            ];
        }

        $day = [
            'live' => count($mine),
            'class' => $class,
            'overtime' => $notEmployed ? new NoOpinion('not employed on this date') : $overtime,
            'worked_day' => $workedDay,
            'records' => $records,
        ];

        // The flat per-record keys describe one record; with none or several they say nothing.
        $flat = ['status', 'late', 'early', 'worked', 'check_in', 'check_out', 'breaks', 'metadata'];
        if (count($records) === 1) {
            foreach ($flat as $key) {
                $day[$key] = $records[0][$key];
            }
        } else {
            $why = new NoOpinion($records === [] ? 'no record on this date' : 'several records on this date: per-record keys need a records list');
            foreach ($flat as $key) {
                $day[$key] = $why;
            }
        }

        return $day;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<int, array<string, mixed>> keyed by index, earliest check-in first
     */
    private static function recordsOn(array $records, string $date): array
    {
        $mine = array_filter($records, static fn (array $r): bool => $r['wd'] === $date);
        uasort($mine, static fn (array $a, array $b): int => $a['in'] <=> $b['in']);

        return $mine;
    }

    /**
     * AR-OT-07: of records that overlap, only the longest pays overtime; how a
     * chain is grouped and what "longest" measures are AR-OT-14.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  array<int, array<string, mixed>>  $rated
     * @return list<int>
     */
    private static function payingRecords(array $records, array $rated, Readings $readings): array
    {
        $closed = array_keys(array_filter($records, static fn (array $r): bool => $r['out'] !== null));

        $measure = static fn (int $i): int => match ($readings->longest()) {
            'worked' => $rated[$i]['worked'],
            'overtime' => array_sum($rated[$i]['overtime']),
            default => $records[$i]['out'] - $records[$i]['in'],
        };
        $overlap = static fn (int $a, int $b): bool => $records[$a]['in'] < $records[$b]['out'] && $records[$b]['in'] < $records[$a]['out'];
        $better = static fn (int $a, int $b): bool => $measure($a) > $measure($b) || ($measure($a) === $measure($b) && $a < $b);

        $payers = [];
        if ($readings->flag('pairwise_overlap')) {
            $order = $closed;
            usort($order, static fn (int $a, int $b): int => $better($a, $b) ? -1 : 1);
            foreach ($order as $i) {
                $clash = array_filter($payers, static fn (int $p): bool => $overlap($i, $p));
                if ($clash === []) {
                    $payers[] = $i;
                }
            }

            return $payers;
        }

        $group = array_combine($closed, $closed);
        $root = static function (int $i) use (&$group): int {
            while ($group[$i] !== $i) {
                $i = $group[$i];
            }

            return $i;
        };
        foreach ($closed as $a) {
            foreach ($closed as $b) {
                if ($a < $b && $overlap($a, $b)) {
                    $group[$root($b)] = $root($a);
                }
            }
        }
        $best = [];
        foreach ($closed as $i) {
            $g = $root($i);
            if (! isset($best[$g]) || $better($i, $best[$g])) {
                $best[$g] = $i;
            }
        }

        return array_values($best);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function setup(array $raw): array
    {
        foreach (array_keys($raw) as $key) {
            if (! in_array($key, self::SETUP_KEYS, true)) {
                throw new InvalidArgumentException("unknown setup key: {$key}");
            }
        }

        if (array_key_exists('auto_checkout', $raw)) {
            $raw['auto_checkout'] = match (true) {
                $raw['auto_checkout'] === true => self::DEFAULT_AUTO_CHECKOUT_MINUTES,
                is_int($raw['auto_checkout']) && $raw['auto_checkout'] >= 0 => $raw['auto_checkout'],
                default => throw new InvalidArgumentException('auto_checkout must be true or a whole number of minutes'),
            };
        }
        foreach (['holiday', 'holiday_elsewhere', 'peer'] as $key) {
            if (isset($raw[$key]) && $raw[$key] !== true && $raw[$key] !== false) {
                Calendar::date((string) $raw[$key]);
            }
        }
        foreach (['hire_date', 'termination_date'] as $key) {
            if (isset($raw[$key])) {
                Calendar::date((string) $raw[$key]);
            }
        }
        if (isset($raw['leave'])) {
            if (! is_array($raw['leave']) || count($raw['leave']) !== 2) {
                throw new InvalidArgumentException('leave must be [from, to]');
            }
            Calendar::date((string) $raw['leave'][0]);
            Calendar::date((string) $raw['leave'][1]);
        }

        return $raw;
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
            'termination' => $setup['termination_date'] ?? null,
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
        if (! preg_match('/^(?:([+-]\d+) )?(\d{2}:\d{2}(?::\d{2})?)$/', $text, $m)) {
            throw new InvalidArgumentException("unreadable time: '{$text}'");
        }
        $offset = ($m[1] ?? '') === '' ? 0 : (int) $m[1];

        return [Calendar::at(Calendar::addDays($day, $offset), Calendar::clockSeconds($m[2]), $tz), ($m[1] ?? '') !== ''];
    }

    // -- readings --------------------------------------------------------

    /**
     * Paths of every leaf where the alternative answer differs from the baseline.
     *
     * @param  list<string|int>  $path
     * @param  list<list<string|int>>  $paths
     */
    private static function differences(mixed $base, mixed $alternative, array $path, array &$paths): void
    {
        if (self::isBranch($base, $path)) {
            foreach ($base as $key => $value) {
                if ($key === 'trace' || $key === 'meta') {
                    continue;
                }
                $other = is_array($alternative) && array_key_exists($key, $alternative) ? $alternative[$key] : new NoOpinion('absent in the alternative reading');
                self::differences($value, $other, [...$path, $key], $paths);
            }

            return;
        }

        // A field the register has no opinion on stays without one in every reading.
        if ($base instanceof NoOpinion) {
            return;
        }

        if (self::canonical($base) !== self::canonical($alternative)) {
            $paths[] = $path;
        }
    }

    /** A leaf is a scalar, an object, or an array holding no nested arrays; outcomes are leaves one by one. */
    private static function isBranch(mixed $value, array $path): bool
    {
        if (! is_array($value)) {
            return false;
        }
        if (end($path) === 'overtime' || end($path) === 'metadata' || end($path) === 'breaks') {
            return false;
        }
        if ($path === []) {
            return true;
        }
        if ($path === ['outcomes']) {
            return true;
        }
        foreach ($value as $item) {
            if (is_array($item)) {
                return true;
            }
        }

        return count($path) === 1; // `days` itself
    }

    private static function canonical(mixed $value): string
    {
        if (is_array($value) && ! array_is_list($value)) {
            ksort($value);
        }

        return serialize($value);
    }

    /** @param list<string|int> $path */
    private static function blank(array &$answer, array $path, string $rule): void
    {
        $node = &$answer;
        foreach ($path as $key) {
            $node = &$node[$key];
        }
        $node = $node instanceof Undecided
            ? new Undecided(array_values(array_unique([...$node->rules, $rule])), $node->literal)
            : new Undecided([$rule], $node);
    }

    /** @param array<string, true> $rules */
    private static function collect(mixed $value, array &$rules): void
    {
        if ($value instanceof Undecided) {
            foreach ($value->rules as $rule) {
                $rules[$rule] = true;
            }

            return;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ($key !== 'trace') {
                    self::collect($item, $rules);
                }
            }
        }
    }
}
