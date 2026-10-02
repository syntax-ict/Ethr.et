<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\LeaveRequest;

/**
 * The template variables the three leave e-mails share — see
 * `App\Support\NotificationTemplates::VARIABLES`. One definition, so the
 * requested, approved and rejected mails cannot disagree about what
 * `{start_date}` means.
 */
trait LeaveTemplateValues
{
    /** @return array<string, scalar|null> */
    protected function leaveTemplateValues(LeaveRequest $leaveRequest): array
    {
        return [
            'employee_name' => $leaveRequest->employee?->name,
            'leave_type' => $leaveRequest->leaveType?->name,
            'start_date' => $leaveRequest->start_date->format('Y-m-d'),
            'end_date' => $leaveRequest->end_date->format('Y-m-d'),
            // Stored as a decimal (half days exist): "3", "1.5" — never "3.0".
            'days' => rtrim(rtrim(number_format((float) $leaveRequest->days, 2, '.', ''), '0'), '.'),
            'reason' => $leaveRequest->rejected_reason,
        ];
    }
}
