<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HealthCheckCommand extends Command
{
    protected $signature = 'health:check';

    protected $description = 'Check that critical services (DB, cache) are reachable';

    public function handle(): int
    {
        try {
            DB::connection()->getPdo();
            // The configured store, not `redis` by name — see HealthController.
            // As a hardcoded store this command failed on every deployment that
            // does not run Redis, reporting the whole application unhealthy
            // because one driver it does not use was absent.
            cache()->store()->put('health_check', true, 5);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
