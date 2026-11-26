<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\AssignUnmatchedBadgeRequest;
use App\Http\Requests\Device\DismissUnmatchedBadgeRequest;
use App\Models\Device;
use App\Models\Employee;
use App\Models\User;
use App\Services\Device\BadgeSuggestionDetails;
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

        $nameMatches = $manages ? $this->nameMatchesFor($groups, $devices, $names, $matches, $viewer) : [];

        // The people the page names, read once, with what is told of each.
        $details = $manages ? $this->details($groups, $devices, $matches, $nameMatches) : null;

        $data = [];
        foreach ($groups as $index => $group) {
            $device = $devices->get($group['device_id']);
            if (! $device instanceof Device) {
                continue;
            }
            $match = $matches[$index] ?? null;
            $data[] = [
                'device_public_id' => (string) $device->public_id,
                'device_name' => (string) $device->name,
                'badge' => $group['badge'],
                'punches' => $group['punches'],
                'first_seen' => $group['first_seen'],
                'last_seen' => $group['last_seen'],
                'match' => $this->matchWithIdentity($match, $details, $group),
                'recording_itself' => ($this->recording[$index] ?? false) === true,
                'name_on_device' => $this->nameOnDevice($names, $group),
                'name_matches' => $this->nameMatchesWithIdentity($nameMatches[$index] ?? [], $details, $group),
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
     * Employees the terminal's name could be, for a person to choose from,
     * the exact fits first and then the spellings that differ a little
     * (TerminalNames::suggestionsFor). Never what assigns a badge: the
     * automatic recording asks the strict `lookalikes()` and not this.
     *
     * Empty for a badge the card already says whose it is, for one past the
     * resolve limit, and for anyone the viewer may not open. The fit limit is
     * applied before the viewer's filter, as `lookalikes()` does it.
     *
     * @param  list<array{device_id: int, badge: string, punches: int, first_seen: string, last_seen: string}>  $groups
     * @param  Collection<int, Device>  $devices
     * @param  array<int, array<string, string>>  $names
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, list<array{employee_public_id: string, employee_name: string, department: string|null, fit: string}>>
     */
    private function nameMatchesFor(array $groups, Collection $devices, array $names, array $matches, User $viewer): array
    {
        $wanted = [];
        foreach (array_slice($groups, 0, self::RESOLVE_LIMIT, true) as $index => $group) {
            $device = $devices->get($group['device_id']);
            $name = $this->nameOnDevice($names, $group);
            if ($device instanceof Device && $name !== null && ($matches[$index]['outcome'] ?? null) !== IdentityMatch::MATCHED) {
                $wanted[(int) $device->tenant_id][$index] = $name;
            }
        }

        $found = [];
        foreach ($wanted as $tenantId => $byIndex) {
            foreach ($this->terminalNames->suggestionsFor($tenantId, $byIndex) as $index => $suggestions) {
                foreach ($suggestions as $suggestion) {
                    $employee = $suggestion['employee'];
                    if (! $viewer->canAccessEmployee($employee)) {
                        continue;
                    }
                    $found[$index][] = [
                        'employee_public_id' => (string) $employee->public_id,
                        'employee_name' => (string) $employee->name,
                        'department' => null,
                        'fit' => $suggestion['fit'],
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * The resolver's verdict with an identity line on each person it names.
     *
     * @param  array<string, mixed>|null  $match
     * @param  array{device_id: int, badge: string, punches: int, first_seen: string, last_seen: string}  $group
     * @return array<string, mixed>|null
     */
    private function matchWithIdentity(?array $match, ?BadgeSuggestionDetails $details, array $group): ?array
    {
        if ($match === null || $details === null) {
            return $match;
        }

        $match['candidates'] = array_map(
            fn (array $candidate): array => $candidate + ['identity' => $details->identity($candidate['employee_public_id'], $group['device_id'], $group['badge'])],
            $match['candidates'],
        );

        return $match;
    }

    /**
     * The people the terminal's name fits, each with an identity line.
     *
     * @param  list<array{employee_public_id: string, employee_name: string, department: string|null, fit: string}>  $people
     * @param  array{device_id: int, badge: string, punches: int, first_seen: string, last_seen: string}  $group
     * @return list<array{employee_public_id: string, employee_name: string, department: string|null, fit: string, identity: array{employee_code: string|null, department: string|null, position: string|null, branch: string|null, hire_date: string|null, status: string, badge_number: string|null, warnings: list<array{code: string, value: string|int|null}>}|null}>
     */
    private function nameMatchesWithIdentity(array $people, ?BadgeSuggestionDetails $details, array $group): array
    {
        $found = [];
        foreach ($people as $person) {
            $identity = $details?->identity($person['employee_public_id'], $group['device_id'], $group['badge']);
            $found[] = [
                'employee_public_id' => $person['employee_public_id'],
                'employee_name' => $person['employee_name'],
                'department' => $identity['department'] ?? $person['department'],
                'fit' => $person['fit'],
                'identity' => $identity,
            ];
        }

        return $found;
    }

    /**
     * Everyone the page names, with identity and warnings, read once.
     *
     * @param  list<array{device_id: int, badge: string, punches: int, first_seen: string, last_seen: string}>  $groups
     * @param  Collection<int, Device>  $devices
     * @param  array<int, array<string, mixed>>  $matches
     * @param  array<int, list<array{employee_public_id: string, employee_name: string, department: string|null, fit: string}>>  $nameMatches
     */
    private function details(array $groups, Collection $devices, array $matches, array $nameMatches): BadgeSuggestionDetails
    {
        $ids = [];
        foreach ($matches as $match) {
            foreach ($match['candidates'] ?? [] as $candidate) {
                $ids[] = $candidate['employee_public_id'];
            }
        }
        foreach ($nameMatches as $people) {
            foreach ($people as $person) {
                $ids[] = $person['employee_public_id'];
            }
        }

        $badges = [];
        foreach (array_slice($groups, 0, self::RESOLVE_LIMIT) as $group) {
            $badges[] = ['device_id' => $group['device_id'], 'badge' => $group['badge']];
        }

        $details = new BadgeSuggestionDetails(
            $ids,
            array_values(array_unique($devices->pluck('tenant_id')->map(fn ($id): int => (int) $id)->all())),
            $badges,
        );

        return $details;
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
