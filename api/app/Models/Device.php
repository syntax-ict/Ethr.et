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

/**
 * @property array<int, string>|null $webhook_ip_allowlist
 */
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
        'webhook_ip_allowlist',
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
            'webhook_ip_allowlist' => 'array',
            'last_sync_at' => 'datetime',
            'auto_sync' => 'boolean',
            'sync_interval_minutes' => 'integer',
        ];
    }

    /**
     * Whether an inbound webhook from $ip is allowed for a token-less device.
     *
     * Fail-closed: a device with no allowlist configured accepts nothing on the
     * IP path — a token-less, allowlist-less device is unreachable by design and
     * must be given either a webhook_token or an explicit allowlist entry.
     * Entries may be a plain IPv4/IPv6 address or an IPv4 CIDR block.
     */
    public function webhookIpAllowed(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        $allowlist = $this->webhook_ip_allowlist;
        if (! is_array($allowlist) || $allowlist === []) {
            return false;
        }

        foreach ($allowlist as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }

            if (str_contains($entry, '/')) {
                if ($this->ipInCidr($ip, $entry)) {
                    return true;
                }

                continue;
            }

            if (hash_equals($entry, $ip)) {
                return true;
            }
        }

        return false;
    }

    /** IPv4 CIDR membership test (e.g. 10.0.0.0/8). Non-IPv4 inputs return false. */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long((string) $subnet);
        if ($ipLong === false || $subnetLong === false || $bits === null) {
            return false;
        }

        $bits = (int) $bits;
        if ($bits < 0 || $bits > 32) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
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
