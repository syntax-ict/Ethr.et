<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One-time verification code (PHASE_00 S02).
 *
 * Like `trusted_devices`, the table shipped in the initial migration and then sat
 * unused — no model, no route, no service — while `RateLimiter::for('otp')` was
 * defined against endpoints that did not exist.
 *
 * `code` holds a **hash**, never the digits. An OTP is a short-lived credential;
 * storing it in plaintext would let anyone with read access to the database
 * complete someone else's sign-in.
 *
 * Not `BelongsToTenant`: the table has no `tenant_id`, and rows hang off
 * `user_id`, which is already strictly narrower than a tenant.
 */
class OtpCode extends Model
{
    public const PURPOSE_LOGIN = 'login';

    protected $fillable = [
        'user_id',
        'code',
        'purpose',
        'expires_at',
        'used_at',
    ];

    protected $hidden = [
        'id',
        'user_id',
        'code',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
