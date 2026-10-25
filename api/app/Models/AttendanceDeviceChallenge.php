<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A single-use challenge for registering or using an attendance passkey. Only
 * its hash is kept; it is spent by one conditional update (DeviceChallenges).
 */
class AttendanceDeviceChallenge extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'purpose',
        'challenge_hash',
        'expires_at',
        'used_at',
    ];

    protected $hidden = ['id', 'tenant_id', 'employee_id', 'challenge_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
