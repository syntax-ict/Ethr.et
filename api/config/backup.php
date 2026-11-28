<?php

declare(strict_types=1);

return [

    /*
     * Where backups are written.
     *
     * Must never be inside the document root. On the shared-hosting layout the
     * application lives at ~/ethr/api and httpdocs is a sibling, so the default
     * is already unreachable over HTTP — but point this somewhere off the
     * account entirely if the plan allows. A backup stored on the disk it is
     * protecting survives an application fault and nothing else.
     */
    'path' => env('BACKUP_PATH', storage_path('backups')),

    /*
     * How many backups to keep locally. Bronze is 5 GB in total, shared with
     * the database and every employee document, so this is a small number by
     * necessity rather than preference. Depth should come from off-host
     * retention, not from this.
     *
     * LOWERED 7 -> 2 on 2026-09-23. Three facts make 7 the wrong number here,
     * and none of them is obvious from the option name:
     *
     *   1. What is retained is NOT an archive. `BackupService::create()`
     *      writes an uncompressed directory — `database.sql` plus a full copy
     *      of every file in storage/app/private. `archive()` (tar.gz/zip) runs
     *      only in `copyOffHost()`, which the scheduled run does not call.
     *   2. `prune()` runs AFTER `create()` in BackupCommand, so the peak is
     *      keep + 1 directories on disk, not keep.
     *   3. So every employee document is stored keep + 2 times at peak — once
     *      live, once in each retained copy, once in the copy being made.
     *      At 7 that is nine copies of the document store; at 2 it is four.
     *
     * This value was also unreachable until the same date: BackupCommand's
     * signature carried its own `--keep=7` default and never consulted this
     * file, so setting BACKUP_KEEP did nothing at all.
     */
    'keep' => (int) env('BACKUP_KEEP', 2),

    /*
     * The hosting plan's disk quota in MB: Bronze is 5 GB, shared by the
     * database, the documents and these backups. DiskUsage measures against
     * it nightly; the operations centre turns amber at 70% and red at 85%,
     * and a backup that would pass 95% is refused rather than filling the
     * disk the database writes to.
     */
    'disk_quota_mb' => (int) env('DISK_QUOTA_MB', 5120),

    /*
     * What DiskUsage walks: the application, by default. A relative path is
     * relative to the application, which is how the test suite points it at
     * a small folder instead of vendor/.
     */
    'disk_root' => env('DISK_MEASURE_ROOT'),

    /*
     * Disk for off-host copies. Already wired through
     * league/flysystem-aws-s3-v3, so any S3-compatible endpoint works without a
     * new dependency.
     */
    'off_host_disk' => env('BACKUP_OFF_HOST_DISK', 's3'),

    /*
     * The operator's statement that the `.env` off-host disk above stores in
     * Ethiopia. Proclamation 1321/2024 Art. 22(1) requires it, and nothing in
     * an endpoint proves where a bucket is, so in production a copy goes to
     * that disk only while this is true (App\Support\BackupResidency). The
     * console's storage carries its own statement, `located_in_ethiopia`.
     */
    'off_host_in_ethiopia' => (bool) env('BACKUP_OFF_HOST_IN_ETHIOPIA', false),

    /*
     * How many archives to keep on the off-host disk. Depth lives there, not
     * on the 5 GB account: two weeks of nightly copies.
     */
    'off_host_keep' => (int) env('BACKUP_OFF_HOST_KEEP', 14),

    /*
     * The key every off-host archive is encrypted with (BackupCipher), as
     * `base64:` and 32 bytes; `php artisan ethr:backup:key` makes one. Without
     * it nothing is copied off the host: an unencrypted copy would hand every
     * salary and identity document to whoever holds the bucket. Keep a copy of
     * it away from the host too, or the archives cannot be opened when the
     * host is the thing that was lost.
     */
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    /*
     * Time limits, so a primary storage that has gone dark leaves the night
     * enough time for the fallback (the run is not bounded by the web SAPI's
     * limit, but a hung connection otherwise holds it for as long as the
     * socket lasts). `timeout` is per request, so it must outlast one
     * upload part: the S3 SDK sends a single PutObject up to 16 MB and 5 MB
     * parts above that, which at 120 s needs about 1.1 Mbit/s.
     */
    'storage_http' => [
        'connect_timeout' => (int) env('BACKUP_STORAGE_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('BACKUP_STORAGE_TIMEOUT', 120),
    ],

    /*
     * Google Drive, the fallback destination. `chunk_bytes` is the size of
     * each resumable-upload request; Google requires a multiple of 256 KiB,
     * and 8 MiB keeps memory flat on a 128 MB PHP limit. `timeout` covers
     * metadata calls, `chunk_timeout` one chunk (8 MiB in 120 s needs about
     * 0.6 Mbit/s).
     */
    'google_drive' => [
        'chunk_bytes' => 8 * 1024 * 1024,
        'connect_timeout' => 10,
        'timeout' => 60,
        'chunk_timeout' => 120,
    ],
];
