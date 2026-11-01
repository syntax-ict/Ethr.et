<?php

declare(strict_types=1);

namespace App\Services\Kiosk;

use App\Models\Employee;
use App\Models\KioskSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A few minutes of self-service at a kiosk for one person, after their code
 * and PIN.
 *
 * Bound to the kiosk it was issued at: a pass read off one terminal's screen
 * or network is refused at every other. Only its hash is stored, and it ends
 * when the person taps Done, when the terminal goes idle, or after LIFETIME
 * seconds, whichever comes first. It is never renewed: a person who needs
 * longer types their PIN again.
 */
final class KioskPass
{
    public const LIFETIME = 180;

    /** @return array{pass: string, expires_at: string} */
    public function issue(KioskSession $session, Employee $employee): array
    {
        $pass = Str::random(48);
        $expiresAt = now()->addSeconds(self::LIFETIME);

        Cache::put($this->key($pass), [
            'tenant_id' => $session->tenant_id,
            'kiosk_session_id' => $session->id,
            'employee_id' => $employee->id,
        ], $expiresAt);

        return ['pass' => $pass, 'expires_at' => $expiresAt->toIso8601String()];
    }

    /**
     * The person a pass names, at the kiosk presenting it. The kiosk's token
     * has already made its tenant current, so the employee lookup is scoped;
     * the stored tenant is compared as well, so that a pass can never be read
     * under another tenant even if the binding were wrong.
     */
    public function resolve(KioskSession $session, ?string $pass): ?Employee
    {
        if ($pass === null || $pass === '') {
            return null;
        }

        $stored = Cache::get($this->key($pass));
        if (! is_array($stored)
            || ($stored['kiosk_session_id'] ?? null) !== $session->id
            || ($stored['tenant_id'] ?? null) !== $session->tenant_id) {
            return null;
        }

        return Employee::query()
            ->where('tenant_id', $session->tenant_id)
            ->whereKey($stored['employee_id'])
            ->first();
    }

    public function revoke(?string $pass): void
    {
        if ($pass !== null && $pass !== '') {
            Cache::forget($this->key($pass));
        }
    }

    private function key(string $pass): string
    {
        return 'kiosk-pass:'.hash('sha256', $pass);
    }
}
