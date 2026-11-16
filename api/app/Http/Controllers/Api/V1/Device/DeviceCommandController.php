<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\RetryDeviceCommandsRequest;
use App\Http\Resources\DeviceCommandResource;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Services\Device\DeviceCommandQueue;
use App\Services\Migration\WorkforceMigrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Commands to devices: what is queued and what happened to it, and the two
 * things an administrator asks for by hand — set the clock now, send the whole
 * branch to a terminal — plus "enrol on devices" from an employee's profile.
 *
 * Every write needs `commands_enabled` on the device; that switch is the
 * administrator's consent to ETHR changing what is stored on the terminal.
 */
class DeviceCommandController extends Controller
{
    public function __construct(
        private readonly DeviceCommandQueue $queue,
        private readonly WorkforceMigrationService $migration,
    ) {}

    public function index(Request $request, Device $device): AnonymousResourceCollection
    {
        Gate::authorize('device.view');

        $commands = $device->commands()
            ->with('employee')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        return DeviceCommandResource::collection($commands);
    }

    public function setTime(Request $request, Device $device): JsonResponse
    {
        Gate::authorize('device.update');
        $this->ensureEnabled($device);

        $command = $this->queue->setTime($device, $request->user());
        AuditLog::record('device.command.set_time', $device);

        return (new DeviceCommandResource($command->load('employee')))->response()->setStatusCode(202);
    }

    public function sendStaff(Request $request, Device $device): JsonResponse
    {
        Gate::authorize('device.update');
        $this->ensureEnabled($device);

        $result = $this->queue->sendStaff($device, $request->user());
        AuditLog::record('device.command.send_staff', $device, $result);

        return response()->json($result, 202);
    }

    /**
     * Send again the people whose newest add-or-update on this device failed,
     * all of them or only those a list brought in. The terminal's number
     * rules apply once more: a number it does not take is still not sent.
     */
    public function retry(RetryDeviceCommandsRequest $request, Device $device): JsonResponse
    {
        Gate::authorize('device.update');
        $this->ensureEnabled($device);
        abort_unless($this->queue->supports($device), 409, 'ETHR cannot send people to this device.');

        $batchId = $request->validated('batch_public_id');
        $only = null;

        if (is_string($batchId)) {
            $batch = MigrationBatch::query()->where('public_id', $batchId)->firstOrFail();
            $only = $this->migration->employees($batch)->pluck('id')->all();
        }

        $result = $this->queue->resend($device, $this->queue->failedPeople($device, $only), $request->user());
        AuditLog::record('device.command.retry', $device, $result + ['batch' => $batchId]);

        return response()->json($result, 202);
    }

    public function enrolEmployee(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('device.update');

        $queued = $this->queue->enrol($employee, $request->user());
        AuditLog::record('employee.device_enrolment', $employee, ['devices' => $queued]);

        return response()->json(['devices' => $queued], 202);
    }

    private function ensureEnabled(Device $device): void
    {
        abort_unless($device->commands_enabled, 409, 'Turn on "Send commands to this device" first.');
    }
}
