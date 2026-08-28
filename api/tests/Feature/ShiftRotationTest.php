<?php

declare(strict_types=1);

use App\Models\Shift;
use App\Models\ShiftRotation;
use App\Models\ShiftRotationStep;
use App\Services\Shift\ShiftRotationResolver;
use Carbon\Carbon;

/**
 * Roadmap 3.1. `shifts.working_days` can only express a pattern that is the same
 * every week, so multi-week rotations were impossible — a hard requirement for
 * hospitals, manufacturing, security and hotels.
 */

/** Build a rotation whose steps cycle through the given shifts, null = rest. */
function rotationWith(int $tenantId, array $shiftsByOffset, int $cycleDays): ShiftRotation
{
    $rotation = ShiftRotation::factory()->create([
        'tenant_id' => $tenantId,
        'cycle_days' => $cycleDays,
    ]);

    foreach ($shiftsByOffset as $offset => $shift) {
        ShiftRotationStep::factory()->create([
            'tenant_id' => $tenantId,
            'shift_rotation_id' => $rotation->id,
            'day_offset' => $offset,
            'shift_id' => $shift?->id,
        ]);
    }

    return $rotation->load('steps.shift');
}

describe('rotation resolution', function () {
    it('cycles through the pattern and repeats after the cycle length', function () {
        $tenant = createTenant();
        $morning = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Morning']);
        $night = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Night']);

        $rotation = rotationWith($tenant->id, [0 => $morning, 1 => $night], 2);
        $resolver = new ShiftRotationResolver;
        $anchor = Carbon::parse('2026-01-01');

        expect($resolver->shiftFor($rotation, $anchor, Carbon::parse('2026-01-01'))?->name)->toBe('Morning');
        expect($resolver->shiftFor($rotation, $anchor, Carbon::parse('2026-01-02'))?->name)->toBe('Night');
        // Wraps.
        expect($resolver->shiftFor($rotation, $anchor, Carbon::parse('2026-01-03'))?->name)->toBe('Morning');
        expect($resolver->shiftFor($rotation, $anchor, Carbon::parse('2026-01-04'))?->name)->toBe('Night');
    });

    it('treats a step with no shift as a rest day', function () {
        $tenant = createTenant();
        $morning = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Morning']);

        $rotation = rotationWith($tenant->id, [0 => $morning, 1 => null], 2);
        $resolver = new ShiftRotationResolver;
        $anchor = Carbon::parse('2026-01-01');

        expect($resolver->shiftFor($rotation, $anchor, Carbon::parse('2026-01-02')))->toBeNull();
        // And it is a defined rest day, not a gap in the pattern.
        expect($resolver->stepFor($rotation, $anchor, Carbon::parse('2026-01-02'))?->isRestDay())->toBeTrue();
    });

    it('expresses a four-on-four-off cycle that never aligns to a week', function () {
        // The case a week-based model cannot represent at all, and the reason
        // the cycle is measured in days.
        $tenant = createTenant();
        $day = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Day']);

        $rotation = rotationWith($tenant->id, [
            0 => $day, 1 => $day, 2 => $day, 3 => $day,
            4 => null, 5 => null, 6 => null, 7 => null,
        ], 8);
        $resolver = new ShiftRotationResolver;
        $anchor = Carbon::parse('2026-01-01');

        $worked = [];
        for ($i = 0; $i < 16; $i++) {
            $date = $anchor->copy()->addDays($i);
            $worked[] = $resolver->shiftFor($rotation, $anchor, $date) !== null;
        }

        expect($worked)->toBe([
            true, true, true, true, false, false, false, false,
            true, true, true, true, false, false, false, false,
        ]);
    });

    it('resolves dates before the anchor', function () {
        // PHP's % yields a negative remainder for negative operands, which would
        // index outside the cycle. Backdating an assignment, or viewing a roster
        // for a month before it started, must still resolve correctly.
        $tenant = createTenant();
        $morning = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Morning']);
        $night = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Night']);

        $rotation = rotationWith($tenant->id, [0 => $morning, 1 => $night], 2);
        $resolver = new ShiftRotationResolver;
        $anchor = Carbon::parse('2026-01-10');

        expect($resolver->offsetFor($rotation, $anchor, Carbon::parse('2026-01-09')))->toBe(1);
        expect($resolver->shiftFor($rotation, $anchor, Carbon::parse('2026-01-09'))?->name)->toBe('Night');
        expect($resolver->shiftFor($rotation, $anchor, Carbon::parse('2026-01-08'))?->name)->toBe('Morning');
    });

    it('resolves the same offset regardless of the time of day', function () {
        // Both sides are normalized to start-of-day; without that, a query run
        // in the evening could land on a different offset than the same query
        // run that morning.
        $tenant = createTenant();
        $morning = Shift::factory()->create(['tenant_id' => $tenant->id]);
        $rotation = rotationWith($tenant->id, [0 => $morning, 1 => null], 2);
        $resolver = new ShiftRotationResolver;

        $anchor = Carbon::parse('2026-01-01 06:00');

        expect($resolver->offsetFor($rotation, $anchor, Carbon::parse('2026-01-02 23:59')))->toBe(1);
        expect($resolver->offsetFor($rotation, $anchor, Carbon::parse('2026-01-02 00:01')))->toBe(1);
    });

    it('treats an undefined offset as a rest day rather than throwing', function () {
        // A half-configured rotation must degrade to "not working", not blow up
        // inside attendance matching or payroll.
        $tenant = createTenant();
        $morning = Shift::factory()->create(['tenant_id' => $tenant->id]);

        $rotation = rotationWith($tenant->id, [0 => $morning], 3);
        $resolver = new ShiftRotationResolver;
        $anchor = Carbon::parse('2026-01-01');

        expect($resolver->shiftFor($rotation, $anchor, Carbon::parse('2026-01-02')))->toBeNull();
        expect($resolver->stepFor($rotation, $anchor, Carbon::parse('2026-01-02')))->toBeNull();
    });

    it('builds a schedule across a date range', function () {
        $tenant = createTenant();
        $morning = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Morning']);
        $rotation = rotationWith($tenant->id, [0 => $morning, 1 => null], 2);
        $resolver = new ShiftRotationResolver;
        $anchor = Carbon::parse('2026-03-01');

        $schedule = $resolver->scheduleFor(
            $rotation,
            $anchor,
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-05'),
        );

        expect(array_keys($schedule))->toBe([
            '2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05',
        ]);
        expect($schedule['2026-03-01']?->name)->toBe('Morning');
        expect($schedule['2026-03-02'])->toBeNull();
        expect($schedule['2026-03-03']?->name)->toBe('Morning');
    });

    it('spans a Gregorian month boundary without drifting', function () {
        // The cycle counts elapsed days, so uneven month lengths must not shift
        // the pattern — a leap-year February is the sharpest version of this.
        $tenant = createTenant();
        $morning = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Morning']);
        $rotation = rotationWith($tenant->id, [0 => $morning, 1 => null], 2);
        $resolver = new ShiftRotationResolver;
        $anchor = Carbon::parse('2024-02-01');

        // 2024 is a leap year: 29 days in February, so 1 March is offset 29 % 2 = 1.
        expect($resolver->offsetFor($rotation, $anchor, Carbon::parse('2024-02-29')))->toBe(0);
        expect($resolver->offsetFor($rotation, $anchor, Carbon::parse('2024-03-01')))->toBe(1);
    });
});
