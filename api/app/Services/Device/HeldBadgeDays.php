<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Models\UnmatchedDevicePunch;
use Illuminate\Support\Facades\DB;

/**
 * The days a held badge punched on, from the terminal's own time stamp.
 *
 * The first ten characters of `occurred_raw` are the date in both formats the
 * adapters store ("2026-11-17T09:00:00+03:00", "2026-11-17 09:00:00"), and a
 * row that does not start with one is left out. One reader, so the date a new
 * employee is prefilled with (the list's `first_day`) and the date the
 * `hired_after` warning compares against cannot drift apart.
 */
final class HeldBadgeDays
{
    /**
     * The distinct valid days of every wanted badge's pending punches.
     *
     * @param  list<array{device_id: int, badge: string}>  $badges
     * @param  list<int>  $tenantIds
     * @return array<string, list<string>> key() => the days, as Y-m-d
     */
    public static function read(array $badges, array $tenantIds): array
    {
        if ($badges === [] || $tenantIds === []) {
            return [];
        }

        $wanted = [];
        foreach ($badges as $badge) {
            $wanted[self::key($badge['device_id'], $badge['badge'])] = true;
        }

        $rows = UnmatchedDevicePunch::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', UnmatchedDevicePunch::PENDING)
            ->whereIn('device_id', array_values(array_unique(array_column($badges, 'device_id'))))
            ->whereIn('badge', array_values(array_unique(array_column($badges, 'badge'))))
            ->selectRaw('device_id, badge, SUBSTR(occurred_raw, 1, 10) as day')
            ->groupBy('device_id', 'badge', DB::raw('SUBSTR(occurred_raw, 1, 10)'))
            ->get();

        $days = [];
        foreach ($rows as $row) {
            $key = self::key((int) $row->getAttribute('device_id'), (string) $row->getAttribute('badge'));
            $day = (string) $row->getAttribute('day');

            if (isset($wanted[$key]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1) {
                $days[$key][] = $day;
            }
        }

        return $days;
    }

    /** The earliest of a badge's days, or null when it has none. */
    public static function earliest(array $days, int $deviceId, string $badge): ?string
    {
        $mine = $days[self::key($deviceId, $badge)] ?? [];

        return $mine === [] ? null : min($mine);
    }

    public static function key(int $deviceId, string $badge): string
    {
        return $deviceId.':'.mb_strtolower($badge);
    }
}
