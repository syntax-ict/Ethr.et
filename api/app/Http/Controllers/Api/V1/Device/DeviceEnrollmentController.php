<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Device\DeviceManager;
use App\Services\Identity\IdentityMatch;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use Illuminate\Http\JsonResponse;
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
                'fingerprint_count' => $enrollment['fingerprint_count'] ?? null,
                'face_registered' => $enrollment['face_registered'] ?? null,
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
        ]);
    }
}
