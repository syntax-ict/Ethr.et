<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves a login identifier value to exactly one tenant user, honouring the
 * identifier types a tenant has enabled. This is what lets organizations whose
 * staff have no email log in by mobile number or employee number instead. See
 * ONBOARDING_V2.md decision D6/D7 (access & identity).
 *
 * Security properties:
 *  - every lookup is scoped to the tenant;
 *  - an identifier that resolves to more than one user returns null (deny)
 *    rather than guessing, so a value can never authenticate the wrong person;
 *  - the caller still verifies the password, so a hit here is not by itself
 *    authentication.
 */
final class AuthIdentifierResolver
{
    public const EMAIL = 'email';

    public const PHONE = 'phone';

    public const EMPLOYEE_CODE = 'employee_code';

    public const USERNAME = 'username';

    /** Identifier types ETHR can authenticate against today. */
    public const AVAILABLE = [self::EMAIL, self::PHONE, self::EMPLOYEE_CODE, self::USERNAME];

    public const DEFAULT = [self::EMAIL];

    /**
     * @param  array<int, string>  $enabledTypes  ordered; the first type that yields a unique user wins
     */
    public function resolve(int $tenantId, string $value, array $enabledTypes): ?User
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        foreach ($enabledTypes as $type) {
            $user = match ($type) {
                self::EMAIL => $this->byEmail($tenantId, $value),
                self::PHONE => $this->byPhone($tenantId, $value),
                self::EMPLOYEE_CODE => $this->byEmployeeCode($tenantId, $value),
                self::USERNAME => $this->byUsername($tenantId, $value),
                default => null,
            };

            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $types  raw, possibly from tenant settings JSON
     * @return array<int, string>
     */
    public static function sanitizeTypes(array $types): array
    {
        $clean = array_values(array_unique(array_filter(
            $types,
            static fn ($t): bool => is_string($t) && in_array($t, self::AVAILABLE, true),
        )));

        return $clean === [] ? self::DEFAULT : $clean;
    }

    private function byEmail(int $tenantId, string $value): ?User
    {
        return $this->single(
            User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($value)])
                ->get()
        );
    }

    private function byPhone(int $tenantId, string $value): ?User
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        $stripped = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', ''), '(', ''), ')', ''), '.', '')";

        // Prefer a phone recorded on the user account; fall back to the linked
        // employee record's phone.
        $user = $this->single(
            User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('phone')
                ->whereRaw("{$stripped} = ?", [$digits])
                ->get()
        );

        if ($user !== null) {
            return $user;
        }

        $employeeIds = Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('phone')
            ->whereRaw("{$stripped} = ?", [$digits])
            ->pluck('id');

        if ($employeeIds->count() !== 1) {
            return null;
        }

        return $this->single(
            User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('employee_id', $employeeIds->first())
                ->get()
        );
    }

    private function byUsername(int $tenantId, string $value): ?User
    {
        return $this->single(
            User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereRaw('LOWER(username) = ?', [mb_strtolower($value)])
                ->get()
        );
    }

    private function byEmployeeCode(int $tenantId, string $value): ?User
    {
        $employeeIds = Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(employee_code) = ?', [mb_strtolower($value)])
            ->pluck('id');

        if ($employeeIds->count() !== 1) {
            return null;
        }

        return $this->single(
            User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('employee_id', $employeeIds->first())
                ->get()
        );
    }

    /**
     * Exactly one user or nothing — an ambiguous identifier must never
     * authenticate an arbitrary account.
     *
     * @param  Collection<int, User>  $users
     */
    private function single(Collection $users): ?User
    {
        return $users->count() === 1 ? $users->first() : null;
    }
}
