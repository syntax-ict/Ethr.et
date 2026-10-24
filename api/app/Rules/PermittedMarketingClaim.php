<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\MarketingClaims;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a marketing text that makes a claim the public site may not make.
 * The list and its reasons are in {@see MarketingClaims}.
 */
class PermittedMarketingClaim implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (MarketingClaims::violation($value) !== null) {
            $fail(__('validation.marketing_claim'));
        }
    }
}
