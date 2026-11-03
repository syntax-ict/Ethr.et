<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * The way back from an off-host copy: decrypt it, unpack it into the backup
 * folder, and hand over to `ethr:restore`, which still checks the dump against
 * its manifest before touching anything.
 *
 *   php artisan ethr:backup:unpack backups/ethr-20261102-010004.tar.gz.ethrbak --from-disk
 *   php artisan ethr:backup:unpack /path/to/downloaded.ethrbak --key=base64:...
 *
 * `--key` is for a new host that does not have the old key yet: the one the
 * console's Backups page showed, or the old BACKUP_ENCRYPTION_KEY.
 */
class BackupUnpackCommand extends Command
{
    protected $signature = 'ethr:backup:unpack
        {archive : A local .ethrbak file, or with --from-disk its key on the off-host disk}
        {--from-disk : Fetch the archive from the off-host disk first}
        {--disk= : Which .env disk; defaults to the console storage, then config backup.off_host_disk}
        {--key= : The key the archive was made with, when it is not the configured one}';

    protected $description = 'Decrypt and unpack an off-host backup so ethr:restore can use it';

    public function handle(BackupService $backups): int
    {
        @set_time_limit(0);

        $cipher = null;

        if ($this->option('key') !== null) {
            $key = BackupCipher::decodeKey((string) $this->option('key'));

            if ($key === null) {
                $this->error('--key is not a 32-byte key in base64.');

                return self::FAILURE;
            }

            $cipher = new BackupCipher($key);
        }

        $disk = $this->option('disk') ? (string) $this->option('disk') : null;

        try {
            $name = $backups->unpack((string) $this->argument('archive'), $cipher, (bool) $this->option('from-disk'), $disk);
        } catch (Throwable $e) {
            $this->error('Unpack FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Unpacked as {$name}. Restore with: php artisan ethr:restore {$name}");

        return self::SUCCESS;
    }
}
