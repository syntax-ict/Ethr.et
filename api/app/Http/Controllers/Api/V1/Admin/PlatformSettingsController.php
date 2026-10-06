<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Http\Resources\PlatformSettingResource;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Super-admin management of platform-wide settings.
 *
 * `admin.manage` is held only by SUPER_ADMIN (User::hasPermission short-circuits
 * for that role and no other role is granted it in PermissionSeeder), so these
 * endpoints are platform-operator only.
 */
class PlatformSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        Gate::authorize('admin.manage');

        // The status is pinned to 200 because `current()` uses firstOrCreate: on the
        // very first read that leaves the model `wasRecentlyCreated`, and a bare
        // JsonResource turns that into a 201 — a GET reporting "Created".
        return (new PlatformSettingResource(PlatformSetting::current()))
            ->response()
            ->setStatusCode(200);
    }

    public function update(UpdatePlatformSettingsRequest $request): PlatformSettingResource
    {
        Gate::authorize('admin.manage');

        $settings = PlatformSetting::current();
        $settings->update($request->validated());

        // Changing where every tenant sends money is exactly the kind of action the
        // append-only audit log exists for.
        AuditLog::record('platform.settings.updated', $settings);

        return new PlatformSettingResource($settings->fresh());
    }
}
