<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceMode;
use App\Models\AttendanceSetting;
use App\Models\BranchAttendanceOverride;
use App\Models\Employee;

/**
 * The attendance rules that apply to one person: the organisation's settings,
 * then their branch's overrides, then their attendance mode. Three levels and
 * no more, so "which rule applies here?" has an answer a person can predict.
 *
 * Every rule a punch is checked against is read through here, so a branch
 * that switches selfies off is believed by the phone, the server and the
 * review queue alike. It answers with an unsaved AttendanceSetting, so callers
 * read the same attributes they always did.
 *
 * Only OVERRIDABLE keys vary by branch. Photo retention, the confidence
 * threshold and the overtime rules stay organisation-wide: retention and
 * consent are the organisation's legal duty, and overtime is payroll's.
 */
final class AttendancePolicy
{
    /** The settings a branch may set differently from its organisation. */
    public const OVERRIDABLE = [
        'enabled_methods',
        'geofence_required',
        'mobile_photo_required',
        'kiosk_pin_required',
        'mobile_accuracy_threshold_meters',
        'auto_checkout_enabled',
        'auto_checkout_after_minutes',
        'geofence_auto_checkin_enabled',
    ];

    /**
     * The organisation's own settings, or the defaults when it has not saved
     * any. In the current tenant's scope.
     */
    public function organisation(): AttendanceSetting
    {
        return AttendanceSetting::query()->first()
            ?? new AttendanceSetting(AttendanceSetting::defaults());
    }

    /** The rules at a branch, before any employee's mode. */
    public function forBranch(?int $branchId, ?AttendanceSetting $organisation = null): AttendanceSetting
    {
        $policy = ($organisation ?? $this->organisation())->replicate();

        if ($branchId === null) {
            return $policy;
        }

        $overrides = BranchAttendanceOverride::query()->where('branch_id', $branchId)->value('overrides');

        foreach (self::only(is_array($overrides) ? $overrides : []) as $key => $value) {
            $policy->setAttribute($key, $value);
        }

        return $policy;
    }

    /**
     * The rules for this person: their branch's, then their mode. A remote
     * worker has no place to be, so no geofence can be required of them and
     * none can check them in. A field worker moves between sites, so being
     * away from the branch is flagged for review (IntegrityInspector), never
     * refused.
     */
    public function for(Employee $employee, ?AttendanceSetting $organisation = null): AttendanceSetting
    {
        $policy = $this->forBranch($employee->branch_id, $organisation);

        $mode = self::modeOf($employee);
        if ($mode !== AttendanceMode::OFFICE) {
            $policy->setAttribute('geofence_required', false);
        }
        if ($mode === AttendanceMode::REMOTE) {
            $policy->setAttribute('geofence_auto_checkin_enabled', false);
        }

        return $policy;
    }

    /** An employee's mode, office when none is recorded. */
    public static function modeOf(Employee $employee): AttendanceMode
    {
        $mode = array_key_exists('attendance_mode', $employee->getAttributes()) ? $employee->attendance_mode : null;

        return $mode instanceof AttendanceMode ? $mode : AttendanceMode::OFFICE;
    }

    /**
     * The overridable keys of $values, dropping anything else and every null
     * (null means "inherit").
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function only(array $values): array
    {
        return array_filter(
            array_intersect_key($values, array_flip(self::OVERRIDABLE)),
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
