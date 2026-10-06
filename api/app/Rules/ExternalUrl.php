<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\OutboundHost;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ExternalUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = parse_url((string) $value, PHP_URL_HOST);

        if (! $host) {
            $fail(__('validation.url'));

            return;
        }

        if (OutboundHost::isInternal($host)) {
            $fail('The :attribute must not point to an internal address.');

            return;
        }

        $scheme = parse_url((string) $value, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            $fail('The :attribute must use http or https.');
        }
    }
}
