<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read LeaveType|null $leaveType
 */
class LeaveBalance extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory;

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'leave_type_id',
        'year',
        'entitled_days',
        'used_days',
        'carried_days',
        'pending_days',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'entitled_days' => 'decimal:1',
            'used_days' => 'decimal:1',
            'carried_days' => 'decimal:1',
            'pending_days' => 'decimal:1',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * Rounded to the columns' one decimal: they are `decimal:1`, and the float
     * arithmetic drifts (4.6 - 3.6 is 0.99999999999999956), which refused a
     * one-day request against a one-day balance and printed the long decimal.
     */
    public function remainingDays(): float
    {
        return round(
            (float) $this->entitled_days + (float) $this->carried_days
                - (float) $this->used_days - (float) $this->pending_days,
            1,
        );
    }
}
