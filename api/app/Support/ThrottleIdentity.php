<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\CurrentTenant;

/**
 * The one spelling of a sign-in identifier that rate limits and lockouts are
 * counted under.
 *
 * Accounts are found by phone through `EthiopianPhone::variants()`, which
 * ignores everything but the digits, while every limiter keyed on the raw
 * input. `0911223344`, `0911-223344` and `+251 911 223 344` reached the same
 * account under three different counters, so the per-account login lockout,
 * the sign-in code attempts and the SMS send limit never tripped (security
 * review 2026-10-30). An Ethiopian number is counted in its canonical form;
 * anything else (an email, a username, a foreign number) lowercased and
 * trimmed.
 */
final class ThrottleIdentity
{
    public static function of(mixed $value): string
    {
        $value = mb_strtolower(trim(is_scalar($value) ? (string) $value : ''));

        if ($value === '' || str_contains($value, '@')) {
            return $value;
        }

        return EthiopianPhone::canonical($value) ?? $value;
    }

    /**
     * The organisation the request resolved to, for the same keys. An employee
     * code or email is unique within one organisation only, so a counter
     * without it let failed attempts at one organisation lock the same
     * identifier out at another behind the same address. `-` when none was
     * resolved (the platform host, or a request that names no organisation).
     */
    public static function organisation(): string
    {
        $tenant = app(CurrentTenant::class);

        return $tenant->resolved() ? (string) $tenant->id() : '-';
    }
}
