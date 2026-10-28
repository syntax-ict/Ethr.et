<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Feature;
use App\Enums\FeatureState;
use Illuminate\Database\Eloquent\Model;

/**
 * A feature's state for the whole platform, one row per key. No row means the
 * catalogue default ({@see Feature::defaultState()}).
 *
 * Global by design (TenantIsolationTest::GLOBAL_MODELS): the platform admin's
 * switch, read by every organisation.
 *
 * @property string $key
 * @property FeatureState $state
 * @property string|null $reason
 * @property int|null $changed_by_user_id
 */
class PlatformFeatureState extends Model
{
    protected $fillable = [
        'key',
        'state',
        'reason',
        'changed_by_user_id',
    ];

    protected $hidden = [
        'id',
        'changed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'state' => FeatureState::class,
        ];
    }
}
