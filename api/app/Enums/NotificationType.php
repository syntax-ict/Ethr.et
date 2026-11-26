<?php

declare(strict_types=1);

namespace App\Enums;

/** The notification types a user can set per-channel preferences for. */
enum NotificationType: string
{
    case LEAVE_REQUESTED = 'leave_requested';

    case LEAVE_APPROVED = 'leave_approved';

    case LEAVE_REJECTED = 'leave_rejected';

    case LEAVE_CHANGED = 'leave_changed';

    case ATTENDANCE_CORRECTION = 'attendance_correction';

    case OVERTIME_REQUEST = 'overtime_request';

    case ATTENDANCE_ANOMALY = 'attendance_anomaly';

    case PAYSLIP_AVAILABLE = 'payslip_available';

    case PAYROLL_PROCESSED = 'payroll_processed';

    case ANNOUNCEMENT = 'announcement';

    case APPROVAL_REMINDER = 'approval_reminder';

    case EXPIRY_REMINDER = 'expiry_reminder';

    case PROFILE_UPDATE = 'profile_update';

    case EMPLOYEE_VOICE = 'employee_voice';

    case LETTER = 'letter';

    /**
     * The channels, in the preferences API's vocabulary, that notifications of this
     * type can reach. Read off the `via()` lists of the classes that return the type;
     * `NotificationSeamTest` fails when they drift apart. No class sends SMS yet.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        return match ($this) {
            self::LEAVE_REQUESTED,
            self::LEAVE_APPROVED,
            self::LEAVE_REJECTED,
            self::LEAVE_CHANGED,
            self::ATTENDANCE_ANOMALY,
            self::PAYSLIP_AVAILABLE,
            self::APPROVAL_REMINDER,
            self::EXPIRY_REMINDER,
            self::EMPLOYEE_VOICE,
            self::LETTER => ['in_app', 'email'],
            self::ATTENDANCE_CORRECTION,
            self::OVERTIME_REQUEST,
            self::PAYROLL_PROCESSED,
            self::ANNOUNCEMENT,
            self::PROFILE_UPDATE => ['in_app'],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
