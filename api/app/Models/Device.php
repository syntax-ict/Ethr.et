<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property array<int, string>|null $webhook_ip_allowlist
 * @property-read Branch|null $branch
 * @property Carbon|null $last_sync_at
 * @property bool $commands_enabled
 * @property int|null $clock_offset_seconds
 * @property Carbon|null $clock_checked_at
 * @property int $sync_interval_minutes
 * @property string|null $webhook_token_hash
 * @property string|null $webhook_token_prefix
 * @property string|null $webhook_token_previous_hash
 * @property Carbon|null $webhook_token_previous_expires_at
 */
class Device extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId, SoftDeletes;

    public const MIN_SYNC_INTERVAL_MINUTES = 5;

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
        'timezone',
        'sync_interval_minutes',
        'last_sync_at',
        'commands_enabled',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'branch_id',
        'connection_config',
        'webhook_token',
        'webhook_token_hash',
        'webhook_token_prefix',
        'webhook_token_head_hash',
        'webhook_token_previous_hash',
        'webhook_token_previous_expires_at',
    ];

    /**
     * The token issued on this instance, in clear. Never persisted: it exists so
     * the create and regenerate responses can show it once. A device read from
     * the database has none.
     */
    public ?string $issuedWebhookToken = null;

    protected function casts(): array
    {
        return [
            'connection_config' => 'encrypted:array',
            'webhook_ip_allowlist' => 'array',
            'last_sync_at' => 'datetime',
            'auto_sync' => 'boolean',
            'commands_enabled' => 'boolean',
            'clock_offset_seconds' => 'integer',
            'clock_checked_at' => 'datetime',
            'sync_interval_minutes' => 'integer',
            'webhook_token_previous_expires_at' => 'datetime',
        ];
    }

    /**
     * Assigning a token stores its hash, its first eight characters for display
     * and the hash of its first sixteen for tracing a cut-off address; the
     * plaintext column stays null. Assigning null clears all three. Reading it
     * gives the token only on the instance it was assigned to.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function webhookToken(): Attribute
    {
        return Attribute::make(
            get: fn ($value): ?string => $this->issuedWebhookToken ?? $value,
            set: function (?string $token): array {
                $this->issuedWebhookToken = $token === '' ? null : $token;

                if ($token === null || $token === '') {
                    return [
                        'webhook_token' => null,
                        'webhook_token_hash' => null,
                        'webhook_token_prefix' => null,
                        'webhook_token_head_hash' => null,
                    ];
                }

                return [
                    'webhook_token' => null,
                    'webhook_token_hash' => self::hashToken($token),
                    'webhook_token_prefix' => substr($token, 0, 8),
                    'webhook_token_head_hash' => self::hashToken(substr($token, 0, self::HEAD_LENGTH)),
                ];
            },
        );
    }

    public const HEAD_LENGTH = 16;

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The address a terminal is configured with; null for an adapter that does not push. */
    public function webhookUrlFor(string $token): ?string
    {
        if (! in_array($this->adapter_type, ['hikvision', 'zkteco', 'suprema'], true)) {
            return null;
        }

        return config('app.url').'/api/v1/devices/webhook/'.$this->adapter_type.'?token='.$token;
    }

    /** Whether a token is set, which the plaintext column no longer says. */
    public function hasWebhookToken(): bool
    {
        return ! empty($this->webhook_token_hash);
    }

    /**
     * A pulled terminal is read when the scheduler ticks, every five minutes at
     * best, so a stored interval under that reads as the floor (2026-11-17).
     * No migration: the next save stores the clamped value.
     *
     * @return Attribute<int, int>
     */
    protected function syncIntervalMinutes(): Attribute
    {
        return Attribute::make(
            get: fn ($value): int => max(self::MIN_SYNC_INTERVAL_MINUTES, (int) $value),
        );
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

    /**
     * Punches this terminal sent that could not be recorded and nobody has
     * dismissed (DeviceQuarantine).
     *
     * @return HasMany<DeviceInboundPunch, $this>
     */
    public function quarantinedPunches(): HasMany
    {
        return $this->hasMany(DeviceInboundPunch::class)->whereNotNull('failed_at')->whereNull('dismissed_at');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(DeviceSyncLog::class);
    }

    /** @return HasMany<DeviceCommand, $this> */
    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
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
