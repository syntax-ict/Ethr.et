<?php

declare(strict_types=1);

use App\Services\Backup\BackupService;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Backup retention has to be settable per environment, and until 2026-09-23 it
 * was not.
 *
 * `config/backup.php` defined `keep` and read `BACKUP_KEEP`, and **nothing
 * consulted either**: `BackupCommand`'s signature carried its own `--keep=7`
 * default, which is never null, so the option always won. `routes/console.php`
 * then passed `--keep=7` on top of that. An operator lowering retention on a
 * 5 GB plan — by editing the config, or by setting the env var in the Plesk
 * panel — got seven backups anyway, with no error and nothing in a log.
 *
 * That matters more here than the word "retention" suggests. What is retained is
 * an **uncompressed** directory: `database.sql` plus a full copy of every file
 * in `storage/app/private`. `archive()` runs only in `copyOffHost()`, which the
 * scheduled run does not call. And `prune()` runs *after* `create()`, so the
 * peak is `keep + 1` directories — every employee document stored `keep + 2`
 * times. Nine copies at 7; four at 2.
 *
 * These tests pin the two halves that were broken, and they fail against the
 * old code for different reasons: the first because the signature default
 * shadowed the config, the second because the schedule passed the option.
 */
function backupResult(): array
{
    return [
        'name' => 'ethr-20260923-010000',
        'path' => '/tmp/ethr-20260923-010000',
        'manifest' => [
            'database' => ['tables' => 80, 'rows' => 1000, 'triggers' => 3, 'bytes' => 1024],
            'files' => ['count' => 0, 'bytes' => 0],
        ],
    ];
}

test('retention comes from config when --keep is omitted', function () {
    config(['backup.keep' => 3]);

    $service = Mockery::mock(BackupService::class);
    $service->shouldReceive('create')->once()->andReturn(backupResult());
    // The assertion. Against the old signature default this received 7.
    $service->shouldReceive('prune')->once()->with(3)->andReturn([]);

    app()->instance(BackupService::class, $service);

    $this->artisan('ethr:backup')->assertSuccessful();
});

test('an explicit --keep still overrides the config', function () {
    config(['backup.keep' => 3]);

    $service = Mockery::mock(BackupService::class);
    $service->shouldReceive('create')->once()->andReturn(backupResult());
    $service->shouldReceive('prune')->once()->with(1)->andReturn([]);

    app()->instance(BackupService::class, $service);

    // A one-off run must still be able to say what it means; making config win
    // unconditionally would trade one silent override for another.
    $this->artisan('ethr:backup --keep=1')->assertSuccessful();
});

test('the scheduled backup passes no --keep, so BACKUP_KEEP governs', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => $e->description === 'ethr-backup');

    expect($events)->toHaveCount(1);

    // `--keep` on this line would override the env var on every host and
    // re-create the defect: retention would again be unsettable per
    // environment, which on a fixed quota is the difference between four
    // copies of the document store and nine.
    expect($events->first()->command)->not->toContain('--keep');
});

test('the shipped default retention is 2', function () {
    // Pinned so raising it is a deliberate act rather than a tidy-up, which is
    // the same reason the tenant-scope inventory is pinned. The cost of being
    // wrong here is silent: a full quota fails every write under storage/ while
    // reads keep working, so it presents as data corruption rather than as a
    // full disk. `config/backup.php` carries the arithmetic.
    //
    // Raising it for a host with room is legitimate — do it with BACKUP_KEEP,
    // which the tests above prove now reaches the command, not by editing the
    // shipped default that shared hosting falls back to.
    expect((int) config('backup.keep'))->toBe(2);
});
