<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\UpdateBranchAttendanceRequest;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchAttendanceOverride;
use App\Services\Attendance\AttendancePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Attendance rules per branch: what each branch overrides, and what applies
 * there once the overrides are laid over the organisation's settings.
 */
class BranchAttendanceController extends Controller
{
    public function __construct(private readonly AttendancePolicy $policy) {}

    public function index(): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $organisation = $this->policy->organisation();
        $overrides = BranchAttendanceOverride::query()->pluck('overrides', 'branch_id');

        $branches = Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'public_id', 'name', 'name_am', 'latitude', 'longitude'])
            ->map(fn (Branch $branch): array => $this->present($branch, $organisation, (array) ($overrides[$branch->id] ?? [])))
            ->values();

        return response()->json([
            'organisation' => $this->overridable($organisation),
            'overridable' => AttendancePolicy::OVERRIDABLE,
            'branches' => $branches,
        ]);
    }

    public function update(UpdateBranchAttendanceRequest $request, Branch $branch): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $overrides = AttendancePolicy::only((array) $request->validated('overrides'));
        $organisation = $this->policy->organisation();

        // A value equal to the organisation's is not an override: storing it
        // would pin the branch to today's setting and stop a later change at
        // the organisation from reaching it.
        $overrides = array_filter(
            $overrides,
            static fn (mixed $value, string $key): bool => $value != $organisation->getAttribute($key),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($overrides === []) {
            BranchAttendanceOverride::query()->where('branch_id', $branch->id)->delete();
        } else {
            BranchAttendanceOverride::query()->updateOrCreate(['branch_id' => $branch->id], ['overrides' => $overrides]);
        }

        AuditLog::record('attendance.branch_rules_updated', $branch, ['overrides' => $overrides]);

        return response()->json($this->present($branch, $organisation, $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function present(Branch $branch, AttendanceSetting $organisation, array $overrides): array
    {
        return [
            'public_id' => $branch->public_id,
            'name' => $branch->name,
            'name_am' => $branch->name_am,
            'placed' => (bool) ($branch->latitude !== null && $branch->longitude !== null),
            'overrides' => $this->overridable(new AttendanceSetting(AttendancePolicy::only($overrides)), onlySet: true),
            'effective' => $this->overridable($this->policy->forBranch($branch->id, $organisation)),
        ];
    }

    /**
     * The overridable keys of $setting, typed by the model's casts. With
     * $onlySet, a key the setting leaves unset is null (inherited).
     *
     * @return array{enabled_methods: list<string>|null, geofence_required: bool|null, mobile_photo_required: bool|null, kiosk_pin_required: bool|null, mobile_accuracy_threshold_meters: int|null, auto_checkout_enabled: bool|null, auto_checkout_after_minutes: int|null, geofence_auto_checkin_enabled: bool|null, risk_review_threshold: int|null}
     */
    private function overridable(AttendanceSetting $setting, bool $onlySet = false): array
    {
        $out = [];
        foreach (AttendancePolicy::OVERRIDABLE as $key) {
            $out[$key] = ! $onlySet || array_key_exists($key, $setting->getAttributes())
                ? $setting->getAttribute($key)
                : null;
        }

        /** @var array{enabled_methods: list<string>|null, geofence_required: bool|null, mobile_photo_required: bool|null, kiosk_pin_required: bool|null, mobile_accuracy_threshold_meters: int|null, auto_checkout_enabled: bool|null, auto_checkout_after_minutes: int|null, geofence_auto_checkin_enabled: bool|null, risk_review_threshold: int|null} $out */
        return $out;
    }
}
