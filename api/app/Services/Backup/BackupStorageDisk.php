<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\BackupStorageSetting;
use App\Support\OutboundHost;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Builds the S3-compatible disk the console settings describe, and proves it
 * works. Its own class so the tests can bind a fake in its place: a real S3
 * client cannot be pointed at a fake.
 */
class BackupStorageDisk
{
    /**
     * `throw` is off for the nightly copy, which checks the size that arrived
     * instead, and on for the test, so its message is the storage's own.
     *
     * The host is checked again here, not only when it was saved, because
     * what a name resolves to can change after that.
     */
    public function build(BackupStorageSetting $settings, bool $throw = false): Filesystem
    {
        if (OutboundHost::isInternal($settings->connectsTo())) {
            throw new RuntimeException('The storage address points to an internal address.');
        }

        return Storage::build([
            'driver' => 's3',
            'key' => $settings->access_key_id,
            'secret' => $settings->secret_access_key,
            'region' => $settings->region,
            'bucket' => $settings->bucket,
            'endpoint' => $settings->endpoint,
            'use_path_style_endpoint' => $settings->path_style,
            'throw' => $throw,
        ]);
    }

    /**
     * Write a small file under `backups/`, read it back, delete it: the three
     * things a nightly copy and its pruning need. Null when all three work,
     * otherwise what went wrong.
     */
    public function probe(BackupStorageSetting $settings): ?string
    {
        $key = 'backups/.ethr-probe-'.bin2hex(random_bytes(6));
        $body = bin2hex(random_bytes(16));

        try {
            $disk = $this->build($settings, throw: true);

            if ($disk->put($key, $body) === false) {
                throw new RuntimeException('The storage refused the test file. Check the bucket name and that the key may write to it.');
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
