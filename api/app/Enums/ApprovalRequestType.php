<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * The kinds of request the approval engine routes. The values match the
 * `type` the approvals inbox already returns, so a workflow, an instance and
 * an inbox item name a request the same way.
 */
enum ApprovalRequestType: string
{
    case LEAVE = 'leave';
    case CORRECTION = 'correction';

    /** The permission every step's approver must hold, as the policies require today. */
    public function permission(): string
    {
        return match ($this) {
            self::LEAVE => 'leave.approve',
            self::CORRECTION => 'correction.approve',
        };
    }

    public static function fromModel(Model $approvable): self
    {
        return match (true) {
            $approvable instanceof LeaveRequest => self::LEAVE,
            $approvable instanceof AttendanceCorrection => self::CORRECTION,
            default => throw new \InvalidArgumentException('No approval workflow for '.$approvable::class),
        };
    }
}
