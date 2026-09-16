<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AccountActivationNotification;
use App\Support\PasswordTokens;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Single entry point for creating login accounts. Every account is created in
 * `invited` status with an unusable password; the invitee sets their own
 * password via an activation link. This is the only place tenant-scoped users
 * (other than the self-registered tenant admin and SSO/SCIM) are minted.
 */
class UserProvisioningService
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    /**
     * Provision a login account and (optionally) email an activation link.
     *
     * Returns null when a user with this email already exists in the tenant —
     * callers (import, employee create) treat that as "skipped".
     */
    public function provision(
        string $email,
        UserRole $role,
        ?Employee $employee = null,
        ?int $invitedBy = null,
        ?int $customRoleId = null,
        bool $sendActivation = true,
        ?string $locale = null,
        ?string $username = null,
    ): ?User {
        $tenant = $this->currentTenant->get();
        if ($tenant === null) {
            throw new \RuntimeException('Cannot provision a user without a resolved tenant.');
        }

        $email = Str::lower(trim($email));

        $exists = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->exists();

        if ($exists) {
            return null;
        }

        $user = User::create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee?->id,
            'email' => $email,
            // Lower-cased so the case-insensitive login lookup and the unique
            // index agree on what counts as the same handle.
            'username' => $username !== null && trim($username) !== ''
                ? Str::lower(trim($username))
                : null,
            'phone' => $employee?->phone,
            // Unusable placeholder. The invitee never learns this value — they
            // set their own password through the activation link.
            'password' => Hash::make(Str::random(40)),
            'role' => $role,
            'custom_role_id' => $customRoleId,
            'status' => 'invited',
            'invited_by' => $invitedBy,
            'invited_at' => now(),
            'locale' => $locale ?? $tenant->default_locale ?? 'en',
        ]);

        // Keep the Employee<->User link consistent in both directions.
        if ($employee !== null && $employee->user_id === null) {
            $employee->update(['user_id' => $user->id]);
        }

        AuditLog::record('user.provisioned', $user, [
            'role' => $role->value,
            'employee_id' => $employee?->public_id,
            'invited_by' => $invitedBy,
        ]);

        if ($sendActivation) {
            $this->sendActivationLink($user);
        }

        return $user;
    }

    /**
     * (Re)send the activation / set-password link to an invited user.
     */
    public function sendActivationLink(User $user): bool
    {
        $tenant = $this->currentTenant->get();
        if ($tenant === null || empty($user->email)) {
            return false;
        }

        try {
            $token = PasswordTokens::broker()->createToken($user);
            $user->notify(new AccountActivationNotification($token, $tenant->subdomain, $tenant->name));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Account activation email failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
