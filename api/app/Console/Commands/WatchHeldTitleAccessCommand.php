<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Events\TitleAccessHeld;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Access\PositionAccess;
use App\Services\CurrentTenant;
use App\Support\Access\TitleAccessHealth;
use App\Support\HealthSignals;
use App\Support\RoleAssignment;
use Illuminate\Console\Command;
use Throwable;

class WatchHeldTitleAccessCommand extends Command
{
    protected $signature = 'access:watch-held-titles';

    protected $description = 'Tell the admins when an account starts waiting for the role its job title gives';

    public function handle(PositionAccess $access, CurrentTenant $current): int
    {
        $told = 0;
        $scanned = 0;
        $waitingTotal = 0;
        $rememberedTotal = 0;

        // Each organisation is set as the current tenant here and everything
        // read inside it goes through the tenant scope: the sweep drops no
        // scope of its own. One organisation that fails is reported and the
        // rest still run.
        Tenant::operational()->each(function (Tenant $tenant) use ($access, $current, &$told, &$scanned, &$waitingTotal, &$rememberedTotal): void {
            // Counted before the work, so an organisation whose own try block
            // throws still shows as scanned.
            $scanned++;

            try {
                $current->set($tenant);

                $waiting = $this->waiting($access);
                $waitingTotal += count($waiting);
                // The v2 key exists because the first release's listener failed on
                // every job while the sweep remembered those accounts as told, so
                // a new key announces them once.
                $key = "title-access:told:v2:{$tenant->id}";
                $before = $this->remembered($key);

                $new = array_diff_key($waiting, array_flip($before));

                // What waits now is what is remembered, so an account that was
                // applied drops out and tells again if it is held a second time.
                $remember = array_keys($waiting);

                // Dispatched before it is remembered: a failed dispatch keeps
                // only what was already told, so the next sweep tries the new
                // accounts again, and does not stop the organisations after.
                if ($new !== []) {
                    try {
                        TitleAccessHeld::dispatch($tenant->id, array_map(
                            fn (string $pair): int => (int) explode(':', $pair)[0],
                            array_keys($new),
                        ));
                        $told++;
                    } catch (Throwable $e) {
                        $remember = array_values(array_intersect($remember, $before));
                        report($e);
                    }
                }

                $this->remember($key, $remember);
                $rememberedTotal += count($remember);
            } catch (Throwable $e) {
                report($e);
            } finally {
                $current->forget();
            }
        });

        // Written even when nothing was found: a sweep that ran and found
        // nothing must read differently from a sweep that never ran.
        TitleAccessHealth::recordSweep($scanned, $waitingTotal, $told, $rememberedTotal);

        $this->info("Told the admins about accounts waiting in {$told} organisation(s).");

        return self::SUCCESS;
    }

    /**
     * The accounts waiting now that someone here may give the role to, keyed
     * `userId:role` so an account whose job title moved on to a different role
     * counts as new again. One that nobody may act on yet is left out, so it
     * is still new when someone who may give the role joins (the listener
     * tells only those who may).
     *
     * @return array<string, true>
     */
    private function waiting(PositionAccess $access): array
    {
        $admins = User::query()
            ->whereIn('role', [UserRole::TENANT_ADMIN->value, UserRole::HR_ADMIN->value])
            ->where('status', 'active')
            ->get();

        if ($admins->isEmpty()) {
            return [];
        }

        $waiting = [];

        $access->heldQuery()->with('employee.position')->chunkById(200, function ($users) use ($access, $admins, &$waiting): void {
            /** @var User $user */
            foreach ($users as $user) {
                $to = $access->previewRole($user);

                if ($to !== null && $user->role !== $to
                    && $admins->contains(fn (User $admin): bool => RoleAssignment::mayChange($admin, $user, $to))) {
                    $waiting["{$user->id}:{$to->value}"] = true;
                }
            }
        });

        return $waiting;
    }

    /**
     * What the last sweep remembered, in the `signals` store: every release
     * runs `optimize:clear`, which empties the default store, and a memory
     * kept there would announce every waiting account again after each
     * release. If the store fails nothing is remembered, so a notice is never
     * lost to an outage.
     *
     * @return list<string>
     */
    private function remembered(string $key): array
    {
        try {
            $value = HealthSignals::store()->get($key);

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
                HealthSignals::store()->forget($key);
            } else {
                HealthSignals::store()->put($key, $pairs, now()->addDays(60));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
