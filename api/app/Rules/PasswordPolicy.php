<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Tenant;
use App\Services\CurrentTenant;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/**
 * Per-tenant password policy (PHASE_00 S03).
 *
 * Nothing enforced password composition before this: registration, reset and
 * change all used a bare `min:8`, and the "configurable min length, complexity,
 * expiry (tenant setting)" requirement had no representation anywhere in the
 * codebase.
 *
 * Defaults reproduce the previous behaviour exactly — 8 characters, no
 * composition requirements — so adding this rule to existing endpoints cannot
 * reject a password that used to be accepted. A tenant opts into anything
 * stricter via `settings.password_policy`.
 */
class PasswordPolicy implements ValidationRule
{
    /** Matches the old bare `min:8`, so existing tenants see no change. */
    public const DEFAULTS = [
        'min_length' => 8,
        'require_uppercase' => false,
        'require_lowercase' => false,
        'require_number' => false,
        'require_symbol' => false,
        // Days before a password must be changed. 0 = never expires.
        'expiry_days' => 0,
    ];

    public function __construct(private readonly ?Tenant $tenant = null) {}

    /**
     * The effective policy for a tenant, defaults filled in.
     *
     * @return array<string, int|bool>
     */
    public static function forTenant(?Tenant $tenant): array
    {
        $raw = $tenant?->getAttribute('settings');
        $settings = is_array($raw) ? $raw : [];
        $policy = $settings['password_policy'] ?? [];

        if (! is_array($policy)) {
            return self::DEFAULTS;
        }

        $merged = array_merge(self::DEFAULTS, array_intersect_key($policy, self::DEFAULTS));

        // A tenant cannot configure itself below the platform floor.
        $merged['min_length'] = max(8, (int) $merged['min_length']);
        $merged['expiry_days'] = max(0, (int) $merged['expiry_days']);

        return $merged;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $tenant = $this->tenant ?? app(CurrentTenant::class)->get();
        $policy = self::forTenant($tenant);

        if (mb_strlen($value) < $policy['min_length']) {
            $fail(__('auth.password_too_short', ['min' => $policy['min_length']]));

            // Return early: a short password will usually trip the composition
            // rules too, and four simultaneous errors is worse guidance than one.
            return;
        }

        if ($policy['require_uppercase'] && ! preg_match('/\p{Lu}/u', $value)) {
            $fail(__('auth.password_needs_uppercase'));
        }

        if ($policy['require_lowercase'] && ! preg_match('/\p{Ll}/u', $value)) {
            $fail(__('auth.password_needs_lowercase'));
        }

        if ($policy['require_number'] && ! preg_match('/\d/', $value)) {
            $fail(__('auth.password_needs_number'));
        }

        if ($policy['require_symbol'] && ! preg_match('/[^\p{L}\p{N}]/u', $value)) {
            $fail(__('auth.password_needs_symbol'));
        }
    }

    /**
     * Whether a password set at `$changedAt` has aged past the tenant's expiry.
     */
    public static function isExpired(?Tenant $tenant, mixed $changedAt): bool
    {
        $policy = self::forTenant($tenant);

        if ($policy['expiry_days'] <= 0 || $changedAt === null) {
            return false;
        }

        return now()->greaterThan(
            Carbon::parse($changedAt)->addDays($policy['expiry_days'])
        );
    }
}
