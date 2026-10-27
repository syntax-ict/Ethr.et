<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\UpdateAttendanceSettingRequest;
use App\Http\Resources\AttendanceSettingResource;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Services\Attendance\AttendanceHealth;
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

        // Whether lateness may be deducted from pay is a payroll decision, not
        // an attendance one. Asked only when it changes: the settings page
        // sends every field, and HR saving the rest must not need it.
        $tenant = app(CurrentTenant::class)->get();
        $stored = AttendanceSetting::query()->where('tenant_id', $tenant->id)->first();
        $changesLateness = collect(['lateness_deduction_enabled', 'lateness_deduction_basis'])
            ->contains(fn (string $key): bool => $request->has($key)
                && $request->validated($key) != ($stored?->getAttribute($key) ?? AttendanceSetting::defaults()[$key]));

        if ($changesLateness) {
            Gate::authorize('payroll.manageConfig');
        }

        $tenant = app(CurrentTenant::class)->get();
        $setting = AttendanceSetting::firstOrCreate(
            ['tenant_id' => $tenant->id],
            AttendanceSetting::defaults()
        );

        $setting->update($request->validated());

        AuditLog::record('attendance.settings_updated', $setting, $request->validated());

        return (new AttendanceSettingResource($setting->fresh()))->response()->setStatusCode(200);
    }

    /**
     * What is keeping attendance from working here, each with where it is
     * fixed (AttendanceHealth). `ready` is false while any blocker remains.
     */
    public function setupStatus(AttendanceHealth $health): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $checks = $health->checks(app(CurrentTenant::class)->get());

        return response()->json([
            'ready' => ! collect($checks)->contains('severity', 'blocker'),
            'checks' => $checks,
        ]);
    }
}
