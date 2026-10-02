<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Services\Shift\ShiftRotationResolver;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which shift an employee is scheduled on for a date.
 *
 * Assignments are looked for on the employee, then the department, then the
 * branch — the first level with one in force decides, and the most recent
 * `effective_from` wins within a level. With none, the tenant's default shift.
 *
 * An assignment holds a fixed shift or a rotation. Until 2026-10-02 only the
 * fixed kind was matched: the query required a loadable `shift`, so a rotation
 * assignment was invisible and the employee fell through to a department,
 * branch or default shift — or to none. Rotation-scheduled staff got no
 * lateness, early-leave or overtime against the rotation's day, while the
 * rotation preview (which uses the same `ShiftRotationResolver`) showed the
 * schedule they were supposedly on.
 */
final class ShiftMatcher
{
    private const LEVELS = [Employee::class, Department::class, Branch::class];

    public function __construct(
        private readonly ShiftRotationResolver $rotations,
    ) {}

    /**
     * The shift worked on $date, or null when none applies — including a rest
     * day in a rotation. A rotation's rest day is its answer, not a gap: it
     * does not fall through to a department, branch or default shift.
     */
    public function match(Employee $employee, Carbon $date): ?Shift
    {
        $day = $date->format('Y-m-d');
        $assignment = $this->governing($this->candidates($employee, $day, $day), $day);

        if ($assignment === null) {
            return $this->findDefaultShift($employee);
        }

        return $assignment->isRotation()
            ? $this->rotationShift($assignment, $date)
            : $assignment->shift;
    }

    /**
     * For each date in the range that a rotation governs, whether the rotation
     * makes it a rest day. Dates governed by a fixed shift, or by nothing, are
     * absent: their rest day is the shift's `working_days`, which the caller
     * already reads from the attendance record.
     *
     * Payroll asks this once per employee per period, so the assignments are
     * loaded once (three queries) and every day is resolved in memory.
     *
     * @return array<string, bool> keyed by Y-m-d
     */
    public function rotationRestDays(Employee $employee, CarbonInterface $from, CarbonInterface $to): array
    {
        $candidates = $this->candidates($employee, $from->format('Y-m-d'), $to->format('Y-m-d'));

        if ($candidates->every(fn (ShiftAssignment $a): bool => ! $a->isRotation())) {
            return [];
        }

        $restDays = [];
        $cursor = Carbon::parse($from->format('Y-m-d'));
        $end = Carbon::parse($to->format('Y-m-d'));

        while ($cursor->lessThanOrEqualTo($end)) {
            $day = $cursor->format('Y-m-d');
            $assignment = $this->governing($candidates, $day);

            if ($assignment !== null && $assignment->isRotation()) {
                $restDays[$day] = $this->rotationShift($assignment, $cursor) === null;
            }

            $cursor = $cursor->addDay();
        }

        return $restDays;
    }

    /**
     * Every assignment on the employee, their department or their branch that
     * is in force at some point in [$from, $to] and still points at something
     * usable: an active shift, or an active rotation.
     *
     * @return Collection<int, ShiftAssignment>
     */
    private function candidates(Employee $employee, string $from, string $to): Collection
    {
        $owners = array_filter([
            Employee::class => $employee->id,
            Department::class => $employee->department_id,
            Branch::class => $employee->branch_id,
        ]);

        return ShiftAssignment::query()
            ->where(function ($query) use ($owners) {
                foreach ($owners as $type => $id) {
                    $query->orWhere(fn ($q) => $q->where('assignable_type', $type)->where('assignable_id', $id));
                }
            })
            ->whereDate('effective_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
            ->where(fn ($q) => $q
                ->whereHas('shift', fn ($s) => $s->where('is_active', true))
                ->orWhereHas('rotation', fn ($r) => $r->where('is_active', true)))
            ->with(['shift', 'rotation.steps.shift'])
            ->get();
    }

    /**
     * The assignment that decides $day: the first level holding one in force
     * that day, latest `effective_from` within it.
     *
     * @param  Collection<int, ShiftAssignment>  $candidates
     */
    private function governing(Collection $candidates, string $day): ?ShiftAssignment
    {
        foreach (self::LEVELS as $level) {
            $match = $candidates
                ->filter(fn (ShiftAssignment $a): bool => $a->assignable_type === $level
                    && $a->effective_from !== null
                    && $a->effective_from->format('Y-m-d') <= $day
                    && ($a->effective_to === null || $a->effective_to->format('Y-m-d') >= $day))
                ->sortByDesc(fn (ShiftAssignment $a): string => (string) $a->effective_from?->format('Y-m-d'))
                ->first();

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    private function rotationShift(ShiftAssignment $assignment, CarbonInterface $date): ?Shift
    {
        $rotation = $assignment->rotation;

        $anchor = $assignment->anchor_date ?? $assignment->effective_from;

        if ($rotation === null || $anchor === null) {
            return null;
        }

        $shift = $this->rotations->shiftFor($rotation, $anchor, $date);

        return $shift !== null && $shift->is_active ? $shift : null;
    }

    private function findDefaultShift(Employee $employee): ?Shift
    {
        return Shift::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    public function calculateStatus(Carbon $checkIn, Shift $shift): string
    {
        $shiftStart = $checkIn->copy()->setTimeFromTimeString($shift->start_time);

        $graceEnd = $shiftStart->copy()->addMinutes($shift->grace_minutes);

        if ($checkIn->lte($graceEnd)) {
            return 'present';
        }

        return 'late';
    }
}
