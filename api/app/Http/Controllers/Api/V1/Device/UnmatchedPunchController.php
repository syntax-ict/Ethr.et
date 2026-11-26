<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\AssignUnmatchedBadgeRequest;
use App\Http\Requests\Device\DismissUnmatchedBadgeRequest;
use App\Models\Device;
use App\Models\Employee;
use App\Models\User;
use App\Services\Device\DevicePunchRecorder;
use App\Services\Device\TerminalNames;
use App\Services\Device\UnmatchedPunches;
use App\Services\Identity\IdentityMatch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Badges a device sent that no employee matched, with the punches held for
 * them (UnmatchedPunches). Assigning a badge to an employee replays them;
 * dismissing drops them.
 */
class UnmatchedPunchController extends Controller
{
    /**
     * How many badges, most recent first, are resolved for a suggestion. Each
     * one is a resolver query; the badges past it are listed with no `match`.
     */
    public const RESOLVE_LIMIT = 100;

    /** @var array<int, bool> whether each listed badge records itself, set while the suggestions are resolved */
    private array $recording = [];

    public function __construct(
        private readonly UnmatchedPunches $unmatched,
        private readonly TerminalNames $terminalNames,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('device.viewAny');

        $groups = $this->unmatched->groups();
        $devices = Device::query()
            ->whereIn('id', array_unique(array_column($groups, 'device_id')))
            ->get(['id', 'tenant_id', 'public_id', 'name', 'adapter_type'])
            ->keyBy('id');

        $viewer = $request->user();
        $manages = Gate::allows('attendance.manage');

        // Only a manager is told what the terminal calls a badge: the name is
        // evidence for assigning it, and assigning needs `attendance.manage`.
        $names = $manages ? $this->namesFor($groups, $devices) : [];
        $matches = $manages ? $this->suggestions($groups, $devices, $viewer, $names) : [];

        $data = [];
        foreach ($groups as $index => $group) {
            $device = $devices->get($group['device_id']);
            if (! $device instanceof Device) {
                continue;
            }
            $match = $matches[$index] ?? null;
            $nameOnDevice = $this->nameOnDevice($names, $group);
            $data[] = [
                'device_public_id' => (string) $device->public_id,
                'device_name' => (string) $device->name,
                'badge' => $group['badge'],
                'punches' => $group['punches'],
                'first_seen' => $group['first_seen'],
                'last_seen' => $group['last_seen'],
                'match' => $match,
                'recording_itself' => ($this->recording[$index] ?? false) === true,
                'name_on_device' => $nameOnDevice,
                'name_matches' => $this->nameMatches($device, $nameOnDevice, $match, $index < self::RESOLVE_LIMIT, $viewer),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Who each badge may be now, as the resolver sees it today. The table
     * holds the badge, not the verdict it had when it arrived, so a badge
     * whose holder has since been imported or typed into a record comes back
     * `matched`, and the card can offer to replay its punches.
     *
     * Offered only to those who can act on it (`attendance.manage`, which
     * `assign` requires), and naming only people they may open: the resolver
     * reads the whole organisation, archived people included.
     *
     * @param  list<array{device_id: int, badge: string, punches: int, first_seen: string, last_seen: string}>  $groups
     * @param  Collection<int, Device>  $devices
     * @param  array<int, array<string, string>>  $names
     * @return array<int, array{outcome: string, employee_public_id: string|null, confidence: float, candidates: list<array{employee_public_id: string, employee_name: string, score: float, reasons: array<int, string>}>}>
     */
    private function suggestions(array $groups, Collection $devices, User $viewer, array $names): array
    {
        $matches = [];
        foreach (array_slice($groups, 0, self::RESOLVE_LIMIT, true) as $index => $group) {
            $device = $devices->get($group['device_id']);
            if ($device instanceof Device) {
                $verdict = $this->unmatched->verdict($device, $group['badge'], $this->nameOnDevice($names, $group));
                $matches[$index] = $verdict;
                $this->recording[$index] = $this->unmatched->recordsItself($device, $group['badge'], $this->nameOnDevice($names, $group), $verdict);
            }
        }

        return IdentityMatch::visibleTo($matches, $viewer);
    }

    /**
     * What each terminal calls the held badges, read once.
     *
     * @param  list<array{device_id: int, badge: string, punches: int, first_seen: string, last_seen: string}>  $groups
     * @param  Collection<int, Device>  $devices
     * @return array<int, array<string, string>>
     */
    private function namesFor(array $groups, Collection $devices): array
    {
        $names = [];
        foreach ($devices->groupBy('tenant_id') as $tenantId => $tenantDevices) {
            $ids = $tenantDevices->pluck('id')->all();
            $pairs = [];
            foreach (array_slice($groups, 0, self::RESOLVE_LIMIT) as $group) {
                if (in_array($group['device_id'], $ids, true)) {
                    $pairs[] = [$group['device_id'], $group['badge']];
                }
            }
            foreach ($this->terminalNames->forBadges((int) $tenantId, $pairs) as $deviceId => $byBadge) {
                $names[$deviceId] = $byBadge;
            }
        }

        return $names;
    }

    /**
     * @param  array<int, array<string, string>>  $names
     * @param  array{device_id: int, badge: string, punches: int, first_seen: string, last_seen: string}  $group
     */
    private function nameOnDevice(array $names, array $group): ?string
    {
        $name = $names[$group['device_id']][mb_strtolower($group['badge'])] ?? null;

        return $name === null ? null : (string) $name;
    }

    /**
     * Employees the terminal's name could be, for a person to choose from.
     * Empty when the card already says whose the badge is, and for anyone the
     * viewer may not open.
     *
     * @param  array<string, mixed>|null  $match
     * @return list<array{employee_public_id: string, employee_name: string, department: string|null}>
     */
    private function nameMatches(Device $device, ?string $name, ?array $match, bool $withinLimit, User $viewer): array
    {
        if ($name === null || ! $withinLimit || ($match['outcome'] ?? null) === IdentityMatch::MATCHED) {
            return [];
        }

        $people = [];
        foreach ($this->terminalNames->lookalikes($device->tenant_id, $name) as $employee) {
            if (! $viewer->canAccessEmployee($employee)) {
                continue;
            }
            $department = $employee->department?->name;
            $people[] = [
                'employee_public_id' => (string) $employee->public_id,
                'employee_name' => (string) $employee->name,
                'department' => $department === null ? null : (string) $department,
            ];
        }

        return $people;
    }

    public function assign(AssignUnmatchedBadgeRequest $request, DevicePunchRecorder $recorder): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $device = Device::query()->where('public_id', $request->validated('device_public_id'))->firstOrFail();
        $employee = Employee::query()->where('public_id', $request->validated('employee_public_id'))->firstOrFail();
        abort_unless($request->user()->canAccessEmployee($employee), 404);

        $result = $this->unmatched->assign($device, (string) $request->validated('badge'), $employee, $request->user(), $recorder);

        return response()->json($result);
    }

    public function dismiss(DismissUnmatchedBadgeRequest $request): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $device = Device::query()->where('public_id', $request->validated('device_public_id'))->firstOrFail();

        return response()->json([
            'dismissed' => $this->unmatched->dismiss($device, (string) $request->validated('badge'), $request->user()),
        ]);
    }
}
