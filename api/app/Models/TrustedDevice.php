<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser the user has told us to remember, so MFA is not re-prompted on it
 * for 30 days (PHASE_00 S03).
 *
 * Deliberately **not** `BelongsToTenant`: the table has no `tenant_id` column, and
 * it does not need one — every row hangs off `user_id`, and a user belongs to
 * exactly one tenant, so the scope is already implied and strictly narrower.
 * `TenantIsolationTest` only requires the trait where a `tenant_id` column exists.
 *
 * `device_hash` is a SHA-256 of the opaque cookie value (see `DeviceFingerprint`),
 * never the cookie itself — a database leak cannot be replayed as a trusted device.
 */
class TrustedDevice extends Model
{
    protected $fillable = [
        'user_id',
        'device_hash',
        'device_name',
        'last_used_at',
        'expires_at',
    ];

    protected $hidden = [
        'id',
        'user_id',
        // Never expose the hash: it is the secret's only stored form.
        'device_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
