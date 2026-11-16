<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Enums\DeviceCommandStatus;
use App\Enums\EmployeeStatus;
use App\Http\Controllers\Api\V1\Device\UnmatchedPunchController;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Models\UnmatchedDevicePunch;
use App\Services\Device\DeviceCommandQueue;
use App\Services\Device\DeviceIdRules;
use App\Services\Device\DeviceManager;
use App\Services\Identity\IdentityMatch;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the attendance terminals hold for the people a list brought in.
 *
 * Nothing is sent from here and nothing is changed: the people reached the
 * terminals when they were saved (DeviceCommandQueue::employeeSaved), and this
 * reads what became of that. A fixed number of queries whatever the size of
 * the list: the people's branches' devices, the ids those devices reported,
 * and each person's newest command on each device.
 *
 * It also says which held punches the list has just made placeable (held()),
 * for a viewer who may assign them: one resolver lookup for each held badge of
 * the people it names, at most `heldLimit` of them.
 */
final class MigrationTerminals
{
    public function __construct(
        private readonly DeviceCommandQueue $queue,
        private readonly IdentityResolver $resolver,
        private readonly int $heldLimit = UnmatchedPunchController::RESOLVE_LIMIT,
    ) {}

    /**
     * The answer for a viewer who may not see device information.
     *
     * @return array{visible: bool, devices: list<array<string, mixed>>, without_number: int, without_branch: int, can_assign: bool, held: list<array{device_public_id: string, device_name: string, badge: string, punches: int, employee_public_id: string, employee_name: string}>, held_more: int}
     */
    public function hidden(): array
    {
        return ['visible' => false, 'devices' => [], 'without_number' => 0, 'without_branch' => 0, 'can_assign' => false, 'held' => [], 'held_more' => 0];
    }

    /**
     * @param  Collection<int, Employee>  $people  the batch's people the viewer may open: nobody else is named or counted
     * @param  bool  $canAssign  the viewer holds `attendance.manage`; without it nothing is looked up or listed
     * @return array{visible: bool, devices: list<array<string, mixed>>, without_number: int, without_branch: int, can_assign: bool, held: list<array{device_public_id: string, device_name: string, badge: string, punches: int, employee_public_id: string, employee_name: string}>, held_more: int}
     */
    public function read(Collection $people, int $tenantId, bool $canAssign = false): array
    {
        $current = $people
            ->filter(fn (Employee $e): bool => in_array($e->status, EmployeeStatus::current(), true))
            ->values();

        $answer = [
            'visible' => true,
            'devices' => [],
            'without_number' => 0,
            'without_branch' => $current->whereNull('branch_id')->count(),
            'can_assign' => $canAssign,
            'held' => [],
            'held_more' => 0,
        ];

        if ($canAssign) {
            ['held' => $answer['held'], 'held_more' => $answer['held_more']] = $this->held($people, $tenantId);
        }

        $branchIds = $current->pluck('branch_id')->filter()->unique()->values()->all();
        $devices = $branchIds === []
            ? new Collection
            : Device::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('branch_id', $branchIds)
                ->orderBy('name')
                ->orderBy('id')
                ->get();

        if ($devices->isEmpty()) {
            return $answer;
        }

        $ids = $this->linkedIds($tenantId, $current, $devices);
        $newest = $this->newestCommands($tenantId, $current, $devices);
        $reached = [];

        foreach ($devices as $device) {
            $writable = $device->commands_enabled && $this->queue->supports($device);
            $row = [
                'device_public_id' => $device->public_id,
                'device_name' => $device->name,
                'adapter_type' => $device->adapter_type,
                'writable' => $writable,
                'receives_only' => DeviceManager::receivesOnly($device),
                'queued' => 0,
                'done' => 0,
                'failed' => 0,
                'not_sent' => 0,
                'last_error' => null,
                'enrol_by_hand' => [],
            ];
            $lastErrorId = 0;

            foreach ($current->where('branch_id', $device->branch_id) as $employee) {
                $linked = $ids[$employee->id.'|'.$device->adapter_type.'|device:'.$device->id] ?? null;
                $userId = DeviceCommandQueue::chooseUserId($linked, $employee->badge_number);

                if ($userId !== null) {
                    $reached[$employee->id] = true;
                }

                $command = $newest[$device->id.'|'.$employee->id] ?? null;

                if ($command !== null) {
                    switch ($command->status) {
                        case DeviceCommandStatus::PENDING:
                        case DeviceCommandStatus::SENT:
                            $row['queued']++;
                            break;
                        case DeviceCommandStatus::DONE:
                            $row['done']++;
                            break;
                        case DeviceCommandStatus::FAILED:
                            if (DeviceCommandQueue::isNotSent($command->last_error)) {
                                $row['not_sent']++;
                            } else {
                                $row['failed']++;
                                if ($command->id > $lastErrorId) {
                                    $lastErrorId = $command->id;
                                    $row['last_error'] = $command->last_error;
                                }
                            }
                            break;
                        case DeviceCommandStatus::CANCELLED:
                            break;
                    }
                }

                if (! $writable && $userId !== null && ($linked !== null || DeviceIdRules::accepts($device->adapter_type, $userId))) {
                    $row['enrol_by_hand'][] = [
                        'employee_public_id' => $employee->public_id,
                        'name' => $employee->name,
                        'device_user_id' => $userId,
                    ];
                }
            }

            $answer['devices'][] = $row;
        }

        $withDevices = $devices->pluck('branch_id')->unique()->all();
        $answer['without_number'] = $current
            ->filter(fn (Employee $e): bool => in_array($e->branch_id, $withDevices, true) && ! isset($reached[$e->id]))
            ->count();

        return $answer;
    }

    /**
     * Held badges that are, by the resolver the Devices page uses, now
     * exactly one of these people.
     *
     * Narrowed first, in one query, to held groups whose badge equals (as the
     * resolver compares, ignoring case) a number these people hold: the badge
     * number on their record, or one a terminal reported for them. Then at
     * most `heldLimit` of those are resolved, newest first; the rest are only
     * counted in `held_more`. Only a `matched` outcome that names one of
     * `$people` is listed, so an ambiguous or probable badge, and one that is
     * somebody else's, never is.
     *
     * @param  Collection<int, Employee>  $people  the people the viewer may open
     * @return array{held: list<array{device_public_id: string, device_name: string, badge: string, punches: int, employee_public_id: string, employee_name: string}>, held_more: int}
     */
    private function held(Collection $people, int $tenantId): array
    {
        $none = ['held' => [], 'held_more' => 0];

        if ($people->isEmpty()) {
            return $none;
        }

        $anywhere = [];
        foreach ($people as $employee) {
            $badge = mb_strtolower(trim((string) $employee->badge_number));
            if ($badge !== '') {
                $anywhere[$badge] = true;
            }
        }

        $onDevice = [];
        EmployeeExternalIdentity::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $people->pluck('id')->all())
            ->where('identifier_type', 'device_user_id')
            ->where('source_ref', 'like', 'device:%')
            ->get(['source_ref', 'identifier_value'])
            ->each(function (EmployeeExternalIdentity $identity) use (&$onDevice): void {
                $value = mb_strtolower(trim((string) $identity->identifier_value));
                if ($value !== '') {
                    $onDevice[substr($identity->source_ref, 7).'|'.$value] = true;
                }
            });

        $numbers = array_values(array_unique([
            ...array_map('strval', array_keys($anywhere)),
            ...array_map(static fn (string $key): string => substr($key, (int) strpos($key, '|') + 1), array_map('strval', array_keys($onDevice))),
        ]));

        if ($numbers === []) {
            return $none;
        }

        $groups = UnmatchedDevicePunch::query()
            ->where('tenant_id', $tenantId)
            ->where('status', UnmatchedDevicePunch::PENDING)
            ->whereIn(DB::raw('LOWER(badge)'), $numbers)
            ->select(['device_id', 'badge', DB::raw('COUNT(*) as punches'), DB::raw('MAX(created_at) as last_seen')])
            ->groupBy('device_id', 'badge')
            ->orderByDesc('last_seen')
            ->orderBy('device_id')
            ->orderBy('badge')
            ->get()
            ->filter(function (UnmatchedDevicePunch $group) use ($anywhere, $onDevice): bool {
                $badge = mb_strtolower((string) $group->getAttribute('badge'));

                return isset($anywhere[$badge]) || isset($onDevice[$group->getAttribute('device_id').'|'.$badge]);
            })
            ->values();

        if ($groups->isEmpty()) {
            return $none;
        }

        $devices = Device::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $groups->pluck('device_id')->unique()->all())
            ->get()
            ->keyBy('id');
        $byId = $people->keyBy('id');
        $held = [];

        foreach ($groups->take($this->heldLimit) as $group) {
            $device = $devices->get($group->getAttribute('device_id'));
            if (! $device instanceof Device) {
                continue;
            }

            $badge = (string) $group->getAttribute('badge');
            $match = $this->resolver->resolve($tenantId, IdentitySignals::forDeviceUser($device, $badge));
            $employee = $match->outcome === IdentityMatch::MATCHED ? $match->employee : null;

            if ($employee === null || ! $byId->has($employee->id)) {
                continue;
            }

            $held[] = [
                'device_public_id' => (string) $device->public_id,
                'device_name' => (string) $device->name,
                'badge' => $badge,
                'punches' => (int) $group->getAttribute('punches'),
                'employee_public_id' => (string) $employee->public_id,
                'employee_name' => (string) $employee->name,
            ];
        }

        return ['held' => $held, 'held_more' => max(0, $groups->count() - $this->heldLimit)];
    }

    /**
     * The ids the devices themselves reported for these people, by
     * "employee|type|source" so each device's own id is found.
     *
     * @param  Collection<int, Employee>  $people
     * @param  Collection<int, Device>  $devices
     * @return array<string, string>
     */
    private function linkedIds(int $tenantId, Collection $people, Collection $devices): array
    {
        $found = [];

        EmployeeExternalIdentity::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $people->pluck('id')->all())
            ->where('identifier_type', 'device_user_id')
            ->whereIn('source_ref', $devices->map(fn (Device $d): string => 'device:'.$d->id)->all())
            ->get(['employee_id', 'source_type', 'source_ref', 'identifier_value'])
            ->each(function (EmployeeExternalIdentity $identity) use (&$found): void {
                $value = trim((string) $identity->identifier_value);
                if ($value !== '') {
                    $found[$identity->employee_id.'|'.$identity->source_type.'|'.$identity->source_ref] = $value;
                }
            });

        return $found;
    }

    /**
     * Each person's newest add-or-update on each device, by "device|employee".
     *
     * @param  Collection<int, Employee>  $people
     * @param  Collection<int, Device>  $devices
     * @return array<string, DeviceCommand>
     */
    private function newestCommands(int $tenantId, Collection $people, Collection $devices): array
    {
        $found = [];

        DeviceCommand::query()
            ->newestUpsert($tenantId, $devices->pluck('id')->all(), $people->pluck('id')->all())
            ->get(['id', 'device_id', 'employee_id', 'status', 'last_error'])
            ->each(function (DeviceCommand $command) use (&$found): void {
                $found[$command->device_id.'|'.$command->employee_id] = $command;
            });

        return $found;
    }
}
