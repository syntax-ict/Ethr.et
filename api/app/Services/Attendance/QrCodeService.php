<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\Branch;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;

final class QrCodeService
{
    public function generate(Branch $branch, ?Shift $shift, int $expiryMinutes = 30): array
    {
        $expiresAt = now()->addMinutes($expiryMinutes);
        $generatedAt = now();

        $payload = [
            'branch_id' => $branch->id,
            'tenant_id' => $branch->tenant_id,
            'shift_id' => $shift?->id,
            'expires_at' => $expiresAt->toIso8601String(),
            'generated_at' => $generatedAt->toIso8601String(),
            'nonce' => bin2hex(random_bytes(8)),
        ];

        $token = Crypt::encryptString(json_encode($payload));

        return [
            'token' => $token,
            'branch_public_id' => $branch->public_id,
            'branch_name' => $branch->name,
            'shift_public_id' => $shift?->public_id,
            'shift_name' => $shift?->name,
            'expires_at' => $expiresAt->toIso8601String(),
            'generated_at' => $generatedAt->toIso8601String(),
            'expiry_minutes' => $expiryMinutes,
        ];
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
