<?php

declare(strict_types=1);

namespace App\Services\Observability;

use App\Services\CurrentTenant;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Strips tenant-sensitive data out of every event before it leaves the process, and
 * tags the event with the tenant it belongs to.
 *
 * ETHR encrypts bank account numbers, TINs and national IDs at rest (see the `casts()`
 * on Employee / EmployeeBankDetail / SsoSetting). None of that protects an error
 * report: a validation failure or a 500 carries the *plaintext* request body, so an
 * unscrubbed Sentry install would quietly become an unencrypted copy of exactly the
 * fields the database is careful to encrypt — in a third-party system, outside the
 * audit log, readable by anyone with error-tracker access.
 *
 * Referenced from config/sentry.php as a `[class, method]` callable rather than a
 * closure so that `php artisan config:cache` can still var_export the config.
 */
class SentryScrubber
{
    /**
     * Request/context keys whose values must never leave the process.
     *
     * Matched case-insensitively against the *whole* key, plus a few substring rules
     * below, so `bank_account_number` is caught as well as `account_number`.
     *
     * @var list<string>
     */
    private const REDACT_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'account_number',
        'bank_account',
        'national_id',
        'national_id_hash',
        'tin',
        'tin_number',
        'totp_secret',
        'two_factor_secret',
        'recovery_codes',
        'idp_certificate',
        'private_key',
        'client_secret',
        'api_key',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'cookie',
        'set-cookie',
        'x-xsrf-token',
        'idempotency-key',
    ];

    /**
     * Substrings that make a key sensitive wherever they appear, so newly added fields
     * (`employee_national_id`, `sso_client_secret`, …) are covered without a code change.
     *
     * @var list<string>
     */
    private const REDACT_FRAGMENTS = [
        'password',
        'secret',
        'token',
        'national_id',
        'account_number',
    ];

    private const REDACTED = '[redacted]';

    public static function handle(Event $event, ?EventHint $hint = null): ?Event
    {
        $request = $event->getRequest();

        if ($request !== []) {
            $event->setRequest(self::redact($request));
        }

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, self::redact($context));
        }

        $extra = $event->getExtra();
        if ($extra !== []) {
            $event->setExtra(self::redact($extra));
        }

        self::scrubBreadcrumbs($event);
        self::tagTenant($event);

        return $event;
    }

    /**
     * Breadcrumbs are a second, easily-missed path out of the process: Laravel logs,
     * queue jobs and HTTP client calls are all captured as breadcrumbs with structured
     * metadata attached. SQL bindings are disabled in config/sentry.php, but a log line
     * written with context (`Log::info('...', ['national_id' => …])`) would still carry
     * the value. Rebuilds each breadcrumb with its metadata redacted.
     *
     * Free-text breadcrumb *messages* are left alone — pattern-matching arbitrary prose
     * produces false confidence, so the rule stays "never log a secret into a message".
     */
    private static function scrubBreadcrumbs(Event $event): void
    {
        $breadcrumbs = $event->getBreadcrumbs();

        if ($breadcrumbs === []) {
            return;
        }

        $event->setBreadcrumb(array_map(
            static fn (Breadcrumb $crumb): Breadcrumb => new Breadcrumb(
                $crumb->getLevel(),
                $crumb->getType(),
                $crumb->getCategory(),
                $crumb->getMessage(),
                self::redact($crumb->getMetadata()),
                $crumb->getTimestamp(),
            ),
            $breadcrumbs,
        ));
    }

    /**
     * Tags the event with the resolved tenant so errors are attributable without
     * anyone having to dig through the payload — and so one tenant's incident can be
     * filtered out from another's.
     */
    private static function tagTenant(Event $event): void
    {
        // Never let observability break the request it is observing.
        try {
            $tenant = app(CurrentTenant::class);

            if ($tenant->resolved()) {
                $event->setTag('tenant.id', (string) $tenant->id());
            }
        } catch (\Throwable) {
            // Container unavailable (e.g. very early boot) — the tag is optional.
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::redact($value);

                continue;
            }

            if (is_string($key) && self::isSensitive($key)) {
                $data[$key] = self::REDACTED;
            }
        }

        return $data;
    }

    private static function isSensitive(string $key): bool
    {
        $normalized = strtolower($key);

        if (in_array($normalized, self::REDACT_KEYS, true)) {
            return true;
        }

        foreach (self::REDACT_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
