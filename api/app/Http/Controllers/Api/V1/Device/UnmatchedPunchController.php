<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\AssignUnmatchedBadgeRequest;
use App\Http\Requests\Device\DismissUnmatchedBadgeRequest;
use App\Models\Device;
use App\Models\Employee;
use App\Services\Device\DevicePunchRecorder;
use App\Services\Device\UnmatchedPunches;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Badges a device sent that no employee matched, with the punches held for
 * them (UnmatchedPunches). Assigning a badge to an employee replays them;
 * dismissing drops them.
 */
class UnmatchedPunchController extends Controller
{
    public function __construct(private readonly UnmatchedPunches $unmatched) {}

    public function index(): JsonResponse
    {
        Gate::authorize('device.viewAny');

        $groups = $this->unmatched->groups();
        $devices = Device::query()
            ->whereIn('id', array_unique(array_column($groups, 'device_id')))
            ->get(['id', 'public_id', 'name'])
            ->keyBy('id');

        $data = [];
        foreach ($groups as $group) {
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
            ];
        }

        return response()->json(['data' => $data]);
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
