<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\ExpectedDay;
use App\Models\Employee;
use App\Models\Holiday;
use Carbon\CarbonInterface;

/**
 * What a day is for one employee, in the words a punch screen needs: a working
 * day, a public holiday (and which), a rest day (and whether it is the
 * weekend), approved leave.
 *
 * Every way of punching answers with this, so a person who checks in by phone,
 * by QR, on the web or at the kiosk is told the same thing about the same day.
 * The decision is ExpectedDays' (the one rule that every absence count already
 * follows: the person's shift or rotation, the organisation's working week,
 * holidays, approved leave); this adds the holiday's name and the weekend.
 *
 * Nothing here refuses a punch. Working on a holiday or a rest day is allowed
 * and is paid as overtime; the screen only says what the day is.
 */
final class DayContext
{
    public function __construct(private readonly ExpectedDays $expected) {}

    /**
     * The same answer for a caller that has no instance to hand, such as an API
     * resource. The shape is declared here because the API contract is built
     * from it and cannot see through the container.
     *
     * @return array{kind: string, weekend: bool, holiday: array{name: string, name_am: string|null}|null}
     */
    public static function of(Employee $employee, CarbonInterface $day): array
    {
        return app(self::class)->forEmployee($employee, $day);
    }

    /**
     * @return array{kind: string, weekend: bool, holiday: array{name: string, name_am: string|null}|null}
     */
    public function forEmployee(Employee $employee, CarbonInterface $day): array
    {
        $date = $day->format('Y-m-d');
        $kind = $this->expected->forEmployee($employee, $day, $day)[$date] ?? ExpectedDay::WORKDAY;

        return [
            'kind' => $kind->value,
            'weekend' => (bool) ($kind === ExpectedDay::REST_DAY && $day->dayOfWeekIso >= 6),
            'holiday' => $kind === ExpectedDay::HOLIDAY ? $this->holiday($employee, $date) : null,
        ];
    }

    /** @return array{name: string, name_am: string|null}|null */
    private function holiday(Employee $employee, string $date): ?array
    {
        $holiday = Holiday::query()
            ->where('tenant_id', $employee->tenant_id)
            ->whereDate('date', $date)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $employee->branch_id))
            ->first();

        return $holiday === null ? null : ['name' => $holiday->name, 'name_am' => $holiday->name_am];
    }
}
