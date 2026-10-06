<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ethiopian phone numbers, canonicalized for storage and lookup.
 *
 * The same subscriber reaches this system as `0911223344` (how Ethiopians write
 * their own number, and what paper forms and most UI input carries),
 * `+251911223344` (E.164 — what tenant registration stores and what
 * `UserFactory` generates), `251911223344` (CSV imports that lost the plus), or
 * a bare `911223344`. They are one number, and anything that compares them as
 * strings is wrong.
 *
 * That was not hypothetical: `OtpController::resolveUser()` matched the raw
 * input against `users.phone` exactly, so a user stored in E.164 who typed the
 * local form was simply not found. Because the OTP endpoints answer identically
 * for known and unknown numbers (deliberately, to avoid enumerating accounts),
 * the failure was silent — the caller got "check your phone" and waited for a
 * code that was never issued.
 *
 * Canonical form here is **E.164 with the plus** (`+251XXXXXXXXX`), because that
 * is what registration already writes and what `users.phone` is validated
 * against. This is deliberately *not* the same as the gateway form produced by
 * `Services\Sms\SmsNumber`, which needs E.164 *without* the plus; that class now
 * delegates its parsing here so the two cannot drift apart.
 */
final class EthiopianPhone
{
    /**
     * The subscriber number: 9 digits, not starting with 0.
     *
     * Covers mobile (leading 9, or 7 on the newer ranges) and landline (leading
     * 1-5 by region). Mobile-only checks belong in isMobile(), because a stored
     * contact number is legitimately a landline while an SMS destination is not.
     */
    private const SUBSCRIBER = '/^[1-9]\d{8}$/';

    /**
     * Canonical storage form, or null when the input is not a recognizable
     * Ethiopian number. Null rather than the raw string: a caller that cannot
     * canonicalize should decide what to do, not silently persist junk.
     */
    public static function canonical(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        // 251911223344 / +251911223344 → 911223344
        if (str_starts_with($digits, '251')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            // 0911223344 → 911223344
            $digits = substr($digits, 1);
        }

        if (preg_match(self::SUBSCRIBER, $digits) !== 1) {
            return null;
        }

        return '+251'.$digits;
    }

    public static function isValid(string $phone): bool
    {
        return self::canonical($phone) !== null;
    }

    /**
     * Canonicalize for storage on a write path, preserving anything that is not a
     * recognizable Ethiopian number rather than nulling it.
     *
     * Registration already canonicalizes (via RegisterTenantRequest), but the
     * bulk/automated write paths — CSV import, workforce migration, SCIM, SSO
     * auto-provision, and profile self-service — persisted whatever raw shape
     * their source carried, so the same subscriber landed as `+251…`, `0…`,
     * `251…` or bare `9…` depending on how they were created. Lookups tolerate
     * that (see variants()), but storing one form keeps the roster consistent.
     * A null input stays null; an unparseable value is kept verbatim so a genuine
     * foreign or malformed number is never silently discarded here.
     */
    public static function canonicalOrRaw(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        return self::canonical($phone) ?? $phone;
    }

    /**
     * Ethiopian mobile ranges start 9 (legacy) or 7 (newer Ethio Telecom and
     * Safaricom Ethiopia allocations). Landlines are valid numbers but cannot
     * receive an SMS.
     */
    public static function isMobile(string $phone): bool
    {
        $canonical = self::canonical($phone);

        return $canonical !== null && preg_match('/^\+251[79]/', $canonical) === 1;
    }

    /**
     * Every shape this number may already be stored as, for a tolerant lookup.
     *
     * Existing rows predate canonicalization and were written in whichever form
     * their source used, so querying only the canonical form would still miss
     * them. Using this in a `whereIn` fixes lookups for historical data without
     * a migration that would have to rewrite every phone column in the schema.
     *
     * @return array<int, string> canonical form first
     */
    public static function variants(string $phone): array
    {
        $canonical = self::canonical($phone);

        if ($canonical === null) {
            // Not parseable — search for exactly what was asked for rather than
            // silently widening the query.
            return [$phone];
        }

        $subscriber = substr($canonical, 4);

        return [
            $canonical,          // +251911223344
            '0'.$subscriber,     // 0911223344
            '251'.$subscriber,   // 251911223344
            $subscriber,         // 911223344
        ];
    }
}
