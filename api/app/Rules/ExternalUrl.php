<?php

declare(strict_types=1);

namespace App\Rules;

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

        if ($this->isInternalHost($host)) {
            $fail('The :attribute must not point to an internal address.');

            return;
        }

        $scheme = parse_url((string) $value, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            $fail('The :attribute must use http or https.');
        }
    }

    private function isInternalHost(string $host): bool
    {
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
            return true;
        }

        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }

        $ip = gethostbyname($host);
        if ($ip === $host) {
            return false;
        }

        return $this->isPrivateIp($ip);
    }

    private function isPrivateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
