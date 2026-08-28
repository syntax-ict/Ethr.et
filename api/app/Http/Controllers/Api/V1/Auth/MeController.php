<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\Profile\ProfilePreferencesController;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\Employee;
use App\Services\CurrentTenant;
use App\Services\FileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __construct(private readonly FileStorageService $storage) {}

    public function __invoke(Request $request, CurrentTenant $currentTenant): JsonResponse
    {
        $user = $request->user();
        /** @var Employee|null $employee */
        $employee = $user->employee;

        return response()->json([
            'user' => [
                'public_id' => $user->public_id,
                'name' => $employee?->name,
                'name_am' => $employee?->name_am,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'status' => $user->status,
                'mfa_enabled' => $user->mfa_enabled,
                'locale' => $user->locale,
                // The shell reads theme/calendar/language from here on boot, so a
                // preference set on one device shows up on the next.
                'preferences' => ProfilePreferencesController::present($user),
                'last_login_at' => $user->last_login_at,
                'employee_code' => $employee?->employee_code,
                'photo_thumb_url' => $this->storage->thumbnailUrlOrNull($employee?->photo_path, 150),
            ],
            'permissions' => $user->permissionNames(),
            'tenant' => $currentTenant->resolved()
                ? new TenantResource($currentTenant->get())
                : null,
        ]);
    }
}
