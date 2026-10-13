<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Contracts\AcceptsDeviceCommands;
use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Enums\EmployeeStatus;
use App\Exceptions\DeviceCommandRejected;
use App\Jobs\DeliverDeviceCommandsJob;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The outbox for commands to devices: set the clock, add or update a person,
 * remove a person.
 *
 * Commands go only to devices with `commands_enabled`, and a person's commands
 * go to the devices of their branch. A device ETHR can call directly gets its
 * commands delivered here; a device that collects them (ZKTeco ADMS) reads the
 * same rows when it next checks in.
 */
final class DeviceCommandQueue
{
    /** A device that keeps refusing a command is not asked a fourth time. */
    public const MAX_ATTEMPTS = 3;

    /** Clocks are set at least this often on devices that take commands. */
    public const CLOCK_EVERY_HOURS = 24;

    /** Statuses that end employment; the person is removed from devices. */
    private const LEAVERS = [EmployeeStatus::RESIGNED, EmployeeStatus::TERMINATED, EmployeeStatus::RETIRED];

    public function __construct(private readonly DeviceManager $devices) {}

    /** Whether this kind of device can carry out commands at all. */
    public function supports(Device $device): bool
    {
        try {
            return $this->devices->adapter($device) instanceof AcceptsDeviceCommands;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** Whether ETHR itself delivers this device's commands, by calling it. */
    public function delivers(Device $device): bool
    {
        return $device->commands_enabled && $this->supports($device);
    }

    /**
     * Add or update the person on every device of their branch.
     *
     * @return int the number of devices a command was queued for
     */
    public function enrol(Employee $employee, ?User $by = null): int
    {
        if (! in_array($employee->status, EmployeeStatus::current(), true)) {
            return 0;
        }

        $queued = 0;
        foreach ($this->branchDevices($employee->branch_id) as $device) {
            if ($this->upsert($device, $employee, $by) !== null) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Send every current employee of the device's branch to this one device —
     * for a terminal newly switched to commands, or one that was reset.
     *
     * @return array{queued: int, skipped: int} skipped: no badge or linked id
     */
    public function sendStaff(Device $device, ?User $by = null): array
    {
        $result = ['queued' => 0, 'skipped' => 0];

        Employee::query()
            ->where('branch_id', $device->branch_id)
            ->whereIn('status', array_map(fn (EmployeeStatus $s) => $s->value, EmployeeStatus::current()))
            ->orderBy('id')
            ->each(function (Employee $employee) use ($device, $by, &$result): void {
                $this->upsert($device, $employee, $by) !== null ? $result['queued']++ : $result['skipped']++;
            });

        return $result;
    }

    /**
     * Keep devices in step with a saved employee. Called after every save, so
     * it acts only on the changes that matter to a device.
     */
    public function employeeSaved(Employee $employee): void
    {
        if (in_array($employee->status, self::LEAVERS, true)) {
            if ($employee->wasChanged('status')) {
                $this->removeFromBranch($employee, $employee->branch_id);
            }

            return;
        }

        $branchChanged = $employee->wasChanged('branch_id');
        if ($branchChanged && $employee->getOriginal('branch_id') !== null) {
            $this->removeFromBranch($employee, (int) $employee->getOriginal('branch_id'));
        }

        // wasRecentlyCreated stays true for the life of the instance, so a
        // create is told apart by having no changes: only an update records them.
        $created = $employee->wasRecentlyCreated && $employee->getChanges() === [];

        if ($created
            || $branchChanged
            || $employee->wasChanged(['name', 'badge_number', 'status'])) {
            $this->enrol($employee);
        }
    }

    /** Remove the person from every device of their branch. */
    public function employeeDeleted(Employee $employee): void
    {
        $this->removeFromBranch($employee, $employee->branch_id);
    }

    public function setTime(Device $device, ?User $by = null): DeviceCommand
    {
        $pending = $device->commands()
            ->where('type', DeviceCommandType::SET_TIME->value)
            ->where('status', DeviceCommandStatus::PENDING->value)
            ->first();

        return $pending ?? $this->queue($device, DeviceCommandType::SET_TIME, null, null, $by);
    }

    /**
     * Deliver a device's pending commands, oldest first. Stops at the first
     * command the device could not be reached for: the rest would fail the
     * same way.
     *
     * @return array{done: int, failed: int, pending: int}
     */
    public function deliver(Device $device): array
    {
        $result = ['done' => 0, 'failed' => 0, 'pending' => 0];
        $adapter = $this->devices->adapter($device);

        if (! $device->commands_enabled || ! $adapter instanceof AcceptsDeviceCommands) {
            return $result;
        }

        $this->setTimeIfDue($device);

        $commands = $device->commands()
            ->where('status', DeviceCommandStatus::PENDING->value)
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($commands as $command) {
            try {
                $adapter->execute($device, $command);
            } catch (DeviceCommandRejected $e) {
                $attempts = $command->attempts + 1;
                $gaveUp = $attempts >= self::MAX_ATTEMPTS;
                $command->update([
                    'attempts' => $attempts,
                    'last_error' => mb_substr($e->getMessage(), 0, 500),
                    'status' => $gaveUp ? DeviceCommandStatus::FAILED : DeviceCommandStatus::PENDING,
                    'completed_at' => $gaveUp ? now() : null,
                ]);
                $gaveUp ? $result['failed']++ : $result['pending']++;

                continue;
            } catch (Throwable $e) {
                $command->update(['last_error' => mb_substr('Not reached: '.$e->getMessage(), 0, 500)]);
                $result['pending'] += $commands->count() - $result['done'] - $result['failed'];

                return $result;
            }

            $command->update([
                'status' => DeviceCommandStatus::DONE,
                'attempts' => $command->attempts + 1,
                'last_error' => null,
                'sent_at' => now(),
                'completed_at' => now(),
            ]);
            $result['done']++;
        }

        return $result;
    }

    /**
     * The id the device knows this person by: the one already linked to this
     * device, else the badge number. Null when there is neither, so nothing
     * can be sent.
     */
    public function deviceUserId(Device $device, Employee $employee): ?string
    {
        $linked = EmployeeExternalIdentity::query()
            ->where('employee_id', $employee->id)
            ->where('source_type', $device->adapter_type)
            ->where('source_ref', 'device:'.$device->id)
            ->where('identifier_type', 'device_user_id')
            ->value('identifier_value');

        $id = $linked ?? $employee->badge_number;

        return $id === null || trim((string) $id) === '' ? null : trim((string) $id);
    }

    private function upsert(Device $device, Employee $employee, ?User $by): ?DeviceCommand
    {
        $userId = $this->deviceUserId($device, $employee);
        if ($userId === null) {
            return null;
        }

        return $this->queue($device, DeviceCommandType::UPSERT_USER, $employee, [
            'device_user_id' => $userId,
            // Terminals keep short names: Hikvision allows 32 characters.
            'name' => mb_substr((string) $employee->name, 0, 32),
        ], $by);
    }

    private function removeFromBranch(Employee $employee, ?int $branchId): void
    {
        foreach ($this->branchDevices($branchId) as $device) {
            $userId = $this->deviceUserId($device, $employee);
            if ($userId !== null) {
                $this->queue($device, DeviceCommandType::DELETE_USER, $employee, ['device_user_id' => $userId]);
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function queue(Device $device, DeviceCommandType $type, ?Employee $employee, ?array $payload, ?User $by = null): DeviceCommand
    {
        // A newer command for the same person replaces one the device has not
        // picked up yet: two edits in a row should reach it once, and an add
        // followed by a removal should not add.
        if ($employee !== null) {
            $device->commands()
                ->where('employee_id', $employee->id)
                ->where('status', DeviceCommandStatus::PENDING->value)
                ->update(['status' => DeviceCommandStatus::CANCELLED->value, 'completed_at' => now()]);
        }

        $command = $device->commands()->create([
            'tenant_id' => $device->tenant_id,
            'employee_id' => $employee?->id,
            'type' => $type,
            'payload' => $payload,
            'status' => DeviceCommandStatus::PENDING,
            'requested_by' => $by?->id,
        ]);

        if ($this->delivers($device)) {
            DeliverDeviceCommandsJob::dispatch($device)->afterCommit();
        }

        return $command;
    }

    private function setTimeIfDue(Device $device): void
    {
        $recent = $device->commands()
            ->where('type', DeviceCommandType::SET_TIME->value)
            ->where(fn ($q) => $q
                ->where('status', DeviceCommandStatus::PENDING->value)
                ->orWhere('created_at', '>=', now()->subHours(self::CLOCK_EVERY_HOURS)))
            ->exists();

        if (! $recent) {
            $device->commands()->create([
                'tenant_id' => $device->tenant_id,
                'type' => DeviceCommandType::SET_TIME,
                'status' => DeviceCommandStatus::PENDING,
            ]);
        }
    }

    /** @return Collection<int, Device> */
    private function branchDevices(?int $branchId): Collection
    {
        if ($branchId === null) {
            return new Collection;
        }

        return Device::query()
            ->where('branch_id', $branchId)
            ->where('commands_enabled', true)
            ->get();
    }
}
