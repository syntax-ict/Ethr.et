<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\DeviceSilent;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Device\DeviceSilence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class WatchDeviceSilenceCommand extends Command
{
    protected $signature = 'devices:watch-silence';

    protected $description = 'Tell the admins when a terminal that only sends to ETHR has gone silent';

    public function handle(DeviceSilence $silence, CurrentTenant $current): int
    {
        $told = 0;

        // Each organisation is set as the current tenant here, and everything
        // read inside it goes through the tenant scope: the sweep drops no
        // scope of its own. One organisation that fails is reported and the
        // rest still run.
        Tenant::operational()->each(function (Tenant $tenant) use ($silence, $current, &$told): void {
            try {
                $current->set($tenant);

                foreach ($silence->silentDevices($tenant) as $found) {
                    $device = $found['device'];

                    $key = "device-notice:silent:{$device->id}:{$found['date']}";

                    if (! $this->firstNotice($key)) {
                        continue;
                    }

                    // A failed dispatch gives the notice back, so the next
                    // sweep tries it again, and does not stop the devices after.
                    try {
                        DeviceSilent::dispatch(
                            $tenant->id,
                            (string) $device->public_id,
                            (string) $device->name,
                            $device->branch?->name,
                            $found['kind'],
                            $found['since']->toIso8601String(),
                        );
                        $told++;
                    } catch (Throwable $e) {
                        $this->giveBack($key);
                        report($e);
                    }
                }
            } catch (Throwable $e) {
                report($e);
            } finally {
                $current->forget();
            }
        });

        $this->info("Told the admins about {$told} silent device(s).");

        return self::SUCCESS;
    }

    /**
     * At most one notice per device per working day, keyed on the day the
     * working window began (a night shift is one day, whichever side of
     * midnight the sweep runs). If the cache fails the answer is yes: a notice
     * is never lost to a cache outage.
     */
    private function firstNotice(string $key): bool
    {
        try {
            return Cache::add($key, true, now()->addDays(2));
        } catch (Throwable $e) {
            report($e);

            return true;
        }
    }

    private function giveBack(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
