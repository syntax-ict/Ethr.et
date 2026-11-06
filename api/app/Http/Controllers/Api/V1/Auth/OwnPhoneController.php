<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateOwnPhoneRequest;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;

/**
 * A signed-in person's own phone, on their account. The administrator who
 * registered an organisation has no employee record, so the profile form
 * could not hold it; ETHR's support calls the organisation's owner on it. A
 * new number is unverified until a code confirms it.
 */
class OwnPhoneController extends Controller
{
    public function __invoke(UpdateOwnPhoneRequest $request): JsonResponse
    {
        $user = $request->user();
        $phone = (string) $request->validated('phone');

        if ($user->phone !== $phone) {
            $user->forceFill(['phone' => $phone, 'phone_verified_at' => null])->save();
            AuditLog::record('user.phone_updated', $user);
        }

        return response()->json([
            'phone' => $user->phone,
            'phone_verified' => $user->phone_verified_at !== null,
        ]);
    }
}
