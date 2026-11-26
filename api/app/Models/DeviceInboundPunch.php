<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One punch a terminal pushed, kept until ProcessDeviceInboundPunchesJob has
 * handed it to the attendance engine (see the
 * create_device_inbound_punches_table migration). `processed_at` is null
 * while it waits; `error` says why a processed row was not recorded.
 *
 * @property int $id
 * @property string $public_id
 * @property string|null $raw
 * @property Carbon|null $failed_at
 * @property string|null $failure
 * @property Carbon|null $dismissed_at
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
        'public_id',
        'raw',
        'failed_at',
        'failure',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /** Most of an event kept as `raw`: a face picture or a runaway body is never stored. */
    public const RAW_MAX_BYTES = 16384;

    /** The raw event as text, capped, or null when there is nothing to keep. */
    public static function capRaw(mixed $raw): ?string
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        $text = is_string($raw)
            ? $raw
            : (json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: null);

        if ($text === null) {
            return null;
        }

        return strlen($text) > self::RAW_MAX_BYTES
            ? mb_strcut($text, 0, self::RAW_MAX_BYTES - 16).'...[truncated]'
            : $text;
    }

    /** A short, single-line error text, safe to show a manager. */
    public static function scrub(string $text): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, 255);
    }

    /**
     * Insert rows into the inbox, ignoring a key already there, in chunks of
     * 500. Each row needs tenant_id, device_id, badge, punch_type,
     * occurred_raw and idempotency_key; the public id and timestamps are
     * filled in here. Every row of one call has the same set of columns.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return int how many were new
     */
    public static function stash(array $rows): int
    {
        $new = 0;
        $now = now();

        foreach (array_chunk($rows, 500) as $chunk) {
            $new += (int) DB::table('device_inbound_punches')->insertOrIgnore(array_map(
                fn (array $row) => $row + [
                    'public_id' => (string) Str::ulid(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $chunk,
            ));
        }

        return $new;
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
