<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The platform's SMS switch, route and daily cap — one row, global by design
 * (TenantIsolationTest::GLOBAL_MODELS).
 *
 * `route_prefix` names the network whose numbers go to the secondary gateway:
 * `7` for Safaricom Ethiopia (+2517…), `9` for Ethio Telecom (+2519…), null
 * for one gateway carrying every number.
 *
 * @property bool $enabled
 * @property string|null $route_prefix
 * @property int $daily_cap
 */
class SmsSetting extends Model
{
    public const ROUTE_PREFIXES = ['7', '9'];

    protected $fillable = ['enabled', 'route_prefix', 'daily_cap'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'daily_cap' => 'integer',
        ];
    }

    /** The one row, created on first read so callers never handle a null. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'enabled' => false,
            'route_prefix' => null,
            'daily_cap' => (int) config('sms.daily_cap', 500),
        ]);
    }
}
