<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Models\Device;
use App\Models\Tenant;
use App\Models\UnmatchedDevicePunch;
use App\Services\CurrentTenant;
use App\Support\HealthSignals;
use App\Support\TenantTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What one terminal sent today, in the organisation's day, counted from what
 * is already stored, so no table was added for it.
 *
 * - `received`: every punch the terminal sent today that ETHR accepted: the
 *   inbox rows created today, plus the resends counted below.
 * - `recorded`: handed to attendance.
 * - `held`: its badge matched nobody, so it waits in the held list.
 * - `quarantined`: ETHR could not record it and a person has to look.
 * - `waiting`: in the inbox, not worked yet.
 * - `duplicate`: a resend of a punch already in the inbox. The inbox ignores
 *   it, so nothing stores it; each push counts it in the cache, by device and
 *   day, for 48 hours. A cache that was cleared makes this count read low,
 *   never high.
 *
 * Every query states the organisation: these are read by a person looking at
 * a device page, and the model's scope is not in play on the query builder.
 */
final class DeviceDayCounts
{
    public const TTL_HOURS = 48;

    /**
     * A push has been answered: of the `$accepted` punches in it, `$new` were
     * stored and the rest were resends. Never throws: a downed cache must not
     * turn an acknowledged post into a 500.
     */
    public static function notePush(Device $device, int $accepted, int $new): void
    {
        $duplicates = $accepted - $new;
        if ($duplicates <= 0) {
            return;
        }

        try {
            $key = self::key($device->id, self::dayFor($device));
            HealthSignals::store()->add($key, 0, now()->addHours(self::TTL_HOURS));
            HealthSignals::store()->increment($key, $duplicates);
        } catch (\Throwable $e) {
            // The count is a courtesy; the punch is already stored.
            report($e);
        }
    }

    /**
     * @param  Collection<int, Device>|list<Device>  $devices  all of one organisation
     * @return array<int, array{day: string, received: int, recorded: int, held: int, duplicate: int, quarantined: int, waiting: int}> by device id
     */
    public static function forDevices(int $tenantId, Collection|array $devices): array
    {
        $devices = collect($devices)->values();
        if ($devices->isEmpty()) {
            return [];
        }

        $tenant = Tenant::query()->find($tenantId);
        $zone = TenantTime::zone($tenant);
        $start = Carbon::now($zone)->startOfDay();
        $day = $start->format('Y-m-d');
        $from = $start->copy()->utc();
        $to = $start->copy()->addDay()->utc();
        $ids = $devices->pluck('id')->all();

        $rows = DB::table('device_inbound_punches as p')
            ->leftJoin('unmatched_device_punches as u', function ($join) {
                $join->on('u.device_id', '=', 'p.device_id')
                    ->on('u.idempotency_key', '=', 'p.idempotency_key')
                    ->on('u.tenant_id', '=', 'p.tenant_id')
                    ->where('u.status', '=', UnmatchedDevicePunch::PENDING);
            })
            ->where('p.tenant_id', $tenantId)
            ->whereIn('p.device_id', $ids)
            ->where('p.created_at', '>=', $from)
            ->where('p.created_at', '<', $to)
            ->groupBy('p.device_id')
            ->selectRaw('p.device_id as device_id')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when p.failed_at is not null then 1 else 0 end) as quarantined')
            ->selectRaw('sum(case when p.processed_at is null then 1 else 0 end) as waiting')
            ->selectRaw('sum(case when p.processed_at is not null and p.failed_at is null and u.id is not null then 1 else 0 end) as held')
            ->get()
            ->keyBy('device_id');

        $counted = self::duplicatesFor($ids, $day);

        $out = [];
        foreach ($devices as $device) {
            $row = $rows->get($device->id);
            $total = (int) ($row->total ?? 0);
            $quarantined = (int) ($row->quarantined ?? 0);
            $waiting = (int) ($row->waiting ?? 0);
            $held = (int) ($row->held ?? 0);
            $duplicate = $counted[$device->id] ?? 0;

            $out[$device->id] = [
                'day' => $day,
                'received' => $total + $duplicate,
                'recorded' => max(0, $total - $quarantined - $waiting - $held),
                'held' => $held,
                'duplicate' => $duplicate,
                'quarantined' => $quarantined,
                'waiting' => $waiting,
            ];
        }

        return $out;
    }

    /**
     * One read for every device on the page.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private static function duplicatesFor(array $ids, string $day): array
    {
        try {
            $keys = [];
            foreach ($ids as $id) {
                $keys[self::key($id, $day)] = $id;
            }

            $out = [];
            foreach (HealthSignals::store()->many(array_keys($keys)) as $key => $value) {
                $out[$keys[$key]] = max(0, (int) $value);
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private static function dayFor(Device $device): string
    {
        return Carbon::now(TenantTime::zone(app(CurrentTenant::class)->get() ?? Tenant::query()->find($device->tenant_id)))->format('Y-m-d');
    }

    private static function key(int $deviceId, string $day): string
    {
        return "device-counts:{$deviceId}:{$day}";
    }
}
