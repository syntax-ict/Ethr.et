<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Auth\ActivationCodeService;
use Illuminate\Console\Command;

/**
 * Deletes in-person activation codes thirty days after they expired. A spent,
 * withdrawn or lapsed code is useless; its audit trail lives in the audit log.
 */
class PurgeActivationCodesCommand extends Command
{
    protected $signature = 'auth:purge-activation-codes';

    protected $description = 'Delete expired in-person activation codes';

    public function handle(ActivationCodeService $codes): int
    {
        $this->info('Deleted '.$codes->purge().' expired activation codes.');

        return self::SUCCESS;
    }
}
