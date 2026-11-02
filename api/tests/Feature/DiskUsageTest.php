<?php

declare(strict_types=1);

use App\Services\Backup\BackupService;
use App\Support\BackupHealth;
use App\Support\DiskUsage;
use Illuminate\Support\Facades\File;

/**
 * Disk use on the plan's quota: what is counted under which part, when the
 * operations centre turns amber and red, and that the nightly backup makes
 * room or stops rather than filling the disk the database writes to.
 */
function diskSandbox(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ethr-disk-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($root.'/storage/app/private/employees');
    File::ensureDirectoryExists($root.'/storage/logs');
    File::ensureDirectoryExists($root.'/storage/backups/ethr-20261101-010000');

    File::put($root.'/storage/app/private/employees/id.pdf', str_repeat('d', 1000));
    File::put($root.'/storage/logs/laravel.log', str_repeat('l', 200));
    File::put($root.'/storage/backups/ethr-20261101-010000/database.sql', str_repeat('b', 3000));
    File::put($root.'/composer.json', str_repeat('o', 50));

    test()->originalStoragePath = app()->storagePath();
    app()->useStoragePath($root.'/storage');
    config(['backup.disk_root' => $root, 'backup.path' => $root.'/storage/backups']);

    return $root;
}

afterEach(function () {
    if (isset($this->originalStoragePath)) {
        app()->useStoragePath($this->originalStoragePath);
    }
});

/** @return array<string, mixed> */
function diskUsageAt(float $ratio): array
{
    $quota = 5120 * 1024 * 1024;

    return [
        'measured_at' => now()->toIso8601String(),
        'quota_bytes' => $quota,
        'total_bytes' => (int) ($quota * $ratio),
        'database_bytes' => 100,
        'documents_bytes' => 100,
        'backups_bytes' => 0,
        'logs_bytes' => 0,
        'other_bytes' => 0,
    ];
}

it('counts each file once, under the part it sits in', function () {
    $root = diskSandbox();

    $usage = (new DiskUsage)->measure();

    expect($usage)->toMatchArray([
        'documents_bytes' => 1000,
        'logs_bytes' => 200,
        'backups_bytes' => 3000,
        'other_bytes' => 50,
        'total_bytes' => 4250,
        'quota_bytes' => 5120 * 1024 * 1024,
    ]);

    File::deleteDirectory($root);
});

it('walks backups kept outside the application on their own', function () {
    $root = diskSandbox();
    $outside = $root.'-backups';
    File::ensureDirectoryExists($outside);
    File::put($outside.'/database.sql', str_repeat('b', 700));
    config(['backup.path' => $outside, 'backup.disk_root' => $root.'/storage/app']);

    $usage = (new DiskUsage)->measure();

    expect($usage['backups_bytes'])->toBe(700)
        ->and($usage['documents_bytes'])->toBe(1000)
        ->and($usage['logs_bytes'])->toBe(200);

    File::deleteDirectory($root);
    File::deleteDirectory($outside);
});

it('turns amber at 70% of the quota and red at 85%', function (float $ratio, ?string $reason) {
    expect(DiskUsage::reason(diskUsageAt($ratio)))->toBe($reason);
})->with([
    [0.5, null],
    [0.7, 'disk_high'],
    [0.84, 'disk_high'],
    [0.85, 'disk_critical'],
]);

it('reports disk use in the backups area, which reaches the outside monitor', function () {
    DiskUsage::record(diskUsageAt(0.9));

    $snapshot = app(BackupHealth::class)->snapshot();

    expect($snapshot['state'])->toBe(BackupHealth::CRITICAL)
        ->and($snapshot['reasons'])->toContain('disk_critical')
        ->and($snapshot['disk']['total_bytes'])->toBe((int) (5120 * 1024 * 1024 * 0.9));

    DiskUsage::record(diskUsageAt(0.75));
    expect(app(BackupHealth::class)->snapshot()['reasons'])->toContain('disk_high');
});

it('says nothing about disk before the first measurement', function () {
    $snapshot = app(BackupHealth::class)->snapshot();

    expect($snapshot['disk'])->toBeNull()
        ->and($snapshot['reasons'])->not->toContain('disk_high')
        ->and($snapshot['reasons'])->not->toContain('disk_critical');
});

it('stops the backup rather than fill the disk, after pruning down to one', function () {
    $root = diskSandbox();
    File::put($root.'/storage/app/private/employees/scan.pdf', str_repeat('d', 600 * 1024));
    config(['backup.disk_quota_mb' => 1, 'backup.keep' => 2]);

    $service = Mockery::mock(BackupService::class);
    $service->shouldReceive('prune')->once()->with(1)->andReturn([]);
    $service->shouldNotReceive('create');
    app()->instance(BackupService::class, $service);

    $this->artisan('ethr:backup')
        ->expectsOutputToContain('Not enough disk for another backup')
        ->assertFailed();

    app()->forgetInstance(BackupService::class);
    expect(app(BackupHealth::class)->snapshot()['reasons'])->toContain('backup_failing');

    File::deleteDirectory($root);
});

it('goes ahead once pruning the oldest backup has made room', function () {
    $root = diskSandbox();
    // 300 KB of documents and a 700 KB old backup: another 300 KB copy does
    // not fit under 95% of 1 MB until the old backup goes.
    File::put($root.'/storage/app/private/employees/scan.pdf', str_repeat('d', 300 * 1024));
    File::put($root.'/storage/backups/ethr-20261101-010000/private.bin', str_repeat('b', 700 * 1024));
    config(['backup.disk_quota_mb' => 1, 'backup.keep' => 2]);

    $service = Mockery::mock(BackupService::class);
    $service->shouldReceive('prune')->once()->with(1)->andReturnUsing(function () use ($root) {
        File::deleteDirectory($root.'/storage/backups/ethr-20261101-010000');

        return ['ethr-20261101-010000'];
    });
    $service->shouldReceive('create')->once()->andReturn([
        'name' => 'ethr-20261102-010000',
        'path' => $root.'/storage/backups/ethr-20261102-010000',
        'manifest' => [
            'database' => ['tables' => 1, 'rows' => 1, 'triggers' => 0, 'bytes' => 10],
            'files' => ['count' => 1, 'bytes' => 1000],
        ],
    ]);
    $service->shouldReceive('offHostBlocker')->andReturn('off_host_not_configured');
    $service->shouldReceive('prune')->with(2)->andReturn([]);
    app()->instance(BackupService::class, $service);

    $this->artisan('ethr:backup')
        ->expectsOutputToContain('early, to make room')
        ->assertSuccessful();

    expect(DiskUsage::last())->not->toBeNull();

    File::deleteDirectory($root);
});
