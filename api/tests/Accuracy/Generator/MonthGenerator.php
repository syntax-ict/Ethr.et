<?php

declare(strict_types=1);

namespace Tests\Accuracy\Generator;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * One employee-month of punches, from a seed, in the golden-case format and
 * with no `expect`.
 *
 * Seeded and deterministic: every draw comes from one Randomizer over
 * Mt19937($seed), and nothing reads a clock. The same seed gives the same case
 * on any machine, so a month that disagrees replays alone from its seed.
 *
 * The generator implements no rule. It draws a plausible month (behaviours,
 * methods, holidays, leave, a hire or leaving date, automatic check-out) and
 * writes the punches in the order they would arrive. What the right answer is
 * belongs to the oracle and the register, never to this file.
 *
 * Moments are held as seconds from midnight of the month's first day in the
 * organisation's zone and written as `+N HH:MM[:SS]`, the golden-case form.
 */
final class MonthGenerator
{
    private const DAY = 86400;

    private const METHODS = ['web', 'mobile', 'kiosk', 'qr', 'biometric'];

    private Randomizer $rng;

    /** @var array<string, array<string, mixed>> */
    private array $shifts;

    private function __construct(int $seed)
    {
        $this->rng = new Randomizer(new Mt19937($seed));
        $this->shifts = require __DIR__.'/../fixtures/shifts.php';
    }

    /** @return array<string, mixed> */
    public static function case(int $seed): array
    {
        return (new self($seed))->build($seed);
    }

    /** @return array<string, mixed> */
    private function build(int $seed): array
    {
        // The month: October 2025 to December 2026.
        $first = (new DateTimeImmutable('2025-10-01', new DateTimeZone('UTC')))->modify('+'.$this->int(0, 14).' months');
        $days = (int) $first->format('t');
        $date = fn (int $i): string => $first->modify("+{$i} days")->format('Y-m-d');
        $weekday = fn (int $i): int => (int) $first->modify("+{$i} days")->format('N');

        $shiftName = $this->weighted(['S1' => 38, 'S3' => 30, 'S2' => 28, 'none' => 4]);
        $zone = $this->weighted(['Africa/Addis_Ababa' => 80, 'Asia/Dubai' => 10, 'America/Bogota' => 10]);
        $shift = $shiftName === 'none' ? null : $this->shifts[$shiftName];
        $night = $shift !== null && $shift['crosses_midnight'];

        $start = $shift === null ? 480 : $this->minutes($shift['start_time']);
        $end = $shift === null ? 1020 : $this->minutes($shift['end_time']) + ($night ? 1440 : 0);
        $grace = $shift['grace_minutes'] ?? 15;
        $early = $shift['early_departure_minutes'] ?? 15;
        $working = array_map('intval', explode(',', $shift['working_days'] ?? '1,2,3,4,5'));

        // The month's set-up.
        $setup = [];
        $holiday = null;
        if ($this->chance(0.35)) {
            $holiday = $this->int(0, $days - 1);
            $setup['holiday'] = $date($holiday);
        }
        if ($this->chance(0.15)) {
            $setup['holiday_elsewhere'] = $date($this->int(0, $days - 1));
        }
        $leave = null;
        if ($this->chance(0.25)) {
            $from = $this->int(1, $days - 5);
            $leave = [$from, $from + $this->int(0, 3)];
            $setup['leave'] = [$date($leave[0]), $date($leave[1])];
        }
        $hire = null;
        $termination = null;
        if ($this->chance(0.08)) {
            $hire = $this->int(3, $days - 8);
            $setup['hire_date'] = $date($hire);
        }
        if ($this->chance(0.08)) {
            $termination = $this->int(($hire ?? 0) + 4, $days - 3);
            $setup['termination_date'] = $date($termination);
        }
        $autoMinutes = $this->chance(0.35) ? $this->pick([60, 120, 180]) : null;
        if ($autoMinutes !== null) {
            $setup['auto_checkout'] = $autoMinutes;
        }

        // Always one working day on which the organisation records attendance.
        $peer = 0;
        for ($i = 0; $i < $days; $i++) {
            if (in_array($weekday($i), $working, true) && $i !== $holiday) {
                $peer = $i;

                break;
            }
        }
        $setup['peer'] = $date($peer);

        // Each day's behaviour.
        $events = [];
        $order = 0;
        for ($i = 0; $i < $days; $i++) {
            $employed = ($hire === null || $i >= $hire) && ($termination === null || $i <= $termination);
            $normal = $employed && in_array($weekday($i), $working, true) && $i !== $holiday
                && ! ($leave !== null && $i >= $leave[0] && $i <= $leave[1]);

            if (! $employed) {
                $behaviour = $this->chance(0.03) ? 'on_time' : 'none';
            } elseif ($normal) {
                $behaviour = $this->weighted([
                    'on_time' => 40, 'late' => 14, 'early_leave' => 7, 'late_early' => 4, 'overtime' => 8,
                    'forgot_out' => 4, 'lunch' => 7, 'second_session' => 3, 'double_tap' => 4,
                    'auto' => 3, 'manual' => 3, 'absent' => 3,
                ]);
            } else {
                $behaviour = $this->chance(0.08) ? $this->pick(['on_time', 'overtime']) : 'none';
            }

            foreach ($this->day($i, $behaviour, $start, $end, $grace, $early, $night) as $punch) {
                // A manual entry arrives when it is entered: at its out time.
                $arrives = $punch['type'] === 'manual' ? $punch['out'] : $punch['at'];
                $events[] = [$arrives, $order++, $punch];
            }
        }

        usort($events, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $punches = [];
        foreach ($events as [, , $p]) {
            $punches[] = $this->write($p, $first);
        }

        $case = [
            'id' => "GEN-{$seed}",
            'rule' => ['GENERATED'],
            'source' => 'generated',
            'zone' => $zone,
            'day' => $first->format('Y-m-d'),
            'setup' => $setup,
            'punches' => $punches,
        ];
        if ($shiftName !== 'none') {
            $case['shift'] = $shiftName;
        }

        // The month is read on the first day of the next one at noon, or, less
        // often, at its last punch. Automatic check-out runs once, then.
        $readAt = "+{$days} 12:00";
        if ($autoMinutes !== null) {
            $case['auto_run_at'] = $readAt;
        } elseif ($this->chance(0.7)) {
            $case['now'] = $readAt;
        }

        return $case;
    }

    /**
     * @return list<array<string, mixed>> punches with moments in seconds from the month's start
     */
    private function day(int $i, string $behaviour, int $start, int $end, int $grace, int $early, bool $night): array
    {
        if ($behaviour === 'none' || $behaviour === 'absent') {
            return [];
        }

        $method = $this->pick(self::METHODS);
        $m = fn (): string => $this->chance(0.8) ? $method : $this->pick(self::METHODS);
        $at = fn (int $minute): int => $i * self::DAY + $minute * 60 + $this->seconds();
        $in = fn (int $minute, string $by): array => ['type' => 'in', 'at' => $at($minute), 'method' => $by];
        $out = fn (int $minute, string $by): array => ['type' => 'out', 'at' => $at($minute), 'method' => $by];

        $arrive = $this->int($start - 45, $start + $grace);
        $leave = $this->int($end - $early, $end + 25);
        $lateIn = $start + $grace + $this->int(1, $night ? 150 : 120);
        $latest = $night ? $end + 180 : 1439;

        switch ($behaviour) {
            case 'on_time':
                return [$in($arrive, $m()), $out(max($leave, $arrive + 30), $m())];

            case 'late':
                return [$in($lateIn, $m()), $out(max($leave, $lateIn + 30), $m())];

            case 'early_leave':
                $cut = $end - $early - $this->int(1, 180);

                return [$in($arrive, $m()), $out(max($cut, $arrive + 30), $m())];

            case 'late_early':
                $cut = $end - $early - $this->int(1, 180);

                return [$in($lateIn, $m()), $out(max($cut, $lateIn + 30), $m())];

            case 'overtime':
                $extra = $this->chance(0.5) ? $this->int(20, 150) : $this->int(20, max(20, $latest - $end));

                return [$in($arrive, $m()), $out(min($end + $extra, $latest), $m())];

            case 'forgot_out':
                return [$in($this->chance(0.3) ? $lateIn : $arrive, $m())];

            case 'lunch':
                $gone = $arrive + $this->int(150, 300);
                $back = $gone + $this->int(20, 90);

                return [
                    $in($arrive, $m()), $out($gone, $m()), $in($back, $m()),
                    $out(max($leave, $back + 30), $m()),
                ];

            case 'second_session':
                $first = $in($arrive, $m());
                $closed = $out(max($leave, $arrive + 30), $m());
                $again = intdiv($closed['at'] - $i * self::DAY, 60) + $this->int(10, 120);
                $over = $again + $this->int(20, 180);
                if ($over > $latest) {
                    return [$first, $closed];
                }

                return [$first, $closed, $in($again, $m()), $out($over, $m())];

            case 'double_tap':
                $a = $in($arrive, $method);
                $variant = $this->int(1, 3);
                if ($variant === 1) {
                    // Same method, under a minute: a repeat.
                    $b = ['type' => 'in', 'at' => $a['at'] + $this->int(2, 59), 'method' => $method];
                    $rest = [$out(max($leave, $arrive + 30), $method)];
                } elseif ($variant === 2) {
                    // Another method, one to six minutes on: one arrival, or a second tap.
                    $other = $this->pick(array_values(array_diff(self::METHODS, [$method])));
                    $b = ['type' => 'in', 'at' => $a['at'] + $this->int(60, 360), 'method' => $other];
                    $rest = [$out(max($leave, $arrive + 30), $method)];
                } else {
                    // A check-out tapped twice within five minutes.
                    $b = $out(max($leave, $arrive + 30), $method);
                    $rest = [['type' => 'out', 'at' => $b['at'] + $this->int(20, 330), 'method' => $this->pick(self::METHODS)]];
                }

                return [$a, $b, ...$rest];

            case 'auto':
                $by = $this->pick(['kiosk', 'biometric']);

                return [
                    ['type' => 'auto', 'at' => $at($arrive), 'method' => $by],
                    ['type' => 'auto', 'at' => $at(max($leave, $arrive + 30)), 'method' => $by],
                ];

            case 'manual':
                $from = $start + $this->int(-10, 90);
                $to = $end + $this->int(-30, 60);

                return [[
                    'type' => 'manual',
                    'date' => $i,
                    'in' => $i * self::DAY + $from * 60 + $this->seconds(),
                    'out' => $i * self::DAY + max($to, $from + 60) * 60 + $this->seconds(),
                    'method' => $this->pick(['manual', 'csv']),
                ]];
        }

        throw new InvalidArgumentException("unknown behaviour: {$behaviour}");
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function write(array $p, DateTimeImmutable $first): array
    {
        if ($p['type'] === 'manual') {
            return [
                'type' => 'manual',
                'date' => $first->modify("+{$p['date']} days")->format('Y-m-d'),
                'in' => $this->text($p['in']),
                'out' => $this->text($p['out']),
                'method' => $p['method'],
            ];
        }

        return ['type' => $p['type'], 'at' => $this->text($p['at']), 'method' => $p['method']];
    }

    /** `08:40`, `08:45:30` or `+3 17:30`, from seconds since the month's first midnight. */
    private function text(int $seconds): string
    {
        $offset = intdiv($seconds, self::DAY);
        $rest = $seconds % self::DAY;
        $clock = sprintf('%02d:%02d', intdiv($rest, 3600), intdiv($rest % 3600, 60));
        if ($rest % 60 !== 0) {
            $clock .= sprintf(':%02d', $rest % 60);
        }

        return $offset > 0 ? "+{$offset} {$clock}" : $clock;
    }

    private function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    /** To the second a quarter of the time, to the minute otherwise. */
    private function seconds(): int
    {
        return $this->chance(0.25) ? $this->int(1, 59) : 0;
    }

    private function int(int $min, int $max): int
    {
        return $this->rng->getInt($min, max($min, $max));
    }

    private function chance(float $p): bool
    {
        return $this->rng->getInt(1, 10000) <= (int) round($p * 10000);
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[$this->rng->getInt(0, count($items) - 1)];
    }

    /** @param array<string, int> $weights */
    private function weighted(array $weights): string
    {
        $roll = $this->rng->getInt(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }
}
