<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ShiftAssignment extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory;

    protected $fillable = [
        'tenant_id',
        'shift_id',
        'shift_rotation_id',
        'assignable_type',
        'assignable_id',
        'effective_from',
        'effective_to',
        'anchor_date',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'anchor_date' => 'date',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<ShiftRotation, $this> */
    public function rotation(): BelongsTo
    {
        return $this->belongsTo(ShiftRotation::class, 'shift_rotation_id');
    }

    /**
     * A row holds either a fixed shift or a rotation, never both.
     */
    public function isRotation(): bool
    {
        return $this->shift_rotation_id !== null;
    }

    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }
}
