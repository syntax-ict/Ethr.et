<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Attendance\Device\DeviceChallenges;
use Illuminate\Console\Command;

/**
 * Deletes attendance passkey challenges an hour after they expired. A
 * challenge lives three minutes; keeping them only bloats the table.
 */
class PurgeDeviceChallengesCommand extends Command
{
    protected $signature = 'attendance:purge-device-challenges';

    protected $description = 'Delete expired attendance passkey challenges';

    public function handle(DeviceChallenges $challenges): int
    {
        $this->info('Deleted '.$challenges->purge().' expired challenges.');

        return self::SUCCESS;
    }
}
