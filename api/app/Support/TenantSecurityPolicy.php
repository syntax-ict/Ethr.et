<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;

/**
 * A tenant's security settings, as the code that enforces them reads them.
 *
 * `mfa_policy` and `session_timeout_minutes` were saved through
 * `PUT /settings` and read by nothing but `GET /settings` (audit N6): an admin
 * could choose "Required" and a 30-minute timeout and see both reflected back,
 * while nobody was asked to enrol and a session lived as long as it always had.
 * They are enforced now by `RequireTenantMfaEnrolment`, `MfaSetupController`
 * and `SessionIdleTimeout`, all of which read through here.
 *
 * Platform users have no tenant (`tenant_id` is null by design), so there is no
 * tenant policy for them: `forUser()` returns null and every enforcement point
 * lets them through. Their own control is `RequirePlatformMfa`.
 */
final class TenantSecurityPolicy
{
    public const MFA_DISABLED = 'disabled';

    public const MFA_OPTIONAL = 'optional';

    public const MFA_REQUIRED = 'required';

    /** @var list<string> */
    public const MFA_POLICIES = [self::MFA_DISABLED, self::MFA_OPTIONAL, self::MFA_REQUIRED];

    /**
     * Shortest idle timeout a tenant may choose. Background polling does not
     * count as activity, so anything shorter would sign people out while they
     * read a page.
     */
    public const TIMEOUT_MIN_MINUTES = 5;

    /**
     * Longest idle timeout a tenant may choose: the server's own session
     * lifetime (`SESSION_LIFETIME=480` in every environment file). A browser
     * session is idle-expired by Laravel at that point regardless, so a longer
     * value would be stored and silently not honoured — the defect this class
     * exists to close.
     */
    public const TIMEOUT_MAX_MINUTES = 480;

    public const DEFAULT_TIMEOUT_MINUTES = 480;

    /** @param  array<string, mixed>  $settings */
    private function __construct(private readonly array $settings) {}

    /** The policy governing a user, or null for a platform user with no tenant. */
    public static function forUser(User $user): ?self
    {
        // The role as well as the missing tenant: a super admin is a platform
        // account whatever its `tenant_id` says, and a tenant's policy must
        // never lock the platform out of its own console.
        if ($user->tenant_id === null || $user->isSuperAdmin()) {
            return null;
        }

        $current = app(CurrentTenant::class)->get();

        // Usually the tenant already resolved for this request — but not
        // always: a token presented on a host that resolved no tenant still
        // belongs to a user who has one. `Tenant` is a global model, so this
        // lookup has no scope to bypass.
        $tenant = $current !== null && $current->id === $user->tenant_id
            ? $current
            : Tenant::query()->find($user->tenant_id);

        // A tenant row that cannot be read still yields a policy — the
        // defaults — rather than none: failing open here would exempt the user
        // from the timeout entirely.
        return $tenant !== null ? self::forTenant($tenant) : new self([]);
    }

    public static function forTenant(Tenant $tenant): self
    {
        $settings = $tenant->getAttribute('settings');

        return new self(is_array($settings) ? $settings : []);
    }

    public function mfaPolicy(): string
    {
        $value = $this->settings['mfa_policy'] ?? null;
        $value = is_string($value) ? strtolower(trim($value)) : null;

        return in_array($value, self::MFA_POLICIES, true) ? $value : self::MFA_OPTIONAL;
    }

    public function requiresMfa(): bool
    {
        return $this->mfaPolicy() === self::MFA_REQUIRED;
    }

    public function forbidsMfaEnrolment(): bool
    {
        return $this->mfaPolicy() === self::MFA_DISABLED;
    }

    /**
     * Minutes of inactivity after which a session ends.
     *
     * A stored value outside the accepted range — written before `PUT /settings`
     * validated it, or by hand — falls back to the default rather than to "no
     * timeout", and is clamped rather than trusted.
     */
    public function idleTimeoutMinutes(): int
    {
        $value = $this->settings['session_timeout_minutes'] ?? null;

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1) {
            return self::DEFAULT_TIMEOUT_MINUTES;
        }

        return max(self::TIMEOUT_MIN_MINUTES, min(self::TIMEOUT_MAX_MINUTES, $value));
    }
}
