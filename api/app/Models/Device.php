<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'branch_id',
        'name',
        'location_description',
        'serial_number',
        'adapter_type',
        'connection_config',
        'status',
        'webhook_token',
        'auto_sync',
        'sync_interval_minutes',
        'last_sync_at',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'branch_id',
        'connection_config',
    ];

    protected function casts(): array
    {
        return [
            'connection_config' => 'encrypted:array',
            'last_sync_at' => 'datetime',
            'auto_sync' => 'boolean',
            'sync_interval_minutes' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(DeviceSyncLog::class);
    }

    public function latestSyncLog(): BelongsTo
    {
        return $this->belongsTo(DeviceSyncLog::class, 'id', 'device_id')
            ->ofMany('created_at', 'max');
    }

    public function isDueForSync(): bool
    {
        if (! $this->auto_sync) {
            return false;
        }

        if (! $this->last_sync_at) {
            return true;
        }

        return $this->last_sync_at->addMinutes($this->sync_interval_minutes)->isPast();
    }

    public static function generateWebhookToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
