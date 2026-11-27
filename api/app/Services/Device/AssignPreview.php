<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Models\Device;
use App\Models\Employee;
use App\Models\UnmatchedDevicePunch;
use App\Models\User;
use App\Services\Identity\IdentityMatch;
use Illuminate\Support\Facades\DB;

/**
 * What assigning a held badge to one person would do, and what speaks against
 * it, read without changing anything.
 *
 * The preview and the assign request both ask this class, so the warnings a
 * person was shown and the warnings the server enforces cannot drift apart.
 * The identity-line warnings come from BadgeSuggestionDetails (`other_badge`,
 * `hired_after`, `own_attendance`); this class adds `moves_from`, which says
 * the badge number is today matched to someone else and assigning would take
 * it from them. Its value is that holder's name when the viewer may open
 * them, and null otherwise.
 *
 * Automatic recording does not ask this class: it has no person to
 * acknowledge anything, and keeps its own strict rule (`recordsItself`).
 */
final class AssignPreview
{
    /** How long after a person's own replay of a badge to this employee a later call is the same run. */
    private const CONTINUATION_MINUTES = 15;

    public function __construct(
        private readonly UnmatchedPunches $unmatched,
        private readonly TerminalNames $terminalNames,
    ) {}

    /**
     * @return array{punches: int, first_day: string|null, last_day: string|null, employee: array{public_id: string, name: string, employee_code: string|null, department: string|null, position: string|null, branch: string|null, hire_date: string|null, status: string, badge_number: string|null}, warnings: list<array{code: string, value: string|int|null}>}
     */
    public function for(Device $device, string $badge, Employee $employee, User $viewer): array
    {
        $details = new BadgeSuggestionDetails(
            [(string) $employee->public_id],
            [(int) $device->tenant_id],
            [['device_id' => (int) $device->id, 'badge' => $badge]],
        );
        $identity = $details->identity((string) $employee->public_id, (int) $device->id, $badge);
        $warnings = $identity['warnings'] ?? [];

        $holder = $this->holderOtherThan($device, $badge, $employee, $viewer);
        if ($holder !== false) {
            $warnings[] = ['code' => 'moves_from', 'value' => $holder];
        }

        [$punches, $first, $last] = $this->pendingDays($device, $badge);

        return [
            'punches' => $punches,
            'first_day' => $first,
            'last_day' => $last,
            'employee' => [
                'public_id' => (string) $employee->public_id,
                'name' => (string) $employee->name,
                'employee_code' => $identity['employee_code'] ?? null,
                'department' => $identity['department'] ?? null,
                'position' => $identity['position'] ?? null,
                'branch' => $identity['branch'] ?? null,
                'hire_date' => $identity['hire_date'] ?? null,
                'status' => $identity['status'] ?? (string) $employee->status->value,
                'badge_number' => $identity['badge_number'] ?? null,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * The warnings a person must acknowledge before the badge is assigned. None
     * when this call carries on a replay the same person already started for
     * the same employee a moment ago: they were asked, or had nothing to be
     * asked, at the first call, and the attendance their own replay has made
     * since must not read as a warning on the next slice.
     *
     * @return list<array{code: string, value: string|int|null}>
     */
    public function warningsToAccept(Device $device, string $badge, Employee $employee, User $viewer): array
    {
        if ($this->continuing($device, $badge, $employee, $viewer)) {
            return [];
        }

        return $this->for($device, $badge, $employee, $viewer)['warnings'];
    }

    /**
     * The name of the person the badge is matched to today when it is not this
     * employee (null when the viewer may not open them), or false when it is
     * matched to nobody else.
     */
    private function holderOtherThan(Device $device, string $badge, Employee $employee, User $viewer): string|null|false
    {
        $names = $this->terminalNames->forBadges((int) $device->tenant_id, [[(int) $device->id, $badge]]);
        $name = $names[(int) $device->id][mb_strtolower($badge)] ?? null;

        $verdict = $this->unmatched->verdict($device, $badge, $name === null ? null : (string) $name);
        $holder = $verdict->employee;

        if ($verdict->outcome !== IdentityMatch::MATCHED || $holder === null || $holder->id === $employee->id) {
            return false;
        }

        return $viewer->canAccessEmployee($holder) ? (string) $holder->name : null;
    }

    private function continuing(Device $device, string $badge, Employee $employee, User $viewer): bool
    {
        return UnmatchedDevicePunch::query()
            ->where('tenant_id', $device->tenant_id)
            ->where('device_id', $device->id)
            ->where('badge', $badge)
            ->where('employee_id', $employee->id)
            ->where('resolved_by', $viewer->id)
            ->whereIn('status', [UnmatchedDevicePunch::REPLAYED, UnmatchedDevicePunch::REFUSED])
            ->where('resolved_at', '>=', now()->subMinutes(self::CONTINUATION_MINUTES))
            ->exists();
    }

    /**
     * How many punches are held, and the first and last day among them, read
     * from the first ten characters of the device's own time stamp.
     *
     * @return array{0: int, 1: string|null, 2: string|null}
     */
    private function pendingDays(Device $device, string $badge): array
    {
        $rows = UnmatchedDevicePunch::query()
            ->where('tenant_id', $device->tenant_id)
            ->where('device_id', $device->id)
            ->where('badge', $badge)
            ->where('status', UnmatchedDevicePunch::PENDING)
            ->selectRaw('SUBSTR(occurred_raw, 1, 10) as day, COUNT(*) as punches')
            ->groupBy(DB::raw('SUBSTR(occurred_raw, 1, 10)'))
            ->get();

        $count = 0;
        $days = [];
        foreach ($rows as $row) {
            $count += (int) $row->getAttribute('punches');
            $day = (string) $row->getAttribute('day');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1) {
                $days[] = $day;
            }
        }

        return [$count, $days === [] ? null : min($days), $days === [] ? null : max($days)];
    }
}
