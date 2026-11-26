<?php

declare(strict_types=1);

use App\Models\BackupDriveSetting;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupDriveDisk;
use App\Services\Backup\BackupService;
use App\Support\BackupHealth;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Off-host copies with Google Drive as the fallback: the order destinations
 * are tried in, what is recorded about a copy that fell back, that each copy
 * is opened with the key of the place it went to, and that pruning, health
 * and a missing table all behave. Drive is a fake disk here; the Google calls
 * themselves are covered by BackupDriveClientTest.
 */
const FALLBACK_PRIMARY_KEY = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

beforeEach(function () {
    config([
        'backup.path' => storage_path('framework/testing/backups-fallback-'.uniqid()),
        'backup.encryption_key' => FALLBACK_PRIMARY_KEY,
        'backup.off_host_disk' => 's3',
        'backup.off_host_keep' => 14,
        'filesystems.disks.s3.bucket' => 'ethr-backups',
        'filesystems.disks.s3.key' => 'test-key',
        'filesystems.disks.s3.secret' => 'test-secret',
    ]);

    Storage::fake('s3');
    Storage::fake('drive-fake');

    $fake = new class extends BackupDriveDisk
    {
        public function build(BackupDriveSetting $settings): Filesystem
        {
            return Storage::disk('drive-fake');
        }
    };
    app()->instance(BackupDriveDisk::class, $fake);
});

afterEach(function () {
    File::deleteDirectory(config('backup.path'));
    app()->detectEnvironment(fn () => 'testing');
});

function fallbackBackup(string $name, ?string $createdAt = null): string
{
    $dir = config('backup.path').DIRECTORY_SEPARATOR.$name;
    File::ensureDirectoryExists($dir.DIRECTORY_SEPARATOR.'private');
    File::put($dir.DIRECTORY_SEPARATOR.'database.sql', "CREATE TABLE t (id INTEGER);\n");
    File::put($dir.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'doc.pdf', random_bytes(2048));
    File::put($dir.DIRECTORY_SEPARATOR.'manifest.json', json_encode([
        'name' => $name,
        'created_at' => $createdAt ?? now()->toIso8601String(),
        'sql_sha256' => hash_file('sha256', $dir.DIRECTORY_SEPARATOR.'database.sql'),
    ]));

    return $dir;
}

function connectedDrive(array $overrides = []): BackupDriveSetting
{
    $setting = new BackupDriveSetting(['client_id' => 'client.apps.googleusercontent.com', 'client_secret' => 'secret']);
    $setting->forceFill(array_merge([
        'encryption_key' => BackupCipher::generateKey(),
        'refresh_token' => 'refresh-token',
        'folder_id' => 'folder-1',
        'enabled' => true,
        'verified_at' => now(),
        'key_saved_at' => now(),
        'connected_at' => now(),
    ], $overrides))->save();

    return $setting;
}

/** A primary that refuses every upload, as a dead storage does. */
function failingPrimary(): void
{
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('writeStream')->andReturn(false);
    $disk->shouldReceive('exists')->andReturn(false);
    $disk->shouldReceive('files')->andReturn([]);
    Storage::set('s3', $disk);
}

function manifestOf(string $dir): array
{
    return json_decode(File::get($dir.'/manifest.json'), true);
}

test('with the primary working, Drive is not touched', function () {
    connectedDrive();
    $dir = fallbackBackup('ethr-20261115-010000');

    $copy = app(BackupService::class)->copyOffHost($dir);

    expect($copy['destination'])->toBe('s3')->and($copy['fell_back'])->toBeFalse();
    Storage::disk('s3')->assertExists($copy['key']);
    expect(Storage::disk('drive-fake')->allFiles())->toBe([])
        ->and(manifestOf($dir)['off_host'])->toMatchArray(['destination' => 's3', 'fell_back' => false]);
});

test('when the primary fails, the copy lands on Drive and says it fell back', function () {
    $drive = connectedDrive();
    failingPrimary();
    $dir = fallbackBackup('ethr-20261115-010000');

    $copy = app(BackupService::class)->copyOffHost($dir);

    expect($copy)->toMatchArray(['destination' => 'google_drive', 'disk' => 'Google Drive', 'fell_back' => true]);
    Storage::disk('drive-fake')->assertExists($copy['key']);
    expect(manifestOf($dir)['off_host'])->toMatchArray(['destination' => 'google_drive', 'fell_back' => true])
        // Sealed with Drive's own key, not the primary's.
        ->and(Storage::disk('drive-fake')->get($copy['key']))->toStartWith(BackupCipher::MAGIC)
        ->and(File::files(config('backup.path')))->toBe([]);
});

test('when the primary is not configured at all, Drive takes the copy and it is not a fall back', function () {
    connectedDrive();
    config(['filesystems.disks.s3.bucket' => null]);
    $dir = fallbackBackup('ethr-20261115-010000');

    $copy = app(BackupService::class)->copyOffHost($dir);

    expect($copy)->toMatchArray(['destination' => 'google_drive', 'fell_back' => false]);
});

test('a Drive that is saved but switched off is never used', function () {
    connectedDrive(['enabled' => false]);
    failingPrimary();

    expect(fn () => app(BackupService::class)->copyOffHost(fallbackBackup('ethr-20261115-010000')))
        ->toThrow(RuntimeException::class, 'did not arrive whole');
    expect(Storage::disk('drive-fake')->allFiles())->toBe([]);
});

test('when both fail the run fails and the message names both', function () {
    connectedDrive();
    failingPrimary();

    $failing = Mockery::mock(Filesystem::class);
    $failing->shouldReceive('writeStream')->andThrow(new RuntimeException('Drive says: quota exceeded'));
    $fake = new class($failing) extends BackupDriveDisk
    {
        public function __construct(private readonly Filesystem $disk) {}

        public function build(BackupDriveSetting $settings): Filesystem
        {
            return $this->disk;
        }
    };
    app()->instance(BackupDriveDisk::class, $fake);

    try {
        app(BackupService::class)->copyOffHost(fallbackBackup('ethr-20261115-010000'));
        $this->fail('Expected the copy to fail.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('every destination')
            ->and($e->getMessage())->toContain('s3: ')
            ->and($e->getMessage())->toContain('did not arrive whole')
            ->and($e->getMessage())->toContain('Google Drive')
            ->and($e->getMessage())->toContain('quota exceeded');
    }

    expect(File::files(config('backup.path')))->toBe([]);
});

test('the nightly run succeeds on the fallback, says so, and reports a warning not an alarm', function () {
    connectedDrive();
    failingPrimary();

    $this->artisan('ethr:backup')
        ->expectsOutputToContain('went to Google Drive instead')
        ->assertSuccessful();

    $latest = app(BackupService::class)->latest();
    expect($latest['off_host'])->toMatchArray(['destination' => 'google_drive', 'fell_back' => true]);

    $snapshot = app(BackupHealth::class)->snapshot();
    expect($snapshot['reasons'])->toContain('off_host_fell_back')
        ->and($snapshot['reasons'])->not->toContain('off_host_failing')
        ->and($snapshot['state'])->toBe('warning')
        ->and($snapshot['off_host']['destination'])->toBe('google_drive')
        ->and($snapshot['off_host']['configured'])->toBeTrue();
});

test('a copy that went to the primary reports its destination and no warning', function () {
    connectedDrive();
    app(BackupService::class)->copyOffHost(fallbackBackup('ethr-20261115-010000'));

    $snapshot = app(BackupHealth::class)->snapshot();

    expect($snapshot['state'])->toBe('ok')
        ->and($snapshot['off_host']['destination'])->toBe('s3')
        ->and($snapshot['reasons'])->toBe([]);
});

test('with only Drive usable, health is configured and not blocked', function () {
    connectedDrive();
    config(['filesystems.disks.s3.bucket' => null]);

    $snapshot = app(BackupHealth::class)->snapshot();

    expect($snapshot['off_host']['configured'])->toBeTrue()
        ->and($snapshot['off_host']['disk'])->toBe('Google Drive');
});

test('pruning keeps the newest on every destination and one failing does not stop the other', function () {
    connectedDrive();

    foreach (['20261110', '20261111', '20261112', '20261113'] as $day) {
        Storage::disk('s3')->put("backups/ethr-{$day}-010000.tar.gz.ethrbak", 'x');
        Storage::disk('drive-fake')->put("backups/ethr-{$day}-010000.tar.gz.ethrbak", 'x');
    }
    Storage::disk('drive-fake')->put('backups/not-ours.txt', 'keep me');

    app(BackupService::class)->pruneOffHost(2);

    foreach (['s3', 'drive-fake'] as $disk) {
        expect(Storage::disk($disk)->allFiles())->toContain('backups/ethr-20261113-010000.tar.gz.ethrbak', 'backups/ethr-20261112-010000.tar.gz.ethrbak')
            ->and(Storage::disk($disk)->allFiles())->not->toContain('backups/ethr-20261110-010000.tar.gz.ethrbak');
    }
    Storage::disk('drive-fake')->assertExists('backups/not-ours.txt');

    // The primary now throws on listing; Drive must still be pruned.
    $broken = Mockery::mock(Filesystem::class);
    $broken->shouldReceive('files')->andThrow(new RuntimeException('primary unreachable'));
    Storage::set('s3', $broken);
    Storage::disk('drive-fake')->put('backups/ethr-20261114-010000.tar.gz.ethrbak', 'x');

    $removed = app(BackupService::class)->pruneOffHost(2);

    expect($removed)->toBe(['backups/ethr-20261112-010000.tar.gz.ethrbak']);
});

test('a copy on Drive is unpacked with Drive\'s key, and only when Drive is named', function () {
    connectedDrive();
    failingPrimary();
    $dir = fallbackBackup('ethr-20261115-010000');
    $sql = File::get($dir.'/database.sql');
    $copy = app(BackupService::class)->copyOffHost($dir);
    File::deleteDirectory($dir);

    // The primary is healthy again and has no such file.
    Storage::fake('s3');

    $this->artisan('ethr:backup:unpack', ['archive' => $copy['key'], '--from-disk' => true])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();

    $this->artisan('ethr:backup:unpack', ['archive' => $copy['key'], '--from-disk' => true, '--destination' => 'google_drive'])
        ->expectsOutputToContain('Unpacked as ethr-20261115-010000')
        ->assertSuccessful();

    expect(File::get($dir.'/database.sql'))->toBe($sql);

    // The primary's key does not open it.
    File::deleteDirectory($dir);
    $this->artisan('ethr:backup:unpack', ['archive' => $copy['key'], '--from-disk' => true, '--destination' => 'google_drive', '--key' => FALLBACK_PRIMARY_KEY])
        ->expectsOutputToContain('does not decrypt')
        ->assertFailed();
});

test('unpack refuses a destination it does not know', function () {
    $this->artisan('ethr:backup:unpack', ['archive' => 'x', '--destination' => 'dropbox'])
        ->expectsOutputToContain('--destination must be')
        ->assertFailed();

    $this->artisan('ethr:backup:unpack', ['archive' => 'backups/x.ethrbak', '--from-disk' => true, '--destination' => 'google_drive'])
        ->expectsOutputToContain('No off-host destination called google_drive')
        ->assertFailed();
});

test('before the migration the backup, the targets and the health snapshot carry on', function () {
    connectedDrive();
    Schema::drop('backup_drive_settings');

    $service = app(BackupService::class);

    expect($service->offHostTargets())->toHaveCount(1)
        ->and($service->offHostBlocker())->toBeNull();

    $copy = $service->copyOffHost(fallbackBackup('ethr-20261115-010000'));

    expect($copy['destination'])->toBe('s3')
        ->and(app(BackupHealth::class)->snapshot()['state'])->toBe('ok');

    $this->artisan('ethr:backup')->assertSuccessful();
});

test('--disk names the .env disk alone and never uses Drive', function () {
    connectedDrive();
    config(['filesystems.disks.s3.bucket' => null]);

    expect(app(BackupService::class)->offHostTargets('s3'))->toHaveCount(1)
        ->and(app(BackupService::class)->offHostBlocker('s3'))->toBe('off_host_not_configured');
});
