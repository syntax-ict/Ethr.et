<?php

declare(strict_types=1);

namespace App\Services\Shift;

use App\Models\Shift;
use App\Models\ShiftRotation;
use App\Models\ShiftRotationStep;
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

        // startOfDay on both sides: a rotation is a whole-day concept, and
        // diffInDays between timestamps hours apart would otherwise round to a
        // different offset depending on the time of day the query ran.
        $days = (int) $anchor->copy()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);

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
