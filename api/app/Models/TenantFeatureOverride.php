<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeatureOverrideMode;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One organisation's grant or revoke of one feature, set by the platform
 * admin. Past `expires_at` the row is ignored but kept, as history.
 *
 * @property string $key
 * @property FeatureOverrideMode $mode
 * @property string $reason
 * @property Carbon|null $expires_at
 * @property int|null $changed_by_user_id
 */
class TenantFeatureOverride extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'key',
        'mode',
        'reason',
        'expires_at',
        'changed_by_user_id',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'changed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'mode' => FeatureOverrideMode::class,
            'expires_at' => 'datetime',
        ];
    }
}
