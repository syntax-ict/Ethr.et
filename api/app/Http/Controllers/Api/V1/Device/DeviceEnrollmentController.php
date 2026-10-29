<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Device;
use App\Services\Device\AdmsRoster;
use App\Services\Device\DeviceCommandQueue;
use App\Services\Device\DeviceManager;
use App\Services\Identity\IdentityMatch;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Workforce discovery: read the people enrolled on a device and show, for each,
 * whether they already exist as an internal employee. This is the read-only
 * preview that precedes any migration — nothing is written here. Confirming the
 * suggested links belongs to the migration workspace (Slice 6).
 *
 * See ONBOARDING_V2.md decisions D4–D5.
 */
class DeviceEnrollmentController extends Controller
{
    public function __construct(
        private readonly DeviceManager $manager,
        private readonly IdentityResolver $resolver,
        private readonly DeviceCommandQueue $commands,
        private readonly AdmsRoster $roster,
    ) {}

    public function index(Device $device): JsonResponse
    {
        Gate::authorize('device.view');

        $enrollments = $this->manager->adapter($device)->pullEnrollments($device);

        $summary = [
            IdentityMatch::MATCHED => 0,
            IdentityMatch::PROBABLE => 0,
            IdentityMatch::AMBIGUOUS => 0,
            IdentityMatch::NEW => 0,
        ];

        $rows = [];
        foreach ($enrollments as $enrollment) {
            $userId = $enrollment['device_user_id'];

            $match = $this->resolver->resolve($device->tenant_id, new IdentitySignals(
                employeeCode: $userId,
                badgeNumber: $enrollment['card_number'] ?? $userId,
                name: $enrollment['name'] ?? null,
                sourceType: $device->adapter_type,
                sourceRef: 'device:'.$device->id,
                identifierType: 'device_user_id',
                identifierValue: $userId,
            ));

            $summary[$match->outcome] = ($summary[$match->outcome] ?? 0) + 1;

            $rows[] = [
                'device_user_id' => $userId,
                'name' => $enrollment['name'] ?? null,
                'card_number' => $enrollment['card_number'] ?? null,
                'department' => $enrollment['department'] ?? null,
                'fingerprint_count' => isset($enrollment['fingerprint_count']) ? (int) $enrollment['fingerprint_count'] : null,
                'face_registered' => isset($enrollment['face_registered']) ? (bool) $enrollment['face_registered'] : null,
                'match' => $match->toArray(),
            ];
        }

        return response()->json([
            'device' => [
                'public_id' => $device->public_id,
                'name' => $device->name,
                'adapter_type' => $device->adapter_type,
            ],
            'enrollments' => $rows,
            'summary' => [
                'total' => count($rows),
                'matched' => $summary[IdentityMatch::MATCHED],
                'probable' => $summary[IdentityMatch::PROBABLE],
                'ambiguous' => $summary[IdentityMatch::AMBIGUOUS],
                'new' => $summary[IdentityMatch::NEW],
            ],
            'roster' => $this->roster($device),
        ]);
    }

    /**
     * Ask a ZKTeco ADMS device again who is enrolled on it. It answers at its
     * next check-in, usually within a minute; the enrollments list shows the
     * answer once it has arrived. Changes nothing on the device.
     */
    public function request(Request $request, Device $device): JsonResponse
    {
        Gate::authorize('device.update');

        $command = $this->commands->requestEnrollments($device, $request->user());
        abort_if($command === null, 409, __('device.roster.read_directly'));

        AuditLog::record('device.command.query_users', $device);

        return response()->json(['roster' => $this->roster($device)], 202);
    }

    /**
     * Where the list comes from. `reported`: the device sends it when asked
     * (ZKTeco ADMS), so it is as of `received_at`; `requested_at` is set while
     * a request is waiting for the device. `live`: read from the device now.
     *
     * @return array{source: 'live'|'reported', received_at: string|null, requested_at: string|null, can_request: bool}
     */
    private function roster(Device $device): array
    {
        if (! $this->commands->collects($device)) {
            return ['source' => 'live', 'received_at' => null, 'requested_at' => null, 'can_request' => false];
        }

        $state = $this->roster->state($device);

        return [
            'source' => 'reported',
            'received_at' => $state['received_at']?->toIso8601String(),
            'requested_at' => $state['pending']?->created_at?->toIso8601String(),
            'can_request' => Gate::allows('device.update'),
        ];
    }
}
