<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceMode;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSite;
use App\Models\Branch;
use App\Models\BranchAttendanceOverride;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\KioskSession;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRotation;
use App\Models\Tenant;
use App\Support\TenantTime;
use App\Support\WorkingWeek;
use Illuminate\Support\Carbon;

/**
 * What is keeping attendance from working, for the current organisation, as
 * a list a person can act on: each check says how many things it found and
 * where they are fixed. A blocker means punches are rated wrong or refused;
 * a warning means part of the setup will not do what it looks like; info is
 * worth knowing.
 *
 * Deterministic and cheap: a handful of counts, no scoring. The same state
 * always gives the same list. Profile-aware where it matters: crews with no
 * rotation are a blocker for a round-the-clock organisation and a warning
 * elsewhere.
 */
final class AttendanceHealth
{
    /** Profiles whose people work in crews, where an unassigned rotation is the normal mistake. */
    private const CREW_PROFILES = ['continuous_24_7', 'rotating_three_shift'];

    public function __construct(private readonly AttendancePolicy $policy) {}

    /**
     * @return list<array{id: string, severity: string, count: int, fix_route: string}>
     */
    public function checks(Tenant $tenant): array
    {
        $checks = [];
        $organisation = $this->policy->organisation();
        $overrides = BranchAttendanceOverride::query()->pluck('overrides', 'branch_id');
        $branches = Branch::query()->where('is_active', true)->get(['id', 'latitude', 'longitude', 'geofence_radius_meters']);
        $unplaced = $branches->filter(fn (Branch $b): bool => $b->latitude === null || $b->longitude === null || (int) $b->geofence_radius_meters <= 0);
        $activeEmployees = Employee::query()->whereNotIn('status', [EmployeeStatus::RESIGNED, EmployeeStatus::TERMINATED, EmployeeStatus::RETIRED]);

        // No default schedule: everyone without a shift of their own punches
        // PENDING, with no lateness, early leave or overtime.
        $hasDefault = Shift::query()->where('is_default', true)->where('is_active', true)->exists()
            || ShiftRotation::query()->where('is_default', true)->where('is_active', true)->exists();
        if (! $hasDefault) {
            $checks[] = $this->check('no_default_schedule', 'blocker', 1, '/shifts');
        }

        // A required geofence at a branch with no place to check against.
        $requiresAt = fn (int $branchId): bool => (bool) ($this->policy->forBranch($branchId, $organisation)->geofence_required);
        $requiredUnplaced = $unplaced->filter(fn (Branch $b): bool => $requiresAt($b->id))->count();
        if ($requiredUnplaced > 0) {
            $checks[] = $this->check('geofence_unplaced', 'blocker', $requiredUnplaced, '/organization?tab=branches');
        }

        // Phones in use with nothing on the map: no punch can be checked.
        $mobileAnywhere = $organisation->isMethodEnabled('mobile')
            || $overrides->contains(fn ($o): bool => in_array('mobile', (array) ($o['enabled_methods'] ?? []), true));
        $placesExist = $branches->count() > $unplaced->count() || AttendanceSite::query()->where('is_active', true)->exists();
        if ($mobileAnywhere && ! $placesExist && $branches->isNotEmpty()) {
            $checks[] = $this->check('nothing_on_map', 'warning', $branches->count(), '/organization?tab=branches');
        }

        // A crew rotation nobody is on.
        $idleRotations = ShiftRotation::query()
            ->where('is_active', true)
            ->where('is_default', false)
            ->whereDoesntHave('assignments', fn ($q) => $q->where(fn ($w) => $w->whereNull('effective_to')->orWhereDate('effective_to', '>=', ShiftAssignment::tenantToday())))
            ->count();
        if ($idleRotations > 0) {
            $profile = (string) data_get($tenant->settings, 'configuration_profile.workforce_profile', data_get($tenant->settings, 'attendance_baseline.profile', ''));
            $checks[] = $this->check('crews_unassigned', in_array($profile, self::CREW_PROFILES, true) ? 'blocker' : 'warning', $idleRotations, '/shifts/rotations');
        }

        // Punches in the last week that had no shift to be rated against.
        $zone = TenantTime::zone($tenant);
        $pending = AttendanceRecord::query()
            ->where('status', AttendanceStatus::PENDING)
            ->whereDate('date', '>=', Carbon::now($zone)->subDays(7)->format('Y-m-d'))
            ->count();
        if ($pending > 0) {
            $checks[] = $this->check('pending_punches', 'warning', $pending, '/shifts/assignments');
        }

        // Fingerprint terminals switched on with none registered, or silent.
        if ($organisation->isMethodEnabled('biometric')) {
            $devices = Device::query()->count();
            $silent = Device::query()
                ->where(fn ($q) => $q->whereNull('last_sync_at')->orWhere('last_sync_at', '<', Carbon::now()->subDay()))
                ->count();
            if ($devices > 0 && $silent > 0) {
                $checks[] = $this->check('devices_silent', 'warning', $silent, '/devices');
            }
        }

        // A kiosk PIN required of people who have none.
        $pinBranches = $branches->filter(fn (Branch $b): bool => (bool) $this->policy->forBranch($b->id, $organisation)->kiosk_pin_required)->pluck('id');
        if ($pinBranches->isNotEmpty() && KioskSession::query()->whereIn('branch_id', $pinBranches)->exists()) {
            $withoutPin = (clone $activeEmployees)->whereIn('branch_id', $pinBranches)->whereNull('kiosk_pin')->count();
            if ($withoutPin > 0) {
                $checks[] = $this->check('kiosk_pins_missing', 'warning', $withoutPin, '/employees');
            }
        }

        // Field staff with nowhere to punch but their branch.
        $fieldStaff = (clone $activeEmployees)->where('attendance_mode', AttendanceMode::FIELD->value)->count();
        if ($fieldStaff > 0 && ! AttendanceSite::query()->where('is_active', true)->exists()) {
            $checks[] = $this->check('field_without_sites', 'warning', $fieldStaff, '/settings/attendance');
        }

        // Public holidays: this year, and next year from November.
        $now = Carbon::now($zone);
        $missingYears = 0;
        foreach (array_filter([$now->year, $now->month >= 11 ? $now->year + 1 : null]) as $year) {
            if (! Holiday::query()->whereYear('date', $year)->exists()) {
                $missingYears++;
            }
        }
        if ($missingYears > 0) {
            $checks[] = $this->check('holidays_missing', 'warning', $missingYears, '/settings/holidays');
        }

        // The organisation's week (leave, absences with no shift) and the
        // default shift's days disagree.
        $default = Shift::query()->where('is_default', true)->where('is_active', true)->first();
        if ($default !== null) {
            $week = WorkingWeek::of($tenant);
            $days = $default->workingDaysArray();
            sort($week);
            sort($days);
            if ($week !== $days) {
                $checks[] = $this->check('working_week_mismatch', 'info', 1, '/settings/leave-types');
            }
        }

        // A kiosk method switched on with no kiosk registered.
        if ($organisation->isMethodEnabled('kiosk') && $branches->isNotEmpty() && ! KioskSession::query()->exists() && $organisation->kiosk_pin_required) {
            $checks[] = $this->check('kiosk_none', 'info', 1, '/attendance/kiosks');
        }

        return $checks;
    }

    /** @return array{id: string, severity: string, count: int, fix_route: string} */
    private function check(string $id, string $severity, int $count, string $fixRoute): array
    {
        return ['id' => $id, 'severity' => $severity, 'count' => $count, 'fix_route' => $fixRoute];
    }
}
