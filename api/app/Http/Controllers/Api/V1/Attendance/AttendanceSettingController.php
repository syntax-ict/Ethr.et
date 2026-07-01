<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\UpdateAttendanceSettingRequest;
use App\Http\Resources\AttendanceSettingResource;
use App\Models\AttendanceSetting;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AttendanceSettingController extends Controller
{
    public function show(): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $tenant = app(CurrentTenant::class)->get();
        $setting = AttendanceSetting::firstOrCreate(
            ['tenant_id' => $tenant->id],
            AttendanceSetting::defaults()
        );

        return response()->json((new AttendanceSettingResource($setting))->resolve());
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

        return response()->json((new AttendanceSettingResource($setting->fresh()))->resolve());
    }
}
