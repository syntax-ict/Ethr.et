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
     */
    'keep' => (int) env('BACKUP_KEEP', 7),

    /*
     * Disk for off-host copies. Already wired through
     * league/flysystem-aws-s3-v3, so any S3-compatible endpoint works without a
     * new dependency.
     */
    'off_host_disk' => env('BACKUP_OFF_HOST_DISK', 's3'),
];
