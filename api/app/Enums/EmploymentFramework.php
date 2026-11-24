<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The body of employment law an organisation's staff are governed by, held as
 * the `employment.framework` rule of the employment domain
 * (docs/payroll/STATUTORY-PARAMETERS.md, "Domains").
 *
 * LABOUR_PROCLAMATION is the private-sector Labour Proclamation,
 * FEDERAL_CIVIL_SERVICE the Federal Civil Servants Proclamation,
 * REGIONAL_CIVIL_SERVICE the civil service law of each region, and
 * UNSPECIFIED a kind whose governing law has not been read.
 *
 * The codes carry no proclamation number because laws are replaced; the
 * number lives in the rule's legal reference, which a new row can change.
 */
enum EmploymentFramework: string
{
    case LABOUR_PROCLAMATION = 'labour_proclamation';
    case FEDERAL_CIVIL_SERVICE = 'federal_civil_service';
    case REGIONAL_CIVIL_SERVICE = 'regional_civil_service';
    case UNSPECIFIED = 'unspecified';

    /** Whether the text of this framework has been read, so rules may be written from it. */
    public function hasStatutoryRules(): bool
    {
        return match ($this) {
            self::LABOUR_PROCLAMATION, self::FEDERAL_CIVIL_SERVICE => true,
            self::REGIONAL_CIVIL_SERVICE, self::UNSPECIFIED => false,
        };
    }
}
