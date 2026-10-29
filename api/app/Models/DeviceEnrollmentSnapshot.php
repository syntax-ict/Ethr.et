<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person a ZKTeco ADMS terminal reported as enrolled on it. See the
 * device_enrollment_snapshots migration and App\Services\Device\AdmsRoster.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $device_id
 * @property string $device_user_id
 * @property string|null $name
 * @property string|null $card_number
 * @property bool $is_admin
 * @property int|null $fingerprint_count
 * @property bool|null $face_registered
 * @property Carbon $seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Device|null $device
 */
class DeviceEnrollmentSnapshot extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'device_id',
        'device_user_id',
        'name',
        'card_number',
        'is_admin',
        'fingerprint_count',
        'face_registered',
        'seen_at',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'device_id',
    ];

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'fingerprint_count' => 'integer',
            'face_registered' => 'boolean',
            'seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
