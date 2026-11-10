<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\InvitationSend;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AccountActivationNotification;
use App\Support\Mail\CriticalMail;
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
    /** The hash every login made by this instance starts with: unusablePassword(). */
    private ?string $placeholder = null;

    public function __construct(private readonly CurrentTenant $currentTenant) {}

    /**
     * The hash of a random secret nobody is told and nothing keeps.
     *
     * Made once for this instance, which is one list of people: the import
     * and "send invitations" hold one for the whole request. It was made per
     * login, and a bcrypt hash is slow on purpose (cost 12, a quarter of a
     * second or more on the shared host), so a thousand logins spent minutes
     * hashing passwords that can never be typed, inside a request the host
     * stops at thirty seconds.
     *
     * Sharing it gives nothing away: the logins it is on cannot be signed in
     * to with any password, the secret is gone the moment it is hashed, and
     * each person replaces it with their own when they activate.
     */
    private function unusablePassword(): string
    {
        return $this->placeholder ??= Hash::make(Str::random(40));
    }

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

        // One live login per employee record: a second would read the first
        // one's payslips and leave as its own. A deleted one does not count.
        if ($employee !== null && $employee->hasLogin()) {
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
            'password' => $this->unusablePassword(),
            'role' => $role,
            'custom_role_id' => $customRoleId,
            'status' => 'invited',
            'invited_by' => $invitedBy,
            'invited_at' => now(),
            'locale' => $locale ?? $tenant->default_locale ?? 'en',
        ]);

        // Keep the Employee<->User link consistent in both directions. The
        // employee has no login of their own here: one that had is refused
        // above.
        if ($employee !== null) {
            $employee->update(['user_id' => $user->id]);
        }

        AuditLog::record('user.provisioned', $user, [
            'role' => $role->value,
            'employee_id' => $employee?->public_id,
            'invited_by' => $invitedBy,
        ]);

        if ($sendActivation) {
            $this->sendActivationLink($user, $invitedBy);
        }

        return $user;
    }

    /**
     * Provision a login for an employee with no email and no phone. The row has
     * a null email and an unusable password; it is activated in person with a
     * code HR issues (ActivationCodeService), so nothing is sent from here.
     */
    public function provisionWithoutContact(Employee $employee, ?int $invitedBy = null): User
    {
        $tenant = $this->currentTenant->get();
        if ($tenant === null) {
            throw new \RuntimeException('Cannot provision a user without a resolved tenant.');
        }

        $user = User::create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'email' => null,
            'password' => $this->unusablePassword(),
            'role' => UserRole::EMPLOYEE,
            'status' => 'invited',
            'invited_by' => $invitedBy,
            'invited_at' => now(),
            'locale' => $tenant->default_locale ?? 'en',
        ]);

        if ($employee->user_id === null) {
            $employee->update(['user_id' => $user->id]);
        }

        AuditLog::record('user.provisioned_no_contact', $user, [
            'employee_id' => $employee->public_id,
            'invited_by' => $invitedBy,
        ]);

        return $user;
    }

    /**
     * Provision a login for an employee with a phone and no email, to be
     * invited by text message once SMS invitations are on. Like the in-person
     * account it has a null email and an unusable password; the person signs in
     * with a code sent to that phone, which activates the account (OtpController).
     */
    public function provisionWithPhone(Employee $employee, ?int $invitedBy = null): User
    {
        $user = $this->provisionWithoutContact($employee, $invitedBy);
        $user->update(['phone' => $employee->phone]);

        return $user;
    }

    /**
     * (Re)send the activation / set-password link to an invited user, now.
     *
     * For one person, where someone may be waiting for it. A bulk send goes
     * through InvitationDispatcher::queue() instead, which sends from the queue.
     */
    public function sendActivationLink(User $user, ?int $sentBy = null): bool
    {
        $tenant = $this->currentTenant->get();
        if ($tenant === null || empty($user->email)) {
            return false;
        }

        try {
            $token = PasswordTokens::activationBroker()->createToken($user);

            // Sent now; a failure queues retries (CriticalMail).
            $sent = CriticalMail::send($user, new AccountActivationNotification($token, $tenant->subdomain, $tenant->name));
        } catch (\Throwable $e) {
            Log::error('Account activation email failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $sent = false;
        }

        // A failure here is retried by CriticalMail, so it is recorded as
        // queued rather than failed: the users list should not call it lost.
        InvitationSend::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'channel' => InvitationSend::EMAIL,
            'status' => $sent ? InvitationSend::SENT : InvitationSend::QUEUED,
            'attempts' => 1,
            'queued_by' => $sentBy,
            'queued_at' => now(),
            'sent_at' => $sent ? now() : null,
        ]);

        return $sent;
    }

    /**
     * Send the activation email and let a failure throw, for SendInvitationJob,
     * which is itself the retry. The token is made here, inside the job, so the
     * jobs table never holds one.
     */
    public function deliverActivationEmail(User $user, Tenant $tenant): void
    {
        $token = PasswordTokens::activationBroker()->createToken($user);

        $user->notifyNow(new AccountActivationNotification($token, $tenant->subdomain, $tenant->name));
    }
}
