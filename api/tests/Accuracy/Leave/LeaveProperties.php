<?php

declare(strict_types=1);

namespace Tests\Accuracy\Leave;

use App\Services\Leave\LeaveDayCalculator;
use Carbon\Carbon;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

/**
 * Inputs and checks for the leave properties. Every property runs over a small
 * table of inputs generated from one fixed seed, so a failure is the same
 * failure on the next run, and each reports its seed, the number of cases and
 * the number of disagreements. A property that fails is never weakened: the
 * smallest failing input is reported as it is.
 */
final class LeaveProperties
{
    public const SEED = 20261128;

    /** Three ways a person's working days are fixed: the organisation week, a six-day shift, a four-day rotation. */
    private const SCENARIOS = [
        'monday to friday' => [],
        'six-day shift' => ['shift' => 'S6'],
        'four-day rotation' => ['rotation' => ['anchor' => '2026-06-01', 'steps' => ['S1', 'S1', null, null]]],
    ];

    private static function random(string $property): Randomizer
    {
        return new Randomizer(new Mt19937(self::SEED + crc32($property) % 100000));
    }

    /**
     * @param  array<string, mixed>  $employee
     * @param  list<array<string, mixed>>  $holidays
     * @return array<string, mixed>
     */
    private static function world(TestCase $t, array $employee, array $holidays = []): array
    {
        return LeaveFixture::build(LeaveCases::synthetic(['employee' => $employee, 'org' => ['holidays' => $holidays]]), $t);
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return list<string>
     */
    private static function charged(array $ctx, string $from, string $to): array
    {
        $employee = $ctx['employee'];

        return app(LeaveDayCalculator::class)->chargedDates(Carbon::parse($from), Carbon::parse($to), $ctx['tenant']->id, $employee->branch_id, $employee);
    }

    /**
     * A span inside 2026-03-01 to 2026-09-30: [from, to, split point].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function span(Randomizer $r): array
    {
        $base = Carbon::parse('2026-03-01');
        $from = $base->copy()->addDays($r->getInt(0, 150));
        $length = $r->getInt(2, 45);
        $to = $from->copy()->addDays($length - 1);
        $split = $from->copy()->addDays($r->getInt(0, $length - 2));

        return [$from->format('Y-m-d'), $to->format('Y-m-d'), $split->format('Y-m-d')];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function holidays(Randomizer $r): array
    {
        $holidays = [];
        foreach (range(1, $r->getInt(0, 3)) as $i) {
            $holidays[] = ['date' => Carbon::parse('2026-03-01')->addDays($r->getInt(0, 200))->format('Y-m-d'), 'branch' => $r->getInt(0, 1) === 1 ? 'own' : null];
        }

        return $holidays;
    }

    /**
     * Property 1 and 5. Splitting a request into two adjacent ones does not
     * change the total charged; days charged are never negative and never
     * more than the calendar days of the span.
     *
     * @return array{cases: int, failures: list<array{size: int, text: string}>}
     */
    public static function splitting(TestCase $t): array
    {
        $r = self::random('splitting');
        $cases = 0;
        $failures = [];

        foreach (self::SCENARIOS as $name => $employee) {
            $ctx = self::world($t, $employee, self::holidays($r));
            foreach (range(1, 20) as $i) {
                [$from, $to, $split] = self::span($r);
                $next = Carbon::parse($split)->addDay()->format('Y-m-d');
                $whole = count(self::charged($ctx, $from, $to));
                $left = count(self::charged($ctx, $from, $split));
                $right = count(self::charged($ctx, $next, $to));
                $calendar = (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
                $cases++;

                $size = $calendar;
                if ($whole !== $left + $right) {
                    $failures[] = ['size' => $size, 'text' => "{$name}: {$from} to {$to} charges {$whole}, split after {$split} it charges {$left} + {$right}"];
                }
                if ($whole < 0 || $whole > $calendar) {
                    $failures[] = ['size' => $size, 'text' => "{$name}: {$from} to {$to} charges {$whole} of {$calendar} calendar days"];
                }
            }
        }

        return compact('cases', 'failures');
    }

    /**
     * Property 2. Adding a holiday inside a request never raises the days
     * charged, and lowers them by exactly one when that date was charged.
     *
     * @return array{cases: int, failures: list<array{size: int, text: string}>}
     */
    public static function holidaysNeverRaise(TestCase $t): array
    {
        $r = self::random('holidays');
        $cases = 0;
        $failures = [];

        foreach (self::SCENARIOS as $name => $employee) {
            $ctx = self::world($t, $employee);
            foreach (range(1, 15) as $i) {
                [$from, $to] = self::span($r);
                $date = Carbon::parse($from)->addDays($r->getInt(0, (int) Carbon::parse($from)->diffInDays(Carbon::parse($to))))->format('Y-m-d');
                $before = self::charged($ctx, $from, $to);
                LeaveFixture::act($ctx, ['do' => 'add_holiday', 'date' => $date]);
                $after = self::charged($ctx, $from, $to);
                $cases++;

                $expected = in_array($date, $before, true) ? count($before) - 1 : count($before);
                if (count($after) > count($before) || count($after) !== $expected) {
                    $failures[] = ['size' => count($before), 'text' => "{$name}: {$from} to {$to} charged ".count($before).", after a holiday on {$date} it charges ".count($after).", expected {$expected}"];
                }
            }
        }

        return compact('cases', 'failures');
    }

    /**
     * Property 3. Accrual for a later month of the year is never lower than
     * for an earlier one, whatever the type's days, the hire date, and a
     * termination date recorded part-way through.
     *
     * @return array{cases: int, failures: list<array{size: int, text: string}>}
     */
    public static function accrualNeverFalls(TestCase $t): array
    {
        $r = self::random('accrual');
        $cases = 0;
        $failures = [];

        foreach (range(1, 10) as $i) {
            $days = $r->getInt(8, 30);
            $hire = Carbon::parse('2025-01-01')->addDays($r->getInt(0, 600))->format('Y-m-d');
            $leaveYear = $r->getInt(0, 1) === 1 ? 'ethiopian' : 'gregorian';
            $terminateAfter = $r->getInt(0, 1) === 1 ? $r->getInt(2, 9) : null;
            $ctx = LeaveFixture::build(LeaveCases::synthetic([
                'type' => ['default_days' => $days],
                'employee' => ['hire_date' => $hire],
                'org' => ['leave_year' => $leaveYear],
            ]), $t);

            $series = [];
            foreach (range(1, 12) as $month) {
                $at = $leaveYear === 'ethiopian'
                    ? Carbon::parse('2025-09-11')->addDays(($month - 1) * 30)
                    : Carbon::create(2026, $month, 1);
                if ($terminateAfter === $month) {
                    $ctx['employee']->update(['termination_date' => $at->copy()->subMonths(3)->format('Y-m-d')]);
                }
                LeaveFixture::act($ctx, ['do' => 'accrue', 'at' => $at->format('Y-m-d').' 00:30']);
                $series[$at->format('Y-m-d')] = self::entitledAcrossYears($ctx);
            }

            $cases++;
            $previous = -1;
            foreach ($series as $date => $entitled) {
                if ($entitled < $previous) {
                    $failures[] = ['size' => $days, 'text' => "{$leaveYear} year, {$days} days, hired {$hire}, termination after month {$terminateAfter}: entitled fell from {$previous} to {$entitled} tenths on {$date}"];
                }
                $previous = $entitled;
            }
        }

        return compact('cases', 'failures');
    }

    /**
     * The entitled tenths of the leave year the clock is in.
     *
     * @param  array<string, mixed>  $ctx
     */
    private static function entitledAcrossYears(array $ctx): int
    {
        $year = LeaveFixture::leaveYear($ctx)->current();

        return LeaveFixture::balance($ctx, $year)['entitled_tenths'];
    }

    /**
     * Property 4. Running accrual twice for the same moment, and carry-forward
     * twice, change nothing.
     *
     * @return array{cases: int, failures: list<array{size: int, text: string}>}
     */
    public static function repeatsChangeNothing(TestCase $t): array
    {
        $r = self::random('repeat');
        $cases = 0;
        $failures = [];

        foreach (range(1, 8) as $i) {
            $days = $r->getInt(8, 30);
            $month = $r->getInt(1, 12);
            $entitled = $r->getInt(100, 300);
            $used = $r->getInt(0, $entitled);
            $cap = $r->getInt(0, 1) === 1 ? $r->getInt(1, 20) : null;
            $ctx = LeaveFixture::build(LeaveCases::synthetic([
                'type' => ['default_days' => $days, 'carry_forward' => true, 'max_carry_days' => $cap],
                'balances' => [['year' => 2025, 'entitled_tenths' => $entitled, 'used_tenths' => $used, 'carried_tenths' => 0, 'pending_tenths' => 0]],
            ]), $t);
            $cases++;

            $accrue = ['do' => 'accrue', 'at' => Carbon::create(2026, $month, 1)->format('Y-m-d').' 00:30'];
            LeaveFixture::act($ctx, $accrue);
            $once = LeaveFixture::balance($ctx, 2026);
            LeaveFixture::act($ctx, $accrue);
            if (LeaveFixture::balance($ctx, 2026) !== $once) {
                $failures[] = ['size' => $days, 'text' => "accrual of {$days} days twice on month {$month}: ".json_encode($once).' then '.json_encode(LeaveFixture::balance($ctx, 2026))];
            }

            $carry = ['do' => 'carry', 'from' => 2025, 'to' => 2026, 'at' => '2026-01-02 01:00'];
            LeaveFixture::act($ctx, $carry);
            $first = LeaveFixture::balance($ctx, 2026);
            LeaveFixture::act($ctx, $carry);
            if (LeaveFixture::balance($ctx, 2026) !== $first) {
                $failures[] = ['size' => $entitled, 'text' => "carry-forward twice (entitled {$entitled}, used {$used}, cap ".var_export($cap, true).'): '.json_encode($first).' then '.json_encode(LeaveFixture::balance($ctx, 2026))];
            }
        }

        return compact('cases', 'failures');
    }

    /**
     * What a property run says, in the one line the output carries.
     *
     * @param  array{cases: int, failures: list<array{size: int, text: string}>}  $result
     */
    public static function line(string $name, array $result): string
    {
        return sprintf('LEAVE PROPERTY %s: seed=%d cases=%d disagreements=%d', $name, self::SEED, $result['cases'], count($result['failures']));
    }

    /**
     * The smallest failing input, or an empty string.
     *
     * @param  array{cases: int, failures: list<array{size: int, text: string}>}  $result
     */
    public static function smallest(array $result): string
    {
        $failures = $result['failures'];
        usort($failures, fn (array $a, array $b): int => $a['size'] <=> $b['size']);

        return $failures === [] ? '' : 'smallest failing input: '.$failures[0]['text'];
    }
}
