<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\ShiftRotationStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One position in a rotation's cycle. A null `shift_id` is a rest day — an
 * intentional part of the pattern, not missing data.
 *
 * No public_id: steps are only ever addressed as part of their parent rotation,
 * never individually over the API.
 */
class ShiftRotationStep extends Model
{
    /** @use HasFactory<ShiftRotationStepFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'shift_rotation_id',
        'day_offset',
        'shift_id',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'day_offset' => 'integer',
        ];
    }

    /** @return BelongsTo<ShiftRotation, $this> */
    public function rotation(): BelongsTo
    {
        return $this->belongsTo(ShiftRotation::class, 'shift_rotation_id');
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function isRestDay(): bool
    {
        return $this->shift_id === null;
    }
}
