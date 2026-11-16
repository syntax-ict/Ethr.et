<?php

declare(strict_types=1);

use App\Models\BackupStorageSetting;
use App\Services\Backup\BackupStorageDisk;
use App\Support\OutboundHost;
use Illuminate\Filesystem\AwsS3V3Adapter;

/**
 * Non-AWS S3 storage (Huawei OBS behind Ethio Telecom Telecloud) refuses the
 * SDK's default trailing checksums, so the client must ask for them only when
 * an operation requires one. Building the client makes no network call.
 */
it('builds an S3 client that sends and checks checksums only when required', function () {
    OutboundHost::resolveUsing(fn () => ['93.184.216.34']);

    $settings = new BackupStorageSetting([
        'endpoint' => 'https://obsv3.example.com',
        'region' => 'af-east-1',
        'bucket' => 'ethr-backups',
        'access_key_id' => 'test-key',
        'secret_access_key' => 'test-secret',
        'path_style' => true,
    ]);

    $disk = (new BackupStorageDisk)->build($settings);

    expect($disk)->toBeInstanceOf(AwsS3V3Adapter::class);

    $client = $disk->getClient();
    expect($client->getConfig('request_checksum_calculation'))->toBe('when_required')
        ->and($client->getConfig('response_checksum_validation'))->toBe('when_required');

    OutboundHost::resolveUsing(null);
});
