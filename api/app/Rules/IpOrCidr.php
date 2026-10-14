<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One entry of a device's IP allowlist: an address, or an IPv4 range.
 *
 * Device::webhookIpAllowed has always matched CIDR ranges, but the request
 * accepted single addresses only — and a terminal on ordinary broadband gets
 * a new address from its provider's block every so often, so a range is the
 * only allowlist that keeps working. A range wider than /8 is refused: /0
 * would admit the whole internet, which is no allowlist at all.
 */
final class IpOrCidr implements ValidationRule
{
    public const WIDEST_PREFIX = 8;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('validation.ip', ['attribute' => $attribute]));

            return;
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return;
        }

        [$subnet, $bits] = array_pad(explode('/', $value, 2), 2, null);
        $valid = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && $bits !== null
            && ctype_digit($bits)
            && (int) $bits >= self::WIDEST_PREFIX
            && (int) $bits <= 32;

        if (! $valid) {
            $fail(__('validation.ip', ['attribute' => $attribute]));
        }
    }
}
