<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\TitleAccessHeld;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Access\PositionAccess;
use App\Services\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class WatchHeldTitleAccessCommand extends Command
{
    protected $signature = 'access:watch-held-titles';

    protected $description = 'Tell the admins when an account starts waiting for the role its job title gives';

    public function handle(PositionAccess $access, CurrentTenant $current): int
    {
        $told = 0;

        // Each organisation is set as the current tenant here and everything
        // read inside it goes through the tenant scope: the sweep drops no
        // scope of its own. One organisation that fails is reported and the
        // rest still run.
        Tenant::operational()->each(function (Tenant $tenant) use ($access, $current, &$told): void {
            try {
                $current->set($tenant);

                $waiting = $this->waiting($access);
                $key = "title-access:told:{$tenant->id}";
                $before = $this->remembered($key);

                $new = array_diff_key($waiting, array_flip($before));

                // What waits now is what is remembered, so an account that was
                // applied drops out and tells again if it is held a second time.
                $this->remember($key, array_keys($waiting));

                if ($new === []) {
                    return;
                }

                // A failed dispatch gives the notice back, so the next sweep
                // tries it again, and does not stop the organisations after.
                try {
                    TitleAccessHeld::dispatch($tenant->id, array_map(
                        fn (string $pair): int => (int) explode(':', $pair)[0],
                        array_keys($new),
                    ));
                    $told++;
                } catch (Throwable $e) {
                    $this->remember($key, $before);
                    report($e);
                }
            } catch (Throwable $e) {
                report($e);
            } finally {
                $current->forget();
            }
        });

        $this->info("Told the admins about accounts waiting in {$told} organisation(s).");

        return self::SUCCESS;
    }

    /**
     * The accounts waiting now, keyed `userId:role` so an account whose job
     * title moved on to a different role counts as new again.
     *
     * @return array<string, true>
     */
    private function waiting(PositionAccess $access): array
    {
        $waiting = [];

        $access->heldQuery()->with('employee.position')->chunkById(200, function ($users) use ($access, &$waiting): void {
            /** @var User $user */
            foreach ($users as $user) {
                $to = $access->previewRole($user);

                if ($to !== null && $user->role !== $to) {
                    $waiting["{$user->id}:{$to->value}"] = true;
                }
            }
        });

        return $waiting;
    }

    /**
     * What the last sweep remembered. If the cache fails nothing is
     * remembered, so a notice is never lost to a cache outage.
     *
     * @return list<string>
     */
    private function remembered(string $key): array
    {
        try {
            $value = Cache::get($key);

            return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @param  list<string>  $pairs */
    private function remember(string $key, array $pairs): void
    {
        try {
            if ($pairs === []) {
                Cache::forget($key);
            } else {
                Cache::put($key, $pairs, now()->addDays(60));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
