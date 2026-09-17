<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A URL safe to publish as a link on a tenant's public landing page.
 *
 * Builds on ExternalUrl rather than restating it: that rule already refuses a
 * non-http(s) scheme and anything resolving to a private or link-local address,
 * and duplicating those checks is how two subtly different definitions of "safe
 * URL" end up in one codebase. What this adds is an optional host allow-list,
 * which is what makes a "social link" mean what it says.
 *
 * Without the allow-list, `social_links.facebook` is simply a link with a
 * Facebook label — a tenant administrator, or anyone who compromises one, could
 * point it anywhere and visitors would click it believing the destination. With
 * it, the label and the destination cannot disagree.
 */
class PublicUrl implements ValidationRule
{
    /** @param  list<string>  $allowedHosts  Empty means any public host. */
    public function __construct(private readonly array $allowedHosts = []) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail(__('validation.url'));

            return;
        }

        $failed = false;

        // Scheme, resolvability and the private-address checks, unchanged.
        //
        // The adapter mirrors the framework's own `$fail` signature exactly —
        // second parameter, and the PotentiallyTranslatedString it returns —
        // rather than the `Closure(string): void` that reads as sufficient.
        // ExternalUrl's parameter is typed against the real contract, so a
        // narrower closure is a type error, and a caller that used the return
        // value (`$fail(...)->translate([...])`, the documented way to pass
        // replacements) would get null from it.
        (new ExternalUrl)->validate(
            $attribute,
            $value,
            function (string $message, ?string $failedAttribute = null) use ($fail, &$failed): PotentiallyTranslatedString {
                $failed = true;

                return $fail($message);
            }
        );

        if ($failed || $this->allowedHosts === []) {
            return;
        }

        $host = strtolower((string) parse_url($value, PHP_URL_HOST));

        if (! in_array($host, $this->allowedHosts, true)) {
            $fail(__('The :attribute must be a link on :hosts.', [
                'hosts' => implode(' or ', $this->allowedHosts),
            ]));
        }
    }
}
