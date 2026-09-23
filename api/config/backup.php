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
     * Disk for off-host copies. Already wired through
     * league/flysystem-aws-s3-v3, so any S3-compatible endpoint works without a
     * new dependency.
     */
    'off_host_disk' => env('BACKUP_OFF_HOST_DISK', 's3'),
];
