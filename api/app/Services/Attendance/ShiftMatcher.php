<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRotation;
use App\Services\Shift\ShiftRotationResolver;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which shift an employee is scheduled on for a date.
 *
 * Assignments are looked for on the employee, then the department, then the
 * branch — the first level with one in force decides, and the most recent
 * `effective_from` wins within a level. With none, the tenant's default
 * schedule: its default rotation if it has one (a six-day week of two shifts),
 * otherwise its default shift.
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
        $assignment = $this->governing($this->candidates([$employee], $day, $day), $day);

        if ($assignment === null) {
            return $this->defaultOn($this->defaults($employee), $date);
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
        $candidates = $this->candidates([$employee], $from->format('Y-m-d'), $to->format('Y-m-d'));
        $defaultRotation = $this->defaults($employee)['rotation'];

        if ($defaultRotation === null && $candidates->every(fn (ShiftAssignment $a): bool => ! $a->isRotation())) {
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
            } elseif ($assignment === null && $defaultRotation !== null) {
                $restDays[$day] = $this->defaultRotationShift($defaultRotation, $cursor) === null;
            }

            $cursor = $cursor->addDay();
        }

        return $restDays;
    }

    /**
     * For each date in the range, whether $employee is scheduled to work: the
     * governing rotation's answer, else the governing or default shift's
     * `working_days`, else the organisation's working week. Same precedence
     * as match(), so a day this calls a working day is one match() gives a
     * shift for.
     *
     * The assignments are loaded once and every day is resolved in memory.
     *
     * @param  list<int>  $workingWeek  ISO weekdays, used when no shift applies
     * @return array<string, bool> keyed by Y-m-d
     */
    public function workingDays(Employee $employee, CarbonInterface $from, CarbonInterface $to, array $workingWeek): array
    {
        $candidates = $this->candidates([$employee], $from->format('Y-m-d'), $to->format('Y-m-d'));
        $default = $this->defaults($employee);

        $days = [];
        $cursor = Carbon::parse($from->format('Y-m-d'));
        $end = Carbon::parse($to->format('Y-m-d'));

        while ($cursor->lessThanOrEqualTo($end)) {
            $days[$cursor->format('Y-m-d')] = $this->worksOn($candidates, $employee, $cursor, $default, $workingWeek);
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * Whether each employee is scheduled to work on $date, by the same rules
     * as workingDays(). One query for the assignments of all of them, so a
     * whole organisation's day costs two queries, not one per person.
     *
     * @param  Collection<int, Employee>  $employees  all of one tenant
     * @param  list<int>  $workingWeek
     * @return array<int, bool> keyed by employee id
     */
    public function workingOn(Collection $employees, CarbonInterface $date, array $workingWeek): array
    {
        $first = $employees->first();

        if ($first === null) {
            return [];
        }

        $day = $date->format('Y-m-d');
        $candidates = $this->candidates($employees->values()->all(), $day, $day);
        $default = $this->defaults($first);

        $working = [];
        foreach ($employees as $employee) {
            $working[$employee->id] = $this->worksOn($candidates, $employee, $date, $default, $workingWeek);
        }

        return $working;
    }

    /**
     * @param  Collection<int, ShiftAssignment>  $candidates
     * @param  array{rotation: ?ShiftRotation, shift: ?Shift}  $default
     * @param  list<int>  $workingWeek
     */
    private function worksOn(Collection $candidates, Employee $employee, CarbonInterface $date, array $default, array $workingWeek): bool
    {
        $owned = $candidates->filter(fn (ShiftAssignment $a): bool => match ($a->assignable_type) {
            Employee::class => (int) $a->assignable_id === $employee->id,
            Department::class => $employee->department_id !== null && (int) $a->assignable_id === (int) $employee->department_id,
            Branch::class => $employee->branch_id !== null && (int) $a->assignable_id === (int) $employee->branch_id,
            default => false,
        });

        $assignment = $this->governing($owned, $date->format('Y-m-d'));

        if ($assignment === null) {
            if ($default['rotation'] !== null) {
                return $this->defaultRotationShift($default['rotation'], $date) !== null;
            }

            return $default['shift'] !== null
                ? $default['shift']->isWorkingDay($date)
                : in_array($date->dayOfWeekIso, $workingWeek, true);
        }

        if ($assignment->isRotation()) {
            return $this->rotationShift($assignment, $date) !== null;
        }

        return $assignment->shift !== null && $assignment->shift->isWorkingDay($date);
    }

    /**
     * Every assignment on the employees, their departments or their branches
     * that is in force at some point in [$from, $to] and still points at
     * something usable: an active shift, or an active rotation.
     *
     * @param  list<Employee>  $employees
     * @return Collection<int, ShiftAssignment>
     */
    private function candidates(array $employees, string $from, string $to): Collection
    {
        $owners = [
            Employee::class => array_values(array_unique(array_map(fn (Employee $e): int => $e->id, $employees))),
            Department::class => array_values(array_unique(array_filter(array_map(fn (Employee $e): ?int => $e->department_id, $employees)))),
            Branch::class => array_values(array_unique(array_filter(array_map(fn (Employee $e): ?int => $e->branch_id, $employees)))),
        ];

        return ShiftAssignment::query()
            ->where(function ($query) use ($owners) {
                foreach (array_filter($owners) as $type => $ids) {
                    $query->orWhere(fn ($q) => $q->where('assignable_type', $type)->whereIn('assignable_id', $ids));
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

    /**
     * The tenant's default schedule: a default rotation, or a default shift.
     * Both are loaded so callers resolving many days ask once.
     *
     * @return array{rotation: ?ShiftRotation, shift: ?Shift}
     */
    private function defaults(Employee $employee): array
    {
        $rotation = ShiftRotation::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->with('steps.shift')
            ->first();

        return [
            'rotation' => $rotation,
            'shift' => $rotation === null ? $this->findDefaultShift($employee) : null,
        ];
    }

    /** @param  array{rotation: ?ShiftRotation, shift: ?Shift}  $default */
    private function defaultOn(array $default, CarbonInterface $date): ?Shift
    {
        return $default['rotation'] !== null
            ? $this->defaultRotationShift($default['rotation'], $date)
            : $default['shift'];
    }

    /**
     * A default rotation's shift on $date, step 0 falling on its anchor (the
     * day it was made the default, when none was given). A rest day is null.
     */
    private function defaultRotationShift(ShiftRotation $rotation, CarbonInterface $date): ?Shift
    {
        $anchor = $rotation->anchor_date ?? $rotation->created_at;

        if ($anchor === null) {
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

    /**
     * The shift a punch at $local belongs to, and the day it belongs to.
     *
     * Usually today's shift and today. But while yesterday's overnight shift
     * is still running, a punch belongs to it: someone due at 22:00 who
     * arrives at 00:30 is late for last night, not early for tonight. Until
     * 2026-10-24 that punch was matched to today's shift, its start was put
     * on today's date (22:00, still ahead), and it was stored `present` and
     * dated the wrong day.
     *
     * $local must be in the tenant's zone.
     *
     * @return array{shift: ?Shift, date: string}
     */
    public function forPunch(Employee $employee, Carbon $local): array
    {
        $yesterday = $local->copy()->subDay();
        $previous = $this->match($employee, $yesterday);

        if ($previous !== null && $previous->crossesMidnight()) {
            [, $end] = $previous->windowOn($yesterday->format('Y-m-d'), $local->getTimezone()->getName());

            if ($local->lt($end)) {
                return ['shift' => $previous, 'date' => $yesterday->format('Y-m-d')];
            }
        }

        return ['shift' => $this->match($employee, $local), 'date' => $local->format('Y-m-d')];
    }

    /**
     * `present` or `late` against the shift's start plus grace, on $workDate
     * (Y-m-d; the check-in's own date when null). `$checkIn` must be in the
     * tenant's zone: shift times are wall-clock times there.
     */
    public function calculateStatus(Carbon $checkIn, Shift $shift, ?string $workDate = null): string
    {
        [$shiftStart] = $shift->windowOn($workDate ?? $checkIn->format('Y-m-d'), $checkIn->getTimezone()->getName());

        $graceEnd = $shiftStart->copy()->addMinutes($shift->grace_minutes);

        if ($checkIn->lte($graceEnd)) {
            return 'present';
        }

        return 'late';
    }
}
