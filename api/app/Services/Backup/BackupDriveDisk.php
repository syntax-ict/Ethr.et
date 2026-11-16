<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\BackupDriveSetting;
use App\Services\Backup\GoogleDrive\GoogleDriveAdapter;
use App\Services\Backup\GoogleDrive\GoogleDriveClient;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem as Flysystem;
use RuntimeException;
use Throwable;

/**
 * Builds the Google Drive disk the console settings describe, and proves it
 * works. Its own class so the tests can bind a fake in its place, as they do
 * for BackupStorageDisk.
 *
 * `throw` is on: Google's own message is what the console and the nightly
 * report show, and the S3 habit of swallowing it into `false` would leave
 * only "did not arrive whole".
 */
class BackupDriveDisk
{
    public function client(BackupDriveSetting $settings): GoogleDriveClient
    {
        if (! $settings->isConnected()) {
            throw new RuntimeException('Google Drive is not connected.');
        }

        return new GoogleDriveClient(
            (string) $settings->client_id,
            (string) $settings->client_secret,
            (string) $settings->refresh_token,
            (string) $settings->folder_id,
        );
    }

    public function build(BackupDriveSetting $settings): Filesystem
    {
        $adapter = new GoogleDriveAdapter($this->client($settings));

        return new FilesystemAdapter(new Flysystem($adapter, ['throw' => true]), $adapter, ['throw' => true]);
    }

    /**
     * Write a small file under `backups/`, read it back, delete it: the three
     * things a nightly copy and its pruning need. Null when all three work,
     * otherwise what went wrong.
     */
    public function probe(BackupDriveSetting $settings): ?string
    {
        $key = 'backups/.ethr-probe-'.bin2hex(random_bytes(6));
        $body = bin2hex(random_bytes(16));

        try {
            $disk = $this->build($settings);

            if ($disk->put($key, $body) === false) {
                throw new RuntimeException('Google Drive refused the test file.');
            }

            if ($disk->get($key) !== $body) {
                throw new RuntimeException('The test file was written but did not read back the same.');
            }

            if ($disk->delete($key) === false) {
                throw new RuntimeException('The test file was written but could not be deleted, so old copies could not be pruned either.');
            }

            return null;
        } catch (Throwable $e) {
            return mb_substr($e->getMessage(), 0, 500);
        }
    }
}
