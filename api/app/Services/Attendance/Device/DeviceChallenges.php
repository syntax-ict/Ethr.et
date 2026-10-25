<?php

declare(strict_types=1);

namespace App\Services\Attendance\Device;

use App\Models\AttendanceDeviceChallenge;
use App\Models\Employee;
use Carbon\Carbon;

/**
 * Single-use challenges for attendance passkeys. A challenge is issued to one
 * employee for one purpose and is spent by a single conditional update, so a
 * replayed or raced signature finds it already used.
 */
final class DeviceChallenges
{
    public const REGISTER = 'register';

    public const PUNCH = 'punch';

    private const TTL_SECONDS = 180;

    /** A fresh challenge (raw bytes); only its hash is stored. */
    public function issue(Employee $employee, string $purpose): string
    {
        $challenge = random_bytes(32);

        AttendanceDeviceChallenge::query()->create([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'purpose' => $purpose,
            'challenge_hash' => hash('sha256', $challenge),
            'expires_at' => Carbon::now('UTC')->addSeconds(self::TTL_SECONDS),
        ]);

        return $challenge;
    }

    /** Spend $challenge if it was issued to this employee for $purpose and is unused and unexpired. */
    public function consume(Employee $employee, string $purpose, string $challenge): bool
    {
        return AttendanceDeviceChallenge::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('purpose', $purpose)
            ->where('challenge_hash', hash('sha256', $challenge))
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now('UTC'))
            ->update(['used_at' => Carbon::now('UTC')]) === 1;
    }

    /** Remove spent and expired challenges; run by the scheduler. */
    public function purge(): int
    {
        return AttendanceDeviceChallenge::withoutGlobalScope('tenant')
            ->where('expires_at', '<', Carbon::now('UTC')->subHour())
            ->delete();
    }
}
