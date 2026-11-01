<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\BackupCipher;
use Illuminate\Console\Command;

/**
 * Prints a fresh BACKUP_ENCRYPTION_KEY. It writes nothing: the key belongs in
 * the host's .env AND somewhere off the host (a password manager), because an
 * archive whose only key was on the lost host cannot be opened. Changing the
 * key later leaves the older archives readable only with the older key.
 */
class BackupKeyCommand extends Command
{
    protected $signature = 'ethr:backup:key';

    protected $description = 'Print a new key for encrypting off-host backups';

    public function handle(): int
    {
        $this->line(BackupCipher::generateKey());
        $this->newLine();
        $this->line('Set it as BACKUP_ENCRYPTION_KEY in the host .env, then run `optimize`.');
        $this->line('Keep a copy away from the host: without it, no off-host backup can be opened.');

        return self::SUCCESS;
    }
}
