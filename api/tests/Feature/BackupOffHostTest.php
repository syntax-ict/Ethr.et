<?php

declare(strict_types=1);

use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupService;
use App\Support\BackupHealth;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * The off-host copy and the alarm for backups that stop.
 *
 * Until 2026-11-01 every backup lived only on the 5 GB account it protects:
 * the nightly run never passed --off-host, the copy path loaded the whole
 * archive into memory, ignored a failed upload (the s3 disk is
 * `throw => false`) and left the archive beside the backups forever, and
 * nothing reported a backup that stopped or failed.
 */
const BACKUP_TEST_KEY = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

beforeEach(function () {
    config([
        'backup.path' => storage_path('framework/testing/backups-'.uniqid()),
        'backup.encryption_key' => BACKUP_TEST_KEY,
        'backup.off_host_disk' => 's3',
        'backup.off_host_keep' => 14,
        'filesystems.disks.s3.bucket' => 'ethr-backups',
        'filesystems.disks.s3.key' => 'test-key',
        'filesystems.disks.s3.secret' => 'test-secret',
    ]);

    Storage::fake('s3');
});

afterEach(function () {
    File::deleteDirectory(config('backup.path'));
    app()->detectEnvironment(fn () => 'testing');
});

/** A backup directory as create() leaves it, without dumping a database. */
function fakeBackup(string $name, ?string $createdAt = null): string
{
    $dir = config('backup.path').DIRECTORY_SEPARATOR.$name;
    File::ensureDirectoryExists($dir.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'documents');
    File::put($dir.DIRECTORY_SEPARATOR.'database.sql', "CREATE TABLE t (id INTEGER);\n");
    File::put($dir.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.'id.pdf', random_bytes(4096));
    File::put($dir.DIRECTORY_SEPARATOR.'manifest.json', json_encode([
        'name' => $name,
        'created_at' => $createdAt ?? now()->toIso8601String(),
        'sql_sha256' => hash_file('sha256', $dir.DIRECTORY_SEPARATOR.'database.sql'),
    ]));

    return $dir;
}

// ── The cipher ───────────────────────────────────────────────────────────────

test('an archive decrypts to exactly what was encrypted, at chunk boundaries too', function (int $bytes) {
    $dir = config('backup.path');
    File::ensureDirectoryExists($dir);
    $plain = $bytes === 0 ? '' : random_bytes($bytes);
    File::put("{$dir}/plain", $plain);

    $cipher = BackupCipher::fromConfig();
    $cipher->encryptFile("{$dir}/plain", "{$dir}/sealed");
    $cipher->decryptFile("{$dir}/sealed", "{$dir}/opened");

    expect($bytes === 0 || ! str_contains(File::get("{$dir}/sealed"), substr($plain, 0, 64)))->toBeTrue()
        ->and(File::get("{$dir}/opened"))->toBe($plain);
})->with([0, 17, 1048576, 1048576 * 2 + 5]);

test('a wrong key or a file cut short refuses to decrypt, and leaves nothing behind', function () {
    $dir = config('backup.path');
    File::ensureDirectoryExists($dir);
    File::put("{$dir}/plain", random_bytes(1048576 + 100));
    BackupCipher::fromConfig()->encryptFile("{$dir}/plain", "{$dir}/sealed");

    $other = new BackupCipher(base64_decode(substr(BackupCipher::generateKey(), 7)));
    expect(fn () => $other->decryptFile("{$dir}/sealed", "{$dir}/opened"))->toThrow(RuntimeException::class, 'does not decrypt');
    expect(File::exists("{$dir}/opened"))->toBeFalse();

    // Drop the final chunk: every chunk left still authenticates, and only the
    // missing FINAL tag shows the file is incomplete.
    $sealed = File::get("{$dir}/sealed");
    $firstChunk = 8 + 24 + 4 + unpack('N', substr($sealed, 32, 4))[1];
    File::put("{$dir}/cut", substr($sealed, 0, $firstChunk));

    expect(fn () => BackupCipher::fromConfig()->decryptFile("{$dir}/cut", "{$dir}/opened"))->toThrow(RuntimeException::class, 'cut short');
    expect(File::exists("{$dir}/opened"))->toBeFalse();
});

test('a key that is not 32 bytes of base64 is no key', function () {
    expect(BackupCipher::decodeKey(''))->toBeNull()
        ->and(BackupCipher::decodeKey('base64:'.base64_encode('short')))->toBeNull()
        ->and(strlen((string) BackupCipher::decodeKey(BACKUP_TEST_KEY)))->toBe(32)
        ->and(strlen((string) BackupCipher::decodeKey(BackupCipher::generateKey())))->toBe(32);
});

// ── The copy ─────────────────────────────────────────────────────────────────

test('nothing leaves the host without storage credentials and a key', function () {
    $service = app(BackupService::class);
    expect($service->offHostBlocker())->toBeNull();

    config(['backup.encryption_key' => null]);
    expect($service->offHostBlocker())->toBe('off_host_key_missing');

    config(['backup.encryption_key' => BACKUP_TEST_KEY, 'filesystems.disks.s3.bucket' => null]);
    expect($service->offHostBlocker())->toBe('off_host_not_configured');

    expect($service->offHostBlocker('no-such-disk'))->toBe('off_host_disk_missing');
    expect(fn () => $service->copyOffHost(fakeBackup('ethr-20261101-010000')))->toThrow(RuntimeException::class, 'off_host_not_configured');
});

test('the copy is encrypted, recorded in the manifest, and leaves no archive on the host', function () {
    $dir = fakeBackup('ethr-20261101-010000');

    $copy = app(BackupService::class)->copyOffHost($dir);

    expect($copy['key'])->toBe('backups/ethr-20261101-010000.tar.gz.ethrbak');
    Storage::disk('s3')->assertExists($copy['key']);
    expect(Storage::disk('s3')->get($copy['key']))->toStartWith(BackupCipher::MAGIC)
        ->and(Storage::disk('s3')->size($copy['key']))->toBe($copy['bytes']);

    $manifest = json_decode(File::get($dir.'/manifest.json'), true);
    expect($manifest['off_host']['key'])->toBe($copy['key'])
        ->and($manifest['sql_sha256'])->toBe(hash_file('sha256', $dir.'/database.sql'));

    // Only the backup directory itself: no .tar.gz or .ethrbak beside it.
    expect(File::files(config('backup.path')))->toBe([]);
});

test('an upload that does not arrive whole fails loudly instead of passing', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('writeStream')->andReturn(false);
    $disk->shouldReceive('exists')->andReturn(false);
    Storage::set('s3', $disk);

    expect(fn () => app(BackupService::class)->copyOffHost(fakeBackup('ethr-20261101-010000')))
        ->toThrow(RuntimeException::class, 'did not arrive whole');
    expect(File::files(config('backup.path')))->toBe([]);
});

test('an off-host copy unpacks into a backup ethr:restore can read', function () {
    $dir = fakeBackup('ethr-20261101-010000');
    $sql = File::get($dir.'/database.sql');
    $document = File::get($dir.'/private/documents/id.pdf');
    $copy = app(BackupService::class)->copyOffHost($dir);

    // As on a new host: the original directory is gone.
    File::deleteDirectory($dir);

    $this->artisan('ethr:backup:unpack', ['archive' => $copy['key'], '--from-disk' => true])
        ->expectsOutputToContain('Unpacked as ethr-20261101-010000')
        ->assertSuccessful();

    expect(File::get($dir.'/database.sql'))->toBe($sql)
        ->and(File::get($dir.'/private/documents/id.pdf'))->toBe($document)
        ->and(File::files(config('backup.path')))->toBe([]);
});

test('unpacking refuses the wrong key and an existing backup of the same name', function () {
    $dir = fakeBackup('ethr-20261101-010000');
    $copy = app(BackupService::class)->copyOffHost($dir);

    $this->artisan('ethr:backup:unpack', ['archive' => $copy['key'], '--from-disk' => true])
        ->expectsOutputToContain('already on this host')
        ->assertFailed();

    File::deleteDirectory($dir);

    $this->artisan('ethr:backup:unpack', ['archive' => $copy['key'], '--from-disk' => true, '--key' => BackupCipher::generateKey()])
        ->expectsOutputToContain('does not decrypt')
        ->assertFailed();
    expect(File::isDirectory($dir))->toBeFalse();
});

test('off-host retention keeps the newest archives and touches nothing else in the bucket', function () {
    $s3 = Storage::disk('s3');

    foreach (['20261020', '20261021', '20261022', '20261023'] as $day) {
        $s3->put("backups/ethr-{$day}-010000.tar.gz.ethrbak", 'x');
    }
    $s3->put('backups/someone-elses-file.txt', 'keep me');

    $removed = app(BackupService::class)->pruneOffHost(2);

    expect($removed)->toBe(['backups/ethr-20261021-010000.tar.gz.ethrbak', 'backups/ethr-20261020-010000.tar.gz.ethrbak']);
    $s3->assertExists(['backups/ethr-20261023-010000.tar.gz.ethrbak', 'backups/ethr-20261022-010000.tar.gz.ethrbak', 'backups/someone-elses-file.txt']);
});

// ── The nightly command ──────────────────────────────────────────────────────

test('the nightly backup copies off the host whenever storage and a key are set', function () {
    $this->artisan('ethr:backup')
        ->expectsOutputToContain('encrypted')
        ->assertSuccessful();

    $latest = app(BackupService::class)->latest();
    expect($latest['off_host']['key'])->toStartWith('backups/'.$latest['name']);
    Storage::disk('s3')->assertExists($latest['off_host']['key']);
});

test('without storage the backup still runs and says it stayed on the host', function () {
    config(['filesystems.disks.s3.bucket' => null]);

    $this->artisan('ethr:backup')
        ->expectsOutputToContain('NOT COPIED (off_host_not_configured)')
        ->assertSuccessful();

    $this->artisan('ethr:backup --off-host')->assertFailed();
});

test('a failed copy fails the run, is reported, and does not stop the local prune', function () {
    fakeBackup('ethr-20261020-010000');
    fakeBackup('ethr-20261021-010000');
    config(['backup.keep' => 2]);

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('writeStream')->andReturn(false);
    $disk->shouldReceive('exists')->andReturn(false);
    Storage::set('s3', $disk);

    $this->artisan('ethr:backup')->assertFailed();

    expect(File::directories(config('backup.path')))->toHaveCount(2)
        ->and(app(BackupHealth::class)->snapshot()['reasons'])->toContain('off_host_failing');
});

// ── The alarm ────────────────────────────────────────────────────────────────

test('a fresh backup that reached off-host storage is healthy', function () {
    app(BackupService::class)->copyOffHost(fakeBackup('ethr-20261101-010000'));

    $snapshot = app(BackupHealth::class)->snapshot();

    expect($snapshot['state'])->toBe('ok')
        ->and($snapshot['reasons'])->toBe([])
        ->and($snapshot['off_host']['configured'])->toBeTrue()
        ->and($snapshot['off_host']['last_copied_at'])->not->toBeNull();
});

test('backups that stop are red once a night is missed', function () {
    app(BackupService::class)->copyOffHost(fakeBackup('ethr-20261101-010000'));

    $this->travel(BackupHealth::STALE_AFTER_SECONDS - 60)->seconds();
    expect(app(BackupHealth::class)->snapshot())->state->toBe('ok');

    $this->travel(120)->seconds();
    expect(app(BackupHealth::class)->snapshot())
        ->state->toBe('critical')
        ->reasons->toBe(['backup_stale', 'off_host_stale']);
});

test('a failed backup is red until a later one succeeds', function () {
    fakeBackup('ethr-20261101-010000');
    $this->travel(1)->minutes();
    BackupHealth::recordFailure('backup');

    expect(app(BackupHealth::class)->snapshot()['reasons'])->toContain('backup_failing');

    $this->travel(1)->minutes();
    fakeBackup('ethr-20261101-010200', now()->toIso8601String());

    expect(app(BackupHealth::class)->snapshot()['reasons'])->not->toContain('backup_failing');
});

test('a manual backup before a release does not hide the nightly off-host copy', function () {
    app(BackupService::class)->copyOffHost(fakeBackup('ethr-20261101-010000'));
    $this->travel(1)->hours();
    fakeBackup('ethr-20261101-020000-manual', now()->toIso8601String());

    expect(app(BackupHealth::class)->snapshot())
        ->state->toBe('ok')
        ->and(app(BackupHealth::class)->snapshot()['off_host']['last_copied_at'])->not->toBeNull();
});

test('in production, no backups is critical and no off-host storage is a warning', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['filesystems.disks.s3.bucket' => null]);

    expect(app(BackupHealth::class)->snapshot())
        ->state->toBe('critical')
        ->reasons->toBe(['backup_never_run', 'off_host_not_configured']);

    fakeBackup('ethr-20261101-010000');

    expect(app(BackupHealth::class)->snapshot())
        ->state->toBe('warning')
        ->reasons->toBe(['off_host_not_configured']);
});

test('off production, no backups and no storage are unknown, not an alarm', function () {
    config(['filesystems.disks.s3.bucket' => null]);

    expect(app(BackupHealth::class)->snapshot())
        ->state->toBe('unknown')
        ->reasons->toBe(['backup_never_run', 'off_host_not_configured']);
});

// ── The check an uptime monitor calls ────────────────────────────────────────

test('the backup check needs the cron token, and answers 503 only while backups are critical', function () {
    config(['cron.token' => 'a-token-that-is-at-least-thirty-two-chars-long', 'cron.min_token_length' => 32]);

    $this->getJson('/api/v1/cron/backup')->assertNotFound();

    $check = fn () => $this->withHeader('X-Cron-Token', 'a-token-that-is-at-least-thirty-two-chars-long')->getJson('/api/v1/cron/backup');

    app()->detectEnvironment(fn () => 'production');
    $check()->assertStatus(503)->assertJsonPath('reasons.0', 'backup_never_run');

    app(BackupService::class)->copyOffHost(fakeBackup('ethr-20261101-010000'));
    $check()->assertOk()->assertJsonPath('state', 'ok');
});
