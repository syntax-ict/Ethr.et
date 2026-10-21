<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A device punch held because its badge matched no employee (see the
 * create_unmatched_device_punches_table migration). `status` is `pending`
 * until the badge is assigned (`replayed`, or `refused` when the engine will
 * not take the punch) or dismissed (`dismissed`).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $device_id
 * @property string $badge
 * @property string $punch_type
 * @property string $occurred_raw
 * @property string $idempotency_key
 * @property string $status
 * @property string|null $refusal
 * @property int|null $employee_id
 * @property Carbon|null $created_at
 * @property-read Device|null $device
 */
class UnmatchedDevicePunch extends Model
{
    use BelongsToTenant, HasPublicId;

    public const PENDING = 'pending';

    public const REPLAYED = 'replayed';

    public const REFUSED = 'refused';

    public const DISMISSED = 'dismissed';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'device_id',
        'badge',
        'punch_type',
        'occurred_raw',
        'idempotency_key',
        'status',
        'refusal',
        'employee_id',
        'resolved_by',
        'resolved_at',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'device_id',
        'employee_id',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
