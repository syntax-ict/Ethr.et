<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\UpdateAttendanceSettingRequest;
use App\Http\Resources\AttendanceSettingResource;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AttendanceSettingController extends Controller
{
    public function show(): JsonResponse
    {
        Gate::authorize('attendance.manage');

        // The first read creates the defaults. The status is set to 200 below
        // because a resource wrapping a model created in this request would
        // otherwise answer that GET with 201.
        $tenant = app(CurrentTenant::class)->get();
        $setting = AttendanceSetting::firstOrCreate(
            ['tenant_id' => $tenant->id],
            AttendanceSetting::defaults()
        );

        return (new AttendanceSettingResource($setting))->response()->setStatusCode(200);
    }

    public function update(UpdateAttendanceSettingRequest $request): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $tenant = app(CurrentTenant::class)->get();
        $setting = AttendanceSetting::firstOrCreate(
            ['tenant_id' => $tenant->id],
            AttendanceSetting::defaults()
        );

        $setting->update($request->validated());

        AuditLog::record('attendance.settings_updated', $setting, $request->validated());

        return (new AttendanceSettingResource($setting->fresh()))->response()->setStatusCode(200);
    }
}
