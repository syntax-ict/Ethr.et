<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One punch a terminal pushed, kept until ProcessDeviceInboundPunchesJob has
 * handed it to the attendance engine (see the
 * create_device_inbound_punches_table migration). `processed_at` is null
 * while it waits; `error` says why a processed row was not recorded.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $device_id
 * @property string $badge
 * @property string $punch_type
 * @property string $occurred_raw
 * @property string $idempotency_key
 * @property Carbon|null $processed_at
 * @property int $attempts
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property-read Device|null $device
 */
class DeviceInboundPunch extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'device_id',
        'badge',
        'punch_type',
        'occurred_raw',
        'idempotency_key',
        'processed_at',
        'attempts',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
