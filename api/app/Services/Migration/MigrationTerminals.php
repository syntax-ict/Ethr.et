<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Enums\DeviceCommandStatus;
use App\Enums\EmployeeStatus;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Services\Device\DeviceCommandQueue;
use App\Services\Device\DeviceIdRules;
use App\Services\Device\DeviceManager;
use Illuminate\Support\Collection;

/**
 * What the attendance terminals hold for the people a list brought in.
 *
 * Nothing is sent from here and nothing is changed: the people reached the
 * terminals when they were saved (DeviceCommandQueue::employeeSaved), and this
 * reads what became of that. A fixed number of queries whatever the size of
 * the list: the people's branches' devices, the ids those devices reported,
 * and each person's newest command on each device.
 */
final class MigrationTerminals
{
    public function __construct(private readonly DeviceCommandQueue $queue) {}

    /**
     * The answer for a viewer who may not see device information.
     *
     * @return array{visible: bool, devices: list<array<string, mixed>>, without_number: int, without_branch: int}
     */
    public function hidden(): array
    {
        return ['visible' => false, 'devices' => [], 'without_number' => 0, 'without_branch' => 0];
    }

    /**
     * @param  Collection<int, Employee>  $people  the batch's people the viewer may open: nobody else is named or counted
     * @return array{visible: bool, devices: list<array<string, mixed>>, without_number: int, without_branch: int}
     */
    public function read(Collection $people, int $tenantId): array
    {
        $current = $people
            ->filter(fn (Employee $e): bool => in_array($e->status, EmployeeStatus::current(), true))
            ->values();

        $answer = [
            'visible' => true,
            'devices' => [],
            'without_number' => 0,
            'without_branch' => $current->whereNull('branch_id')->count(),
        ];

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
