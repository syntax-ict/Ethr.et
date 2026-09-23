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
 *    authentication;
 *  - `withoutGlobalScopes()` drops `SoftDeletingScope` along with the tenant
 *    scope, so a soft-deleted user *can* be returned from here. Nothing about
 *    that is safe in this class. It is safe because `UserController::destroy()`
 *    sets `status = 'inactive'` before it calls `delete()`, and
 *    `LoginController` refuses a non-active account with 403 — two lines in
 *    another class, in the right order, pinned by `UserManagementTest`'s
 *    *"deactivates a user but never yourself"*. Measured 2026-09-23: that is
 *    the only site in `app/` which deletes a `User`. A second one that skipped
 *    the deactivation would silently restore logins to deleted accounts, and
 *    nothing here would stop it.
 *
 *    Deleting an *employee* is a different question and not this class's:
 *    `EmployeeController::destroy()` soft-deletes the employee and leaves the
 *    linked `User` active, so an offboarded employee keeps their login — by
 *    email as much as by employee number. `BASELINE.md` §15e records it as an
 *    owner decision rather than a defect.
 *
 * Every lookup here is a plain equality against an indexed, normalised column
 * — `email_normalized`, `username_normalized`, `phone_normalized`,
 * `employee_code_normalized` — added by
 * `2026_09_23_000003_index_login_identifier_lookups.php`. **Do not reintroduce
 * a `LOWER()` or `REPLACE()` wrapper around a column here.** A predicate on a
 * function of a column cannot use an index on that column, which is what made
 * every login scan all of the tenant's rows until 2026-09-23 (`BASELINE.md`
 * §15e). Normalise the *input* in PHP, as the methods below do.
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
                ->where('email_normalized', mb_strtolower($value))
                ->get()
        );
    }

    private function byPhone(int $tenantId, string $value): ?User
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        // Prefer a phone recorded on the user account; fall back to the linked
        // employee record's phone.
        //
        // The `whereNotNull('phone')` these two queries used to carry is gone
        // because it can no longer do anything: a NULL phone generates a NULL
        // `phone_normalized`, and `= $digits` never matches NULL. `$digits` is
        // non-empty by the guard above, so a blank or punctuation-only phone
        // normalises to '' and is excluded the same way.
        $user = $this->single(
            User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('phone_normalized', $digits)
                ->get()
        );

        if ($user !== null) {
            return $user;
        }

        $employeeIds = Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('phone_normalized', $digits)
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
                ->where('username_normalized', mb_strtolower($value))
                ->get()
        );
    }

    private function byEmployeeCode(int $tenantId, string $value): ?User
    {
        $employeeIds = Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('employee_code_normalized', mb_strtolower($value))
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
