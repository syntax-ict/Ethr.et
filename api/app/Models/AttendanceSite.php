<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A place people may punch besides their branch: a named circle. Everyone at
 * its branch may use it; further employees and departments by assignment.
 *
 * @property float|string $latitude
 * @property float|string $longitude
 */
class AttendanceSite extends Model
{
    use BelongsToTenant, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'branch_id',
        'name',
        'name_am',
        'latitude',
        'longitude',
        'radius_meters',
        'is_active',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'branch_id',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'radius_meters' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<AttendanceSiteAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(AttendanceSiteAssignment::class);
    }
}
