<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\EthiopianPhone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The one phone rule for form requests, so no request writes its own regex.
 *
 * Two modes, because the two kinds of field really differ:
 *
 *  - `any()` is for a number that is recorded, not texted: an employee, an
 *    emergency contact, an applicant, a branch, the contact page. It takes an
 *    Ethiopian number in any way it is typed (`0911 22 33 44`, `+251911223344`,
 *    `911223344`) and also a number from another country written with its
 *    country code, because foreign staff and contacts are real.
 *
 *  - `ethiopianMobile()` is for a number that receives a text message: the
 *    sign-in code, the user's own phone, sign-up. Only the `9` and `7` ranges
 *    can receive one, so a landline is refused.
 *
 * Storage is not this rule's job: every write path already normalises through
 * `EthiopianPhone::canonicalOrRaw()`.
 */
final class Phone implements ValidationRule
{
    private function __construct(
        private readonly bool $mobileOnly,
        private readonly ?string $stored = null,
    ) {}

    public static function any(): self
    {
        return new self(false);
    }

    public static function ethiopianMobile(): self
    {
        return new self(true);
    }

    /**
     * Accept the value the record already holds, exactly as it is stored.
     *
     * Older rows were written through looser rules, imports and SCIM, so a
     * stored number may be one this rule would refuse: a partial `551234`, or a
     * foreign number that lost its plus. An edit form sends the phone back
     * unchanged, and refusing it would make every other field of that record
     * uneditable. Anything the user actually changes is checked in full.
     */
    public function unlessUnchanged(?string $stored): self
    {
        return new self($this->mobileOnly, $stored);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $this->stored !== null && trim($value) === trim($this->stored)) {
            return;
        }

        if (! is_string($value)) {
            $fail(__($this->mobileOnly ? 'auth.phone_invalid' : 'auth.phone_any_invalid'));

            return;
        }

        // canonical() drops every non-digit, so letters would otherwise slip
        // through as "digits with noise". A phone holds digits and separators.
        $typedLikeAPhone = preg_match('/^[\d\s+().-]+$/', $value) === 1;

        if ($this->mobileOnly) {
            if (! $typedLikeAPhone || ! EthiopianPhone::isMobile($value)) {
                $fail(__('auth.phone_invalid'));
            }

            return;
        }

        if ($typedLikeAPhone && (EthiopianPhone::isValid($value) || EthiopianPhone::isInternational($value))) {
            return;
        }

        $fail(__('auth.phone_any_invalid'));
    }
}
