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

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
