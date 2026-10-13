<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One command for one device. See the device_commands migration and
 * App\Services\Device\DeviceCommandQueue.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $device_id
 * @property int|null $employee_id
 * @property DeviceCommandType $type
 * @property array<string, mixed>|null $payload
 * @property DeviceCommandStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property int|null $requested_by
 * @property Carbon|null $sent_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Device|null $device
 * @property-read Employee|null $employee
 */
class DeviceCommand extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'device_id',
        'employee_id',
        'type',
        'payload',
        'status',
        'attempts',
        'last_error',
        'requested_by',
        'sent_at',
        'completed_at',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'device_id',
        'employee_id',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => DeviceCommandType::class,
            'status' => DeviceCommandStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
