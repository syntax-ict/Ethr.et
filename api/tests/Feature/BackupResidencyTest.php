<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\BackupDriveSetting;
use App\Models\BackupStorageSetting;
use App\Models\User;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupDriveDisk;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupStorageDisk;
use App\Support\BackupHealth;
use App\Support\BackupResidency;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/**
 * Proclamation 1321/2024 Art. 22(1): in production an off-host backup is
 * stored only in Ethiopia. Google Drive is refused outright, the console's S3
 * storage and the `.env` disk only while someone has stated they are in
 * Ethiopia. A refusal is a blocker, never a removal: what a destination
 * already holds can still be read back, because a restore must not depend
 * on a rule written after the copy was made. Off production nothing changes.
 */
const RESIDENCY_CONSOLE = 'http://admin.ethr.test/api/v1/admin/backups';

const RESIDENCY_ENV_KEY = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

beforeEach(function () {
    config([
        'backup.path' => storage_path('framework/testing/backups-residency-'.uniqid()),
        'backup.encryption_key' => RESIDENCY_ENV_KEY,
        'backup.off_host_disk' => 's3',
        'backup.off_host_in_ethiopia' => false,
        'filesystems.disks.s3.bucket' => 'ethr-backups',
        'filesystems.disks.s3.key' => 'test-key',
        'filesystems.disks.s3.secret' => 'test-secret',
    ]);

    Storage::fake('s3');
    Storage::fake('residency-drive');
    Storage::fake('residency-console');

    app()->instance(BackupDriveDisk::class, new class extends BackupDriveDisk
    {
        public function build(BackupDriveSetting $settings): Filesystem
        {
            return Storage::disk('residency-drive');
        }
    });

    app()->instance(BackupStorageDisk::class, new class extends BackupStorageDisk
    {
        public function build(BackupStorageSetting $settings, bool $throw = false): Filesystem
        {
            return Storage::disk('residency-console');
        }

        public function probe(BackupStorageSetting $settings): ?string
        {
            return null;
        }
    });

    RateLimiter::clear('backup-storage-test');
});

afterEach(function () {
    File::deleteDirectory(config('backup.path'));
    app()->detectEnvironment(fn () => 'testing');
});

function residencyProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
}

function residencyBackup(string $name): string
{
    $dir = config('backup.path').DIRECTORY_SEPARATOR.$name;
    File::ensureDirectoryExists($dir.DIRECTORY_SEPARATOR.'private');
    File::put($dir.DIRECTORY_SEPARATOR.'database.sql', "CREATE TABLE t (id INTEGER);\n");
    File::put($dir.DIRECTORY_SEPARATOR.'manifest.json', json_encode([
        'name' => $name,
        'created_at' => now()->toIso8601String(),
        'sql_sha256' => hash_file('sha256', $dir.DIRECTORY_SEPARATOR.'database.sql'),
    ]));

    return $dir;
}

function residencyDrive(): BackupDriveSetting
{
    $setting = new BackupDriveSetting(['client_id' => 'client.apps.googleusercontent.com', 'client_secret' => 'secret']);
    $setting->forceFill([
        'encryption_key' => BackupCipher::generateKey(),
        'refresh_token' => 'refresh-token',
        'folder_id' => 'folder-1',
        'enabled' => true,
        'verified_at' => now(),
        'key_saved_at' => now(),
        'connected_at' => now(),
    ])->save();

    return $setting;
}

function residencyConsoleStorage(bool $inEthiopia): BackupStorageSetting
{
    $setting = new BackupStorageSetting([
        'endpoint' => 'https://storage.example.et',
        'region' => 'addis-1',
        'bucket' => 'ethr-backups',
        'access_key_id' => 'AKIAEXAMPLE',
        'secret_access_key' => 'storage-secret',
        'path_style' => true,
        'located_in_ethiopia' => $inEthiopia,
    ]);
    $setting->forceFill([
        'encryption_key' => BackupCipher::generateKey(),
        'enabled' => true,
        'verified_at' => now(),
        'key_saved_at' => now(),
    ])->save();

    return $setting;
}

function residencyOperator(): User
{
    $user = User::factory()->create(['tenant_id' => null, 'role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true]);
    test()->actingAs($user);

    return $user;
}

/** @return array<string, mixed> */
function residencyStoragePayload(array $overrides = []): array
{
    return array_merge([
        'endpoint' => 'https://storage.example.et',
        'region' => 'addis-1',
        'bucket' => 'ethr-backups',
        'access_key_id' => 'AKIAEXAMPLE',
        'secret_access_key' => 'storage-secret-0123456789abcdef',
        'path_style' => true,
        'located_in_ethiopia' => true,
    ], $overrides);
}

describe('where copies go', function () {
    it('changes nothing off production: Drive still takes the copy when the primary is unusable', function () {
        residencyDrive();
        config(['filesystems.disks.s3.bucket' => null]);

        $copy = app(BackupService::class)->copyOffHost(residencyBackup('ethr-20261125-010000'));

        expect($copy['destination'])->toBe('google_drive');
    });

    it('never copies to Google Drive in production', function () {
        residencyDrive();
        config(['backup.off_host_in_ethiopia' => true, 'filesystems.disks.s3.bucket' => null]);
        residencyProduction();

        $service = app(BackupService::class);
        $drive = collect($service->offHostTargets())->firstWhere('kind', 'google_drive');

        expect($drive['blocker'])->toBe(BackupResidency::BLOCKER)
            ->and($service->offHostBlocker())->toBe('off_host_not_configured');
        expect(fn () => $service->copyOffHost(residencyBackup('ethr-20261125-010000')))->toThrow(RuntimeException::class);
        expect(Storage::disk('residency-drive')->allFiles())->toBe([]);
    });

    it('refuses the .env disk in production until it is stated to be in Ethiopia', function () {
        residencyProduction();
        $service = app(BackupService::class);

        expect($service->primaryTarget()['blocker'])->toBe(BackupResidency::BLOCKER);

        config(['backup.off_host_in_ethiopia' => true]);

        expect($service->primaryTarget()['blocker'])->toBeNull();
        $copy = $service->copyOffHost(residencyBackup('ethr-20261125-010000'));
        expect($copy['destination'])->toBe('s3');
        Storage::disk('s3')->assertExists($copy['key']);
    });

    it('refuses the console storage in production until it is stated to be in Ethiopia', function () {
        $storage = residencyConsoleStorage(inEthiopia: false);
        residencyProduction();
        $service = app(BackupService::class);

        expect($service->primaryTarget()['blocker'])->toBe(BackupResidency::BLOCKER);

        $storage->forceFill(['located_in_ethiopia' => true])->save();

        expect($service->primaryTarget()['blocker'])->toBeNull()
            ->and($service->copyOffHost(residencyBackup('ethr-20261125-010000'))['destination'])->toBe('s3');
    });

    it('still restores, in production, an archive Drive already holds', function () {
        residencyDrive();
        config(['filesystems.disks.s3.bucket' => null]);
        $dir = residencyBackup('ethr-20261120-010000');
        $sql = File::get($dir.'/database.sql');
        $copy = app(BackupService::class)->copyOffHost($dir);
        File::deleteDirectory($dir);

        residencyProduction();

        $this->artisan('ethr:backup:unpack', ['archive' => $copy['key'], '--from-disk' => true, '--destination' => 'google_drive'])
            ->expectsOutputToContain('Unpacked as ethr-20261120-010000')
            ->assertSuccessful();

        expect(File::get($dir.'/database.sql'))->toBe($sql);
    });

    it('reports the refusal as a warning in the operations centre', function () {
        residencyProduction();
        residencyBackup('ethr-20261125-010000');

        $health = app(BackupHealth::class)->snapshot();

        expect($health['state'])->toBe(BackupHealth::WARNING)
            ->and($health['reasons'])->toContain(BackupResidency::BLOCKER);
    });
});

describe('the console', function () {
    it('requires the location statement with every save', function () {
        residencyOperator();
        $payload = residencyStoragePayload();
        unset($payload['located_in_ethiopia']);

        test()->putJson(RESIDENCY_CONSOLE.'/storage', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['located_in_ethiopia']);
    });

    it('refuses, in production, a storage that is not in Ethiopia', function () {
        residencyProduction();
        residencyOperator();

        test()->putJson(RESIDENCY_CONSOLE.'/storage', residencyStoragePayload(['located_in_ethiopia' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['located_in_ethiopia']);

        expect(BackupStorageSetting::query()->count())->toBe(0);
    });

    it('accepts one that is not in Ethiopia off production, and records no statement', function () {
        residencyOperator();

        test()->putJson(RESIDENCY_CONSOLE.'/storage', residencyStoragePayload(['located_in_ethiopia' => false]))
            ->assertOk()
            ->assertJsonPath('storage.located_in_ethiopia', false)
            ->assertJsonPath('storage.located_confirmed_at', null)
            ->assertJsonPath('residency_enforced', false);
    });

    it('records who stated where the storage is, and when, and audits it', function () {
        residencyProduction();
        $operator = residencyOperator();

        test()->putJson(RESIDENCY_CONSOLE.'/storage', residencyStoragePayload())
            ->assertOk()
            ->assertJsonPath('storage.located_in_ethiopia', true)
            ->assertJsonPath('residency_enforced', true);

        $storage = BackupStorageSetting::current();
        expect($storage->located_confirmed_by)->toBe($operator->id)
            ->and($storage->located_confirmed_at)->not->toBeNull();

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'platform.backup_storage_updated')->latest('id')->firstOrFail();
        expect($audit->payload['located_in_ethiopia'] ?? null)->toBeTrue();
    });

    it('restates the statement when the storage moves', function () {
        $operator = residencyOperator();
        test()->putJson(RESIDENCY_CONSOLE.'/storage', residencyStoragePayload())->assertOk();
        $first = BackupStorageSetting::current()->located_confirmed_at;

        $this->travel(1)->hours();
        test()->putJson(RESIDENCY_CONSOLE.'/storage', residencyStoragePayload(['endpoint' => 'https://other.example.et']))->assertOk();

        $storage = BackupStorageSetting::current();
        expect($storage->located_confirmed_at->greaterThan($first))->toBeTrue()
            ->and($storage->located_confirmed_by)->toBe($operator->id);
    });

    it('refuses to switch Google Drive on in production, and still lets it be switched off', function () {
        residencyDrive();
        residencyProduction();
        residencyOperator();

        test()->putJson(RESIDENCY_CONSOLE.'/google-drive/settings', ['enabled' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enabled']);

        test()->putJson(RESIDENCY_CONSOLE.'/google-drive/settings', ['enabled' => false])->assertOk()
            ->assertJsonPath('google_drive.enabled', false)
            ->assertJsonPath('google_drive.blocker', BackupResidency::BLOCKER);
    });
});
