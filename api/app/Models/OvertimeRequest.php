<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OvertimeRequestStatus;
use App\Traits\BelongsToTenant;
use App\Traits\HasApprovalInstance;
use App\Traits\HasPublicId;
use Database\Factories\OvertimeRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Overtime asked for before it is worked, decided through the approval
 * engine. With the organisation's `overtime_requires_approval` on, payroll
 * flags overtime worked beyond what was approved for the day; pay is never
 * held back.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $employee_id
 * @property Carbon $date
 * @property int $planned_minutes
 * @property string $reason
 * @property OvertimeRequestStatus $status
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property-read Employee|null $employee
 */
class OvertimeRequest extends Model
{
    /** @use HasFactory<OvertimeRequestFactory> */
    use BelongsToTenant, HasApprovalInstance, HasFactory, HasPublicId;

    /** The legal daily limit (Proclamation 1156/2019 Art. 67): no more can be asked for one day. */
    public const MAX_PLANNED_MINUTES = 240;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'date',
        'planned_minutes',
        'reason',
        'status',
        'requested_by',
        'decided_by',
        'decided_at',
        'decision_note',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'employee_id',
        'requested_by',
        'decided_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'planned_minutes' => 'integer',
            'status' => OvertimeRequestStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
