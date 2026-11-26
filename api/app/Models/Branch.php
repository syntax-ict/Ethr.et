<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `region_code` and `town_code` name entries of the shared pick lists beside the
 * `city` text; both stay null for a branch whose place was typed.
 *
 * @property string|null $region_code
 * @property string|null $town_code
 */
class Branch extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'name',
        'name_am',
        'code',
        'address',
        'city',
        'region_code',
        'town_code',
        'phone',
        'latitude',
        'longitude',
        'geofence_radius_meters',
        'is_active',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'geofence_radius_meters' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function kioskSessions(): HasMany
    {
        return $this->hasMany(KioskSession::class);
    }
}
