<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * When a scheduled report or dashboard digest runs next: 06:00 where the
 * organisation is, every day, every Monday, or on the 1st, as the schedule
 * screen promises ("Daily, 6:00 AM" / "ዕለታዊ፣ 12:00 ጠዋት").
 *
 * Before this, the first run was 06:00 UTC (09:00 in Addis Ababa) and every
 * later one was simply a day, week or month after the last run, so a report
 * drifted by however late the hourly runner picked it up and never ran at
 * 06:00 at all.
 */
final class ScheduleSlot
{
    public const HOUR = 6;

    /** The next slot strictly after `$after` (now by default), as a UTC instant. */
    public static function next(?string $frequency, ?Tenant $tenant, ?CarbonInterface $after = null): Carbon
    {
        $zone = TenantTime::zone($tenant);
        $now = CarbonImmutable::instance($after ?? now())->setTimezone($zone);

        $slot = match ($frequency) {
            'weekly' => $now->startOfWeek(CarbonInterface::MONDAY)->setTime(self::HOUR, 0),
            'monthly' => $now->startOfMonth()->setTime(self::HOUR, 0),
            default => $now->setTime(self::HOUR, 0),
        };

        while ($slot->lessThanOrEqualTo($now)) {
            $slot = match ($frequency) {
                'weekly' => $slot->addWeek(),
                'monthly' => $slot->addMonthNoOverflow(),
                default => $slot->addDay(),
            };
        }

        return Carbon::instance($slot->utc());
    }

    /** The organisation a schedule belongs to, read without a tenant in context. */
    public static function forTenantId(?string $frequency, int|string|null $tenantId, ?CarbonInterface $after = null): Carbon
    {
        $tenant = $tenantId === null ? null : Tenant::query()->find($tenantId);

        return self::next($frequency, $tenant instanceof Tenant ? $tenant : null, $after);
    }
}
