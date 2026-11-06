<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\Branch;
use App\Models\KioskSession;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;

final class QrCodeService
{
    public function generate(Branch $branch, ?Shift $shift, int $expiryMinutes = 30): array
    {
        $generatedAt = now();
        $expiresAt = $generatedAt->copy()->addMinutes($expiryMinutes);

        return [
            'token' => $this->seal($branch, $shift, $generatedAt, $expiresAt, null),
            'branch_public_id' => $branch->public_id,
            'branch_name' => $branch->name,
            'shift_public_id' => $shift?->public_id,
            'shift_name' => $shift?->name,
            'expires_at' => $expiresAt->toIso8601String(),
            'generated_at' => $generatedAt->toIso8601String(),
            'expiry_minutes' => $expiryMinutes,
        ];
    }

    /**
     * A code a kiosk shows on its own screen: lives for seconds, not minutes,
     * and names the kiosk that issued it. QrAttendanceController::redeem reads
     * that name and lets such a code be used once in all, by one person.
     * Same token, same validate(), same scan endpoint as the code HR prints.
     */
    public function generateForKiosk(KioskSession $kiosk, Branch $branch, int $ttlSeconds): array
    {
        $generatedAt = now();
        $expiresAt = $generatedAt->copy()->addSeconds($ttlSeconds);

        return [
            'token' => $this->seal($branch, null, $generatedAt, $expiresAt, $kiosk->id),
            'branch_public_id' => $branch->public_id,
            'branch_name' => $branch->name,
            'expires_at' => $expiresAt->toIso8601String(),
            'generated_at' => $generatedAt->toIso8601String(),
            'ttl_seconds' => $ttlSeconds,
        ];
    }

    private function seal(Branch $branch, ?Shift $shift, Carbon $generatedAt, Carbon $expiresAt, ?int $kioskSessionId): string
    {
        $payload = [
            'branch_id' => $branch->id,
            'tenant_id' => $branch->tenant_id,
            'shift_id' => $shift?->id,
            'expires_at' => $expiresAt->toIso8601String(),
            'generated_at' => $generatedAt->toIso8601String(),
            'nonce' => bin2hex(random_bytes(8)),
        ];
        if ($kioskSessionId !== null) {
            $payload['kiosk_session_id'] = $kioskSessionId;
        }

        return Crypt::encryptString(json_encode($payload));
    }

    public function validate(string $token, int $tenantId): ?array
    {
        try {
            $decrypted = Crypt::decryptString($token);
            $payload = json_decode($decrypted, true);

            if (! $payload || ! isset($payload['tenant_id'], $payload['branch_id'], $payload['expires_at'])) {
                return null;
            }

            if ((int) $payload['tenant_id'] !== $tenantId) {
                return null;
            }

            $expiresAt = Carbon::parse($payload['expires_at']);
            if (now()->isAfter($expiresAt)) {
                return null;
            }

            return $payload;
        } catch (\Throwable) {
            return null;
        }
    }
}
