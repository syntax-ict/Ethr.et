<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;

/**
 * When a person's net pay has moved enough since their last released payslip
 * to be listed before a run is approved (PreApprovalChecks): by at least
 * `percent` of what it was, and by at least `minCents`. Both must hold, so a
 * 20% change on a small payslip, or ETB 600 on a large one, is not listed.
 *
 * Each organisation sets its own in `tenants.settings`
 * (`pay_change_alert_percent`, `pay_change_alert_min_cents`). A stored value
 * outside the accepted range falls back to the default.
 */
final readonly class PayChangeThresholds
{
    public const DEFAULT_PERCENT = 20;

    public const DEFAULT_MIN_CENTS = 50_000;

    public const PERCENT_MIN = 1;

    public const PERCENT_MAX = 100;

    public const MIN_CENTS_MAX = 100_000_000;

    private function __construct(public int $percent, public int $minCents) {}

    public static function forTenant(?Tenant $tenant): self
    {
        $settings = $tenant?->getAttribute('settings');
        $settings = is_array($settings) ? $settings : [];

        $percent = self::integer($settings['pay_change_alert_percent'] ?? null);
        $minCents = self::integer($settings['pay_change_alert_min_cents'] ?? null);

        return new self(
            $percent !== null && $percent >= self::PERCENT_MIN && $percent <= self::PERCENT_MAX ? $percent : self::DEFAULT_PERCENT,
            $minCents !== null && $minCents >= 0 && $minCents <= self::MIN_CENTS_MAX ? $minCents : self::DEFAULT_MIN_CENTS,
        );
    }

    /** Whether a change from `$before` to `$after` (both cents) is listed. */
    public function flags(int $before, int $after): bool
    {
        $change = abs($after - $before);
        if ($change === 0 || $change < $this->minCents) {
            return false;
        }

        return $before === 0 || $change * 100 >= $this->percent * abs($before);
    }

    private static function integer(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return is_int($value) ? $value : null;
    }
}
