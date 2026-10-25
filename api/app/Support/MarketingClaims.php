<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Claims the public site may not make, whoever is typing.
 *
 * The capability audit of 2026-10-17 read every sentence on the landing page
 * against the code and took these off it: features that are not built,
 * devices nobody has verified on real hardware, legal conclusions that are
 * the employer's to draw, and figures nobody can substantiate. While the copy
 * was code, `marketing-pages.test.tsx` kept them off. Now that an operator
 * can type the page into a form, the test guards nothing; this list does, as
 * a validation rule on every marketing text field.
 *
 * Each entry is a case-insensitive pattern and the reason it is here, which
 * the validation message does not say (a 422 that explains which claim is
 * forbidden and why belongs in the console copy, not in the API).
 */
final class MarketingClaims
{
    /** @var array<string, string> pattern => reason */
    public const FORBIDDEN = [
        // Not built: no performance module, no training, no goals.
        '/performance review/i' => 'performance reviews are not built',
        '/appraisal/i' => 'appraisals are not built',
        '/\btraining\b/i' => 'training is not built',
        '/certif/i' => 'certifications are not tracked',
        '/\bgoals?\b/i' => 'goal tracking is not built',
        '/\bOKRs?\b/' => 'OKRs are not built',
        // No device is verified on real hardware, and biometric data is
        // sensitive under Proclamation 1321/2024 Art. 2.
        '/hikvision|zkteco|suprema/i' => 'no device brand is verified on hardware',
        '/biometric/i' => 'biometric claims were removed by the 2026-10-17 audit',
        '/fingerprint/i' => 'fingerprint claims were removed by the 2026-10-17 audit',
        // Import is CSV; approval is single-step; delegation does not exist.
        '/\bexcel\b/i' => 'import is CSV, not Excel',
        '/multi-?level/i' => 'approval is single-step',
        '/delegat/i' => 'delegation is not built',
        '/maker-?checker/i' => 'maker-checker is not built',
        // Legal conclusions the employer draws as data controller.
        '/full(y)? compli/i' => 'compliance is the employer\'s conclusion to draw',
        '/no data leaves/i' => 'nothing measures that every byte stays in the country',
        '/your data,? your servers/i' => 'hosting is shared, not the customer\'s servers',
        '/every action is logged/i' => 'the audit trail covers sensitive actions, not every action',
        '/no cross-tenant access is possible/i' => 'isolation fails closed; it is not absolute',
        // Figures nobody can substantiate, and an SLA the terms deny.
        '/\b500\+/' => 'invented customer count',
        '/50,?000\+/' => 'invented employee count',
        '/\b1M\+/' => 'invented payroll volume',
        '/99\.9\s?%/' => 'no SLA exists',
        '/\buptime\b/i' => 'no SLA exists',
        // Integrations that do not exist.
        '/telebirr/i' => 'no Telebirr integration exists',
        '/bank integration/i' => 'payroll produces a bank transfer file, not an integration',
    ];

    /** The reason a text is refused, or null when it is allowed. */
    public static function violation(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        foreach (self::FORBIDDEN as $pattern => $reason) {
            if (preg_match($pattern, $text) === 1) {
                return $reason;
            }
        }

        return null;
    }
}
