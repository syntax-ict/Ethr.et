<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;

/**
 * Whether, and how soon, the organisation's admins are told that a terminal
 * which only sends to ETHR has gone silent (DeviceSilence).
 *
 * Each organisation sets its own in `tenants.settings`
 * (`device_silence_alerts`, `device_silence_adms_minutes`,
 * `device_silence_push_grace_minutes`). On unless turned off. A stored value
 * outside the accepted range falls back to the default, as PayChangeThresholds
 * does.
 *
 * The defaults allow for the production scheduler: the GitHub Actions caller
 * is often ten to twenty minutes late, so a limit shorter than that would fire
 * on the delay itself.
 */
final readonly class DeviceSilenceRules
{
    public const DEFAULT_ADMS_MINUTES = 30;

    public const DEFAULT_PUSH_GRACE_MINUTES = 60;

    public const ADMS_MINUTES_MIN = 15;

    public const ADMS_MINUTES_MAX = 240;

    public const PUSH_GRACE_MINUTES_MIN = 30;

    public const PUSH_GRACE_MINUTES_MAX = 480;

    private function __construct(
        public bool $enabled,
        public int $admsMinutes,
        public int $pushGraceMinutes,
    ) {}

    public static function forTenant(?Tenant $tenant): self
    {
        $settings = $tenant?->getAttribute('settings');
        $settings = is_array($settings) ? $settings : [];

        $adms = self::integer($settings['device_silence_adms_minutes'] ?? null);
        $push = self::integer($settings['device_silence_push_grace_minutes'] ?? null);

        return new self(
            ($settings['device_silence_alerts'] ?? true) !== false,
            $adms !== null && $adms >= self::ADMS_MINUTES_MIN && $adms <= self::ADMS_MINUTES_MAX ? $adms : self::DEFAULT_ADMS_MINUTES,
            $push !== null && $push >= self::PUSH_GRACE_MINUTES_MIN && $push <= self::PUSH_GRACE_MINUTES_MAX ? $push : self::DEFAULT_PUSH_GRACE_MINUTES,
        );
    }

    private static function integer(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return is_int($value) ? $value : null;
    }
}
