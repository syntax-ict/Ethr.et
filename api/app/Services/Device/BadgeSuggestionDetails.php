<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Enums\AttendanceStatus;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\UnmatchedDevicePunch;
use App\Support\TenantTime;
use Illuminate\Support\Facades\DB;

/**
 * What a person needs to choose between the people a held badge may be: who
 * each one is (number, department, position, branch, hire date, status) and
 * what speaks against them.
 *
 * Built for a whole page of badges at once, in a fixed number of queries: the
 * people in one, the days each badge punched in one, and their own attendance
 * on those days in one per few hundred people. Nothing here assigns a badge or
 * changes a row; it only reads.
 *
 * The warnings, as `{code, value}`:
 *  - `other_badge`: they already carry another badge number (the value).
 *  - `hired_after`: their hire date is after the first day this badge punched
 *    (the value is that day), so the punches would fall before they started.
 *    It is left out when the hire date is the same calendar day, in the
 *    organisation's own time zone, as the day the record was created: that
 *    date is then taken to be a placeholder filled in by an import, not a real
 *    start date. A person entered on their true first day loses the warning too.
 *  - `own_attendance`: they already have attendance of their own on days this
 *    badge punched (the value is how many days), so replaying could double up.
 */
final class BadgeSuggestionDetails
{
    /** People whose attendance is read in one statement. */
    private const ATTENDANCE_CHUNK = 400;

    /** @var array<string, Employee> */
    private array $people = [];

    /** @var array<int, string> tenant id => time zone name, read once per organisation */
    private array $zones = [];

    /** @var array<string, list<string>> badge key => the days (Y-m-d) it punched on */
    private array $days = [];

    /** @var array<int, array<string, true>> employee id => the days they have attendance */
    private array $attendance = [];

    /**
     * Read what the page needs.
     *
     * @param  list<string>  $publicIds  every person the page may name
     * @param  list<int>  $tenantIds  the organisations of the devices on the page
     * @param  list<array{device_id: int, badge: string}>  $badges  the badges on the page
     */
    public function __construct(array $publicIds, array $tenantIds, array $badges)
    {
        $publicIds = array_values(array_unique($publicIds));
        $tenantIds = array_values(array_unique($tenantIds));

        if ($publicIds !== [] && $tenantIds !== []) {
            Employee::withTrashed()
                ->whereIn('tenant_id', $tenantIds)
                ->whereIn('public_id', $publicIds)
                ->with(['department:id,name', 'position:id,title', 'branch:id,name'])
                ->get()
                ->each(function (Employee $employee): void {
                    $this->people[(string) $employee->public_id] = $employee;
                });
        }

        if ($this->people !== []) {
            Tenant::query()
                ->whereIn('id', array_values(array_unique(array_map(fn (Employee $e): int => (int) $e->tenant_id, $this->people))))
                ->get()
                ->each(function (Tenant $tenant): void {
                    $this->zones[(int) $tenant->id] = TenantTime::zone($tenant);
                });
        }

        $this->readDays($badges, $tenantIds);
        $this->readAttendance($tenantIds);
    }

    /**
     * The identity line of one person for one badge, or null when they were not
     * loaded (not in this organisation, or not named on this page).
     *
     * @return array{employee_code: string|null, department: string|null, position: string|null, branch: string|null, hire_date: string|null, status: string, badge_number: string|null, warnings: list<array{code: string, value: string|int|null}>}|null
     */
    public function identity(string $employeePublicId, int $deviceId, string $badge): ?array
    {
        $employee = $this->people[$employeePublicId] ?? null;
        if (! $employee instanceof Employee) {
            return null;
        }

        $days = $this->days[self::key($deviceId, $badge)] ?? [];
        $warnings = [];

        $theirs = $employee->badge_number === null ? '' : trim((string) $employee->badge_number);
        if ($theirs !== '' && mb_strtolower($theirs) !== mb_strtolower(trim($badge))) {
            $warnings[] = ['code' => 'other_badge', 'value' => $theirs];
        }

        $first = $days === [] ? null : min($days);
        $hired = $employee->hire_date?->format('Y-m-d');
        if ($first !== null && $hired !== null && $hired > $first && ! $this->hiredOnImportDay($employee, $hired)) {
            $warnings[] = ['code' => 'hired_after', 'value' => $first];
        }

        $own = array_intersect_key($this->attendance[(int) $employee->id] ?? [], array_flip($days));
        if ($own !== []) {
            $warnings[] = ['code' => 'own_attendance', 'value' => count($own)];
        }

        return [
            'employee_code' => $employee->employee_code === null ? null : (string) $employee->employee_code,
            'department' => $employee->department?->name === null ? null : (string) $employee->department->name,
            'position' => $employee->position?->title === null ? null : (string) $employee->position->title,
            'branch' => $employee->branch?->name === null ? null : (string) $employee->branch->name,
            'hire_date' => $hired,
            'status' => $employee->trashed() ? 'archived' : (string) $employee->status->value,
            'badge_number' => $theirs === '' ? null : $theirs,
            'warnings' => $warnings,
        ];
    }

    private function hiredOnImportDay(Employee $employee, string $hired): bool
    {
        if ($employee->created_at === null) {
            return false;
        }

        $zone = $this->zones[(int) $employee->tenant_id] ?? TenantTime::zone(null);

        return $employee->created_at->copy()->setTimezone($zone)->format('Y-m-d') === $hired;
    }

    private static function key(int $deviceId, string $badge): string
    {
        return $deviceId.':'.mb_strtolower($badge);
    }

    /**
     * The days each badge punched on, from the device's own time stamp. The
     * first ten characters of `occurred_raw` are the date in both formats the
     * adapters store ("2026-11-17T09:00:00+03:00", "2026-11-17 09:00:00"), and
     * a row that does not start with one is left out.
     *
     * @param  list<array{device_id: int, badge: string}>  $badges
     * @param  list<int>  $tenantIds
     */
    private function readDays(array $badges, array $tenantIds): void
    {
        if ($badges === [] || $tenantIds === []) {
            return;
        }

        $wanted = [];
        foreach ($badges as $badge) {
            $wanted[self::key($badge['device_id'], $badge['badge'])] = true;
        }

        $rows = UnmatchedDevicePunch::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', UnmatchedDevicePunch::PENDING)
            ->whereIn('device_id', array_values(array_unique(array_column($badges, 'device_id'))))
            ->whereIn('badge', array_values(array_unique(array_column($badges, 'badge'))))
            ->selectRaw('device_id, badge, SUBSTR(occurred_raw, 1, 10) as day')
            ->groupBy('device_id', 'badge', DB::raw('SUBSTR(occurred_raw, 1, 10)'))
            ->get();

        foreach ($rows as $row) {
            $key = self::key((int) $row->getAttribute('device_id'), (string) $row->getAttribute('badge'));
            $day = (string) $row->getAttribute('day');

            if (isset($wanted[$key]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1) {
                $this->days[$key][] = $day;
            }
        }
    }

    /**
     * Each loaded person's attendance on any day a badge punched. Voided
     * records do not count. The range is the earliest to the latest day on the
     * page, and the exact days are matched afterwards, one badge at a time.
     *
     * @param  list<int>  $tenantIds
     */
    private function readAttendance(array $tenantIds): void
    {
        $all = array_merge(...array_values($this->days ?: [[]]));
        if ($all === [] || $this->people === [] || $tenantIds === []) {
            return;
        }

        $ids = array_values(array_unique(array_map(fn (Employee $e): int => (int) $e->id, $this->people)));

        foreach (array_chunk($ids, self::ATTENDANCE_CHUNK) as $chunk) {
            $rows = DB::table('attendance_records')
                ->whereIn('tenant_id', $tenantIds)
                ->whereIn('employee_id', $chunk)
                // A date column reads back as "Y-m-d 00:00:00" on some drivers,
                // so the last day is bounded by its end, not its start.
                ->whereBetween('date', [min($all), max($all).' 23:59:59'])
                ->where('status', '!=', AttendanceStatus::VOIDED->value)
                ->select(['employee_id', 'date'])
                ->distinct()
                ->get();

            foreach ($rows as $row) {
                $this->attendance[(int) $row->employee_id][substr((string) $row->date, 0, 10)] = true;
            }
        }
    }
}
