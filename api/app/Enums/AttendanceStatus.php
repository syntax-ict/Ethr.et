<?php

declare(strict_types=1);

namespace App\Enums;

enum AttendanceStatus: string
{
    case PENDING = 'pending';
    case PRESENT = 'present';
    case LATE = 'late';
    case ABSENT = 'absent';
    case EARLY_LEAVE = 'early_leave';
    case ON_LEAVE = 'on_leave';
    case HOLIDAY = 'holiday';
    case VOIDED = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::PRESENT => 'Present',
            self::LATE => 'Late',
            self::ABSENT => 'Absent',
            self::EARLY_LEAVE => 'Early Leave',
            self::ON_LEAVE => 'On Leave',
            self::HOLIDAY => 'Holiday',
            self::VOIDED => 'Voided',
        };
    }

    public function isWorked(): bool
    {
        return in_array($this, [self::PRESENT, self::LATE, self::EARLY_LEAVE]);
    }
}
