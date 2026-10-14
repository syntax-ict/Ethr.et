<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\OutboundHost;
use App\Support\WebhookTargets;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ExternalUrl implements ValidationRule
{
    /**
     * @param  bool  $webhookTarget  a webhook URL, which honours the local-only
     *                               WEBHOOK_ALLOW_PRIVATE_HOSTS switch; every
     *                               other outbound URL (the SMS gateway) never does
     */
    public function __construct(private readonly bool $webhookTarget = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = parse_url((string) $value, PHP_URL_HOST);

        if (! $host) {
            $fail(__('validation.url'));

            return;
        }

        $refused = $this->webhookTarget ? WebhookTargets::refused($host) : OutboundHost::isInternal($host);
        if ($refused) {
            $fail('The :attribute must not point to an internal address.');

            return;
        }

        $scheme = parse_url((string) $value, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            $fail('The :attribute must use http or https.');
        }
    }
}
