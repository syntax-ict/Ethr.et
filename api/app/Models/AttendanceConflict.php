<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConflictResolutionStatus;
use App\Enums\ConflictType;
use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property ConflictType $conflict_type
 * @property ConflictResolutionStatus $resolution
 * @property Carbon|null $resolved_at
 * @property string|null $resolution_notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AttendanceConflict extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'employee_id',
        'record_a_id',
        'record_b_id',
        'conflict_type',
        'resolution',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'employee_id',
        'record_a_id',
        'record_b_id',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'conflict_type' => ConflictType::class,
            'resolution' => ConflictResolutionStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<AttendanceRecord, $this> */
    public function recordA(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'record_a_id');
    }

    /** @return BelongsTo<AttendanceRecord, $this> */
    public function recordB(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'record_b_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
