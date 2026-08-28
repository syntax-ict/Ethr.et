<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Support\EthiopianPhone;

/**
 * Ethiopian MSISDN normalization.
 *
 * Phone numbers reach the system in at least three shapes — `0911223344` from
 * paper forms, `+251911223344` from device exports, `251911223344` from CSV
 * imports that lost the plus. The SESSIONHANDOFF lists this reconciliation as an
 * open item for identity matching; here it is scoped to the one thing a gateway
 * needs, which is E.164 without the plus.
 */
final class SmsNumber
{
    /**
     * Gateway form: E.164 *without* the plus, which is what the operator API
     * expects. Parsing is delegated to EthiopianPhone so this class and the
     * storage canonicalizer cannot drift into disagreeing about what counts as
     * the same subscriber — a disagreement that already cost a silent
     * authentication failure once (see that class).
     *
     * Unparseable input falls through to bare digits rather than throwing, so
     * isValid() below stays the single place that decides what is sendable.
     */
    public static function normalize(string $number): string
    {
        $canonical = EthiopianPhone::canonical($number);

        if ($canonical !== null) {
            return substr($canonical, 1);
        }

        return preg_replace('/\D+/', '', $number) ?? '';
    }

    public static function isValid(string $number): bool
    {
        // Ethiopian mobile: 251 + 9 digits, first of which is 7 or 9. A landline
        // is a valid phone number but not a valid SMS destination.
        return EthiopianPhone::isMobile($number);
    }
}
