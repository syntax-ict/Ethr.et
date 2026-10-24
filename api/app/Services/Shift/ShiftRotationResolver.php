<?php

declare(strict_types=1);

namespace App\Services\Shift;

use App\Models\Shift;
use App\Models\ShiftRotation;
use App\Models\ShiftRotationStep;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Answers "which shift does this rotation put someone on, on this date?".
 *
 * Kept free of Eloquent lookups on purpose: it takes a loaded rotation and
 * returns a shift, so it can be unit-tested over a whole cycle without touching
 * the database, and so a caller resolving a month of dates loads the steps once
 * rather than per day.
 */
final class ShiftRotationResolver
{
    /**
     * The shift worked on $date, or null for a rest day.
     *
     * Dates before the anchor resolve too: PHP's % returns a negative remainder
     * for negative operands, which would index outside the cycle, so the result
     * is shifted back into range. Without that, backdating an assignment — or
     * viewing a roster for a month before it started — silently returns no
     * shift instead of the correct one.
     */
    public function shiftFor(ShiftRotation $rotation, CarbonInterface $anchor, CarbonInterface $date): ?Shift
    {
        /** @var Shift|null $shift */
        $shift = $this->stepFor($rotation, $anchor, $date)?->shift;

        return $shift;
    }

    public function offsetFor(ShiftRotation $rotation, CarbonInterface $anchor, CarbonInterface $date): int
    {
        $cycle = $rotation->cycle_days;

        if ($cycle < 1) {
            return 0;
        }

        // Calendar dates on both sides, read in one zone: a rotation is a
        // whole-day concept. startOfDay alone was not enough. The anchor is a
        // date cast (UTC midnight) and the engine passes the punch in the
        // tenant's zone, whose midnight in Addis Ababa is 21:00 UTC the day
        // before, so the gap came out as 3.875 days, truncated to 3, while
        // payroll (passing UTC dates) got 4: one day apart on the same date.
        $from = Carbon::parse($anchor->format('Y-m-d'), 'UTC');
        $to = Carbon::parse($date->format('Y-m-d'), 'UTC');
        $days = (int) round($from->diffInDays($to, false));

        return (($days % $cycle) + $cycle) % $cycle;
    }

    /**
     * The step at $date's position in the cycle, or null when the rotation has
     * no step defined there. A rotation is not required to define every offset;
     * an undefined one is treated as a rest day rather than an error, so a
     * half-configured pattern degrades to "not working" instead of throwing
     * during payroll or attendance matching.
     */
    public function stepFor(ShiftRotation $rotation, CarbonInterface $anchor, CarbonInterface $date): ?ShiftRotationStep
    {
        $offset = $this->offsetFor($rotation, $anchor, $date);

        /** @var ShiftRotationStep|null $step */
        $step = $rotation->steps->firstWhere('day_offset', $offset);

        return $step;
    }

    /**
     * The resolved pattern across a date range, keyed by Y-m-d.
     *
     * @return array<string, ?Shift>
     */
    public function scheduleFor(
        ShiftRotation $rotation,
        CarbonInterface $anchor,
        CarbonInterface $from,
        CarbonInterface $to,
    ): array {
        $schedule = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $schedule[$cursor->format('Y-m-d')] = $this->shiftFor($rotation, $anchor, $cursor);
            $cursor = $cursor->addDay();
        }

        return $schedule;
    }
}
