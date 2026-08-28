<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Database\Factories\ShiftRotationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A repeating multi-day shift pattern. See the migration for why the cycle is
 * measured in days rather than weeks.
 */
class ShiftRotation extends Model
{
    /** @use HasFactory<ShiftRotationFactory> */
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'name',
        'name_am',
        'description',
        'cycle_days',
        'is_active',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'cycle_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ShiftRotationStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ShiftRotationStep::class);
    }

    /** @return HasMany<ShiftAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }
}
