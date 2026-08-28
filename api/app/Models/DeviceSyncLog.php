<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceSyncLog extends Model
{
    use BelongsToTenant, HasAuditLog, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'device_id',
        'status',
        'triggered_by',
        'events_found',
        'events_processed',
        'events_failed',
        'error_message',
        'started_at',
        'completed_at',
        'duration_ms',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'device_id',
    ];

    protected function casts(): array
    {
        return [
            'events_found' => 'integer',
            'events_processed' => 'integer',
            'events_failed' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
