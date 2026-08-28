<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TrustedDevice;
use App\Services\Auth\TrustedDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets a user see and withdraw the browsers that skip their MFA prompt
 * (PHASE_00 S03). Trust is *granted* on the MFA verify call, not here — this is
 * the review-and-revoke side.
 */
class TrustedDeviceController extends Controller
{
    public function __construct(private readonly TrustedDeviceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->service->pruneExpired($user);

        $devices = TrustedDevice::where('user_id', $user->id)
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn (TrustedDevice $d) => [
                'id' => $d->id,
                'device_name' => $d->device_name,
                'last_used_at' => $d->last_used_at,
                'expires_at' => $d->expires_at,
            ]);

        return response()->json(['data' => $devices]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        // Scoped to the caller's own rows — an id from another user simply misses.
        $device = TrustedDevice::where('user_id', $user->id)->whereKey($id)->first();

        if ($device === null) {
            return response()->json([
                'type' => 'https://ethr.et/errors/device-not-found',
                'title' => 'Device Not Found',
                'status' => 404,
                'detail' => 'That trusted device no longer exists.',
            ], 404);
        }

        $name = $device->device_name;
        $device->delete();

        AuditLog::record('user.device_untrusted', $user, ['device_name' => $name]);

        return response()->json(['message' => 'Device removed. MFA will be required on it again.']);
    }
}
