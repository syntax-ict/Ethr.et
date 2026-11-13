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
use App\Services\Device\UnmatchedPunches;
use App\Services\Identity\IdentityMatch;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
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

    public function __construct(
        private readonly UnmatchedPunches $unmatched,
        private readonly IdentityResolver $resolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('device.viewAny');

        $groups = $this->unmatched->groups();
        $devices = Device::query()
            ->whereIn('id', array_unique(array_column($groups, 'device_id')))
            ->get(['id', 'tenant_id', 'public_id', 'name', 'adapter_type'])
            ->keyBy('id');

        $matches = Gate::allows('attendance.manage')
            ? $this->suggestions($groups, $devices, $request->user())
            : [];

        $data = [];
        foreach ($groups as $index => $group) {
            $device = $devices->get($group['device_id']);
            if (! $device instanceof Device) {
                continue;
            }
            $data[] = [
                'device_public_id' => (string) $device->public_id,
                'device_name' => (string) $device->name,
                'badge' => $group['badge'],
                'punches' => $group['punches'],
                'first_seen' => $group['first_seen'],
                'last_seen' => $group['last_seen'],
                'match' => $matches[$index] ?? null,
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
     * @return array<int, array{outcome: string, employee_public_id: string|null, confidence: float, candidates: list<array{employee_public_id: string, employee_name: string, score: float, reasons: array<int, string>}>}>
     */
    private function suggestions(array $groups, Collection $devices, User $viewer): array
    {
        $matches = [];
        foreach (array_slice($groups, 0, self::RESOLVE_LIMIT, true) as $index => $group) {
            $device = $devices->get($group['device_id']);
            if ($device instanceof Device) {
                $matches[$index] = $this->resolver->resolve(
                    $device->tenant_id,
                    IdentitySignals::forDeviceUser($device, $group['badge']),
                );
            }
        }

        return IdentityMatch::visibleTo($matches, $viewer);
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
