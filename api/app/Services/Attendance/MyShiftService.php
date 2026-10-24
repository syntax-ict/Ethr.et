<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceMode;
use App\Enums\LeaveStatus;
use App\Http\Controllers\Api\V1\Profile\ProfilePreferencesController;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AttendanceRules;
use App\Support\PhotoConsent;
use App\Support\TenantTime;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * What an employee's phone needs to help them punch: today's shift as instants,
 * whether they are in, the organisation's punching rules, and the assistant's
 * switches - theirs and the organisation's.
 *
 * Shift times are wall-clock times in the tenant's zone; they are returned as
 * UTC instants as well, so a device in any zone schedules its reminders for
 * the right moment.
 */
final class MyShiftService
{
    public function __construct(
        private readonly ShiftMatcher $shiftMatcher,
        private readonly AttendancePolicy $policy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forEmployee(Employee $employee, User $user, Tenant $tenant): array
    {
        $zone = TenantTime::zone($tenant);
        $now = Carbon::now($zone);

        // A check-in still open from yesterday's shift (a night shift, or
        // overtime past midnight) is today's business until it is closed.
        $open = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where('check_in', '>=', Carbon::now('UTC')->subHours(AttendanceRules::MAX_OPEN_HOURS))
            ->with('shift')
            ->latest('check_in')
            ->first();

        // The record's `date` is already the tenant's calendar day.
        $day = $open?->date !== null
            ? Carbon::parse($open->date->format('Y-m-d'), $zone)
            : $now->copy()->startOfDay();

        $shift = $open !== null && $open->shift !== null ? $open->shift : $this->shiftMatcher->match($employee, $now);

        $record = $open ?? AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $day->format('Y-m-d'))
            ->latest('check_in')
            ->first();

        // This person's rules: their branch and mode may differ.
        $policy = $this->policy->for($employee);
        $prefs = ProfilePreferencesController::present($user);

        $holiday = $this->holiday($employee, $day);
        $onLeave = $this->onLeave($employee, $day);
        $working = (bool) ($shift !== null && $shift->isWorkingDay($day) && $holiday === null && ! $onLeave);

        // A remote worker has no place to be shown or measured against.
        $mode = AttendancePolicy::modeOf($employee);
        $branch = $mode === AttendanceMode::REMOTE ? null : $employee->branch;
        $geofence = $branch !== null && $branch->latitude !== null && $branch->longitude !== null
            ? [
                'latitude' => (float) $branch->latitude,
                'longitude' => (float) $branch->longitude,
                'radius_meters' => (int) $branch->geofence_radius_meters,
            ]
            : null;

        return [
            'date' => $day->format('Y-m-d'),
            'timezone' => $zone,
            'working_day' => $working,
            'holiday' => $holiday,
            'on_leave' => $onLeave,
            'shift' => $shift === null ? null : $this->shiftWindow($shift, $day),
            'attendance' => [
                'status' => $record === null
                    ? 'not_checked_in'
                    : ($record->check_out === null ? 'checked_in' : 'checked_out'),
                'record_public_id' => $record?->public_id,
                'check_in' => $record?->check_in?->toIso8601String(),
                'check_out' => $record?->check_out?->toIso8601String(),
                'status_label' => $record?->status?->value,
                'auto_checked_out' => (bool) ($record?->metadata['auto_checkout'] ?? false),
            ],
            'policy' => [
                'mobile_enabled' => $policy->isMethodEnabled('mobile'),
                'web_enabled' => $policy->isMethodEnabled('web'),
                'qr_enabled' => $policy->isMethodEnabled('qr'),
                'attendance_mode' => $mode->value,
                'geofence_required' => (bool) $policy->geofence_required,
                'photo_required' => (bool) $policy->mobile_photo_required,
                'photo_consent' => PhotoConsent::given($user),
                'accuracy_threshold_meters' => (int) $policy->mobile_accuracy_threshold_meters,
                'offline_sync_enabled' => (bool) $policy->offline_sync_enabled,
                'geofence' => $geofence,
            ],
            'assistant' => [
                'reminders' => (bool) ($policy->reminders_enabled && $prefs['punch_reminders']),
                'reminders_allowed' => (bool) $policy->reminders_enabled,
                'reminder_lead_minutes' => (int) $policy->reminder_lead_minutes,
                'auto_check_in' => (bool) ($policy->geofence_auto_checkin_enabled && $prefs['auto_check_in'] && $geofence !== null),
                'auto_check_in_allowed' => (bool) ($policy->geofence_auto_checkin_enabled && $geofence !== null),
                'auto_checkout' => (bool) $policy->auto_checkout_enabled,
                'auto_checkout_after_minutes' => (int) $policy->auto_checkout_after_minutes,
            ],
        ];
    }

    /**
     * The shift's start and end on the given day, as wall clock and as instants.
     *
     * @return array<string, mixed>
     */
    public function shiftWindow(Shift $shift, CarbonInterface $day): array
    {
        [$start, $end] = self::window($shift, $day);

        return [
            'public_id' => $shift->public_id,
            'name' => $shift->name,
            'start_time' => substr((string) $shift->start_time, 0, 5),
            'end_time' => substr((string) $shift->end_time, 0, 5),
            'crosses_midnight' => (bool) $shift->crosses_midnight,
            'grace_minutes' => (int) $shift->grace_minutes,
            'early_departure_minutes' => (int) $shift->early_departure_minutes,
            'starts_at' => $start->copy()->utc()->toIso8601String(),
            'ends_at' => $end->copy()->utc()->toIso8601String(),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon} start and end in the day's zone
     */
    public static function window(Shift $shift, CarbonInterface $day): array
    {
        return $shift->windowOn($day->format('Y-m-d'), $day->getTimezone()->getName());
    }

    /** @return array{name: string, name_am: string|null}|null */
    private function holiday(Employee $employee, CarbonInterface $day): ?array
    {
        $holiday = Holiday::query()
            ->whereDate('date', $day->format('Y-m-d'))
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $employee->branch_id))
            ->first();

        return $holiday === null ? null : ['name' => $holiday->name, 'name_am' => $holiday->name_am];
    }

    private function onLeave(Employee $employee, CarbonInterface $day): bool
    {
        return LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', LeaveStatus::APPROVED)
            ->whereDate('start_date', '<=', $day->format('Y-m-d'))
            ->whereDate('end_date', '>=', $day->format('Y-m-d'))
            ->exists();
    }
}
