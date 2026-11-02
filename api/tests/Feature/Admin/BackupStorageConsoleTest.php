<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\BackupStorageSetting;
use App\Models\User;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupStorageDisk;
use App\Support\BackupHealth;
use App\Support\OutboundHost;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/**
 * Off-host backup storage configured in the platform console: who may use it,
 * what it never reveals, the order it insists on (save, test, keep the key,
 * then switch on), and that the nightly copy follows it instead of `.env`.
 */
const BACKUP_CONSOLE = 'http://admin.ethr.test/api/v1/admin/backups';

const BACKUP_CONSOLE_SECRET = 'storage-secret-0123456789abcdef';

function backupOperator(bool $mfa = true): User
{
    $user = User::factory()->create([
        'tenant_id' => null,
        'role' => UserRole::SUPER_ADMIN,
        'mfa_enabled' => $mfa,
    ]);

    test()->actingAs($user);

    return $user;
}

/** @return array<string, mixed> */
function storagePayload(array $overrides = []): array
{
    return array_merge([
        'endpoint' => 'https://storage.example.com',
        'region' => 'us-east-1',
        'bucket' => 'ethr-backups',
        'access_key_id' => 'AKIAEXAMPLE',
        'secret_access_key' => BACKUP_CONSOLE_SECRET,
        'path_style' => true,
    ], $overrides);
}

/** The disk the console storage builds, faked, and what its test answers. */
function fakeConsoleStorage(?string $probeError = null): object
{
    $fake = new class extends BackupStorageDisk
    {
        public ?string $probeError = null;

        public function build(BackupStorageSetting $settings, bool $throw = false): Filesystem
        {
            return Storage::disk('backup-console-fake');
        }

        public function probe(BackupStorageSetting $settings): ?string
        {
            return $this->probeError;
        }
    };
    $fake->probeError = $probeError;
    app()->instance(BackupStorageDisk::class, $fake);

    return $fake;
}

function consoleBackupDir(string $name): string
{
    $dir = config('backup.path').DIRECTORY_SEPARATOR.$name;
    File::ensureDirectoryExists($dir);
    File::put($dir.DIRECTORY_SEPARATOR.'database.sql', "CREATE TABLE t (id INTEGER);\n");
    File::put($dir.DIRECTORY_SEPARATOR.'manifest.json', json_encode([
        'name' => $name,
        'created_at' => now()->toIso8601String(),
        'sql_sha256' => hash_file('sha256', $dir.DIRECTORY_SEPARATOR.'database.sql'),
    ]));

    return $dir;
}

/** Saved, tested and confirmed: everything the switch needs. */
function readyConsoleStorage(): void
{
    test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload())->assertOk();
    test()->postJson(BACKUP_CONSOLE.'/storage/test')->assertOk();
    test()->putJson(BACKUP_CONSOLE.'/settings', ['key_saved' => true])->assertOk();
}

beforeEach(function () {
    config([
        'backup.path' => storage_path('framework/testing/backups-console-'.uniqid()),
        'backup.encryption_key' => null,
        'backup.off_host_disk' => 's3',
        'filesystems.disks.s3.bucket' => null,
    ]);
    Storage::fake('backup-console-fake');
    fakeConsoleStorage();
    RateLimiter::clear('backup-storage-test');
});

afterEach(function () {
    File::deleteDirectory(config('backup.path'));
    app()->detectEnvironment(fn () => 'testing');
});

describe('authorization', function () {
    it('lets a platform super admin with MFA read the page', function () {
        backupOperator();

        test()->getJson(BACKUP_CONSOLE)->assertOk()
            ->assertJsonPath('source', 'none')
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('storage', null);
    });

    it('refuses a super admin without MFA', function () {
        backupOperator(mfa: false);

        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload())->assertForbidden();
        expect(BackupStorageSetting::query()->count())->toBe(0);
    });

    it('refuses a tenant admin', function () {
        $tenant = createTenant();
        test()->actingAs(createUser(['role' => UserRole::TENANT_ADMIN], $tenant));

        expect(test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload())->status())->toBeIn([403, 404]);
        expect(test()->postJson(BACKUP_CONSOLE.'/key')->status())->toBeIn([403, 404]);
        expect(BackupStorageSetting::query()->count())->toBe(0);
    });
});

describe('secrets', function () {
    it('never returns the storage secret or the key, only that they exist', function () {
        backupOperator();

        $saved = test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload())->assertOk();
        $read = test()->getJson(BACKUP_CONSOLE)->assertOk();
        $key = BackupStorageSetting::query()->first()->encryption_key;

        foreach ([$saved, $read] as $response) {
            expect($response->getContent())->not->toContain(BACKUP_CONSOLE_SECRET)
                ->and($response->getContent())->not->toContain(substr($key, 7, 20));
            $response->assertJsonPath('storage.secret_set', true)
                ->assertJsonMissingPath('storage.secret_access_key');
        }
    });

    it('stores the secret and the key encrypted, and generates the key itself', function () {
        backupOperator();
        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload())->assertOk();

        $row = DB::table('backup_storage_settings')->first();
        $settings = BackupStorageSetting::query()->first();

        expect($row->secret_access_key)->not->toContain(BACKUP_CONSOLE_SECRET)
            ->and($settings->secret_access_key)->toBe(BACKUP_CONSOLE_SECRET)
            ->and($row->encryption_key)->not->toContain($settings->encryption_key)
            ->and(BackupCipher::decodeKey($settings->encryption_key))->not->toBeNull();
    });

    it('keeps the secret and the key when the form is saved again without a secret', function () {
        backupOperator();
        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload())->assertOk();
        $key = BackupStorageSetting::query()->first()->encryption_key;

        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload(['secret_access_key' => null, 'region' => 'eu-west-1']))->assertOk();

        $settings = BackupStorageSetting::query()->first();
        expect($settings->secret_access_key)->toBe(BACKUP_CONSOLE_SECRET)
            ->and($settings->encryption_key)->toBe($key);
    });

    it('requires a secret the first time', function () {
        backupOperator();

        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload(['secret_access_key' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('secret_access_key');
    });

    it('refuses plain http and internal addresses', function (string $endpoint) {
        backupOperator();

        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload(['endpoint' => $endpoint]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endpoint');
    })->with(['http://storage.example.com', 'https://127.0.0.1', 'https://localhost']);

    it('refuses a bucket that puts the connection on an internal address', function () {
        backupOperator();
        OutboundHost::resolveUsing(fn (string $host) => $host === 'internal.storage.example.com' ? ['10.0.0.5'] : ['203.0.113.10']);

        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload(['bucket' => 'internal', 'path_style' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors('bucket');
        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload(['bucket' => 'internal', 'path_style' => true]))
            ->assertOk();

        OutboundHost::resolveUsing(null);
    });

    it('will not connect once the name has come to point inside', function () {
        backupOperator();
        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload())->assertOk();
        OutboundHost::resolveUsing(fn () => ['127.0.0.1']);

        expect((new BackupStorageDisk)->probe(BackupStorageSetting::current()))
            ->toBe('The storage address points to an internal address.');

        OutboundHost::resolveUsing(null);
    });

    it('shows the key only through its own action, and records that it was shown', function () {
        $admin = backupOperator();
        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload())->assertOk();

        $key = test()->postJson(BACKUP_CONSOLE.'/key')->assertOk()->json('key');

        expect($key)->toBe(BackupStorageSetting::query()->first()->encryption_key)
            ->and(BackupCipher::decodeKey($key))->not->toBeNull();
        test()->getJson(BACKUP_CONSOLE)->assertJsonPath('key.saved_at', null)
            ->assertJson(fn ($json) => $json->where('key.revealed_at', fn ($at) => $at !== null)->etc());

        $entry = AuditLog::withoutGlobalScopes()->where('action', 'platform.backup_key_revealed')->first();
        expect($entry)->not->toBeNull()
            ->and($entry->user_id)->toBe($admin->id)
            ->and(json_encode($entry->toArray()))->not->toContain(substr($key, 7, 20));
    });

    it('never writes the secret or the key to the audit log', function () {
        backupOperator();
        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload())->assertOk();
        $key = BackupStorageSetting::query()->first()->encryption_key;

        $entries = AuditLog::withoutGlobalScopes()->where('action', 'platform.backup_storage_updated')->get();
        expect($entries)->toHaveCount(1);
        $logged = json_encode($entries->toArray());

        expect($logged)->not->toContain(BACKUP_CONSOLE_SECRET)
            ->and($logged)->not->toContain(substr($key, 7, 20));
    });
});

describe('the order: save, test, keep the key, switch on', function () {
    it('will not switch on before the storage has passed a test', function () {
        backupOperator();
        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload())->assertOk();
        test()->putJson(BACKUP_CONSOLE.'/settings', ['key_saved' => true])->assertOk();

        test()->putJson(BACKUP_CONSOLE.'/settings', ['enabled' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enabled');
    });

    it('will not switch on before the key is confirmed saved', function () {
        backupOperator();
        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload())->assertOk();
        test()->postJson(BACKUP_CONSOLE.'/storage/test')->assertOk()->assertJsonPath('storage.verified', true);

        test()->putJson(BACKUP_CONSOLE.'/settings', ['enabled' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enabled');
    });

    it('switches on once both are done, and the copy is then available', function () {
        backupOperator();
        readyConsoleStorage();

        test()->putJson(BACKUP_CONSOLE.'/settings', ['enabled' => true])->assertOk()
            ->assertJsonPath('source', 'console')
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('available', true)
            ->assertJsonPath('blocker', null);
    });

    it('records a failed test with the storage\'s own message, and does not verify', function () {
        backupOperator();
        fakeConsoleStorage('NoSuchBucket: The specified bucket does not exist');
        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload())->assertOk();

        test()->postJson(BACKUP_CONSOLE.'/storage/test')->assertOk()
            ->assertJsonPath('storage.verified', false)
            ->assertJsonPath('storage.last_test_ok', false)
            ->assertJsonPath('storage.last_test_error', 'NoSuchBucket: The specified bucket does not exist');
    });

    it('clears the test and switches copies off when the connection changes', function () {
        backupOperator();
        readyConsoleStorage();
        test()->putJson(BACKUP_CONSOLE.'/settings', ['enabled' => true])->assertOk();

        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload(['bucket' => 'another-bucket', 'secret_access_key' => null]))->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('storage.verified', false)
            ->assertJsonPath('blocker', 'off_host_switched_off');
    });

    it('switches copies off when the key confirmation is withdrawn', function () {
        backupOperator();
        readyConsoleStorage();
        test()->putJson(BACKUP_CONSOLE.'/settings', ['enabled' => true])->assertOk();

        test()->putJson(BACKUP_CONSOLE.'/settings', ['key_saved' => false])->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('key.saved_at', null);
    });
});

describe('the nightly copy follows the console', function () {
    it('copies to the console storage, encrypted with the console key, and unpacks from it', function () {
        backupOperator();
        readyConsoleStorage();
        test()->putJson(BACKUP_CONSOLE.'/settings', ['enabled' => true])->assertOk();
        $service = app(BackupService::class);

        $copy = $service->copyOffHost(consoleBackupDir('ethr-20261102-010000'));

        expect($copy['disk'])->toBe('ethr-backups');
        Storage::disk('backup-console-fake')->assertExists($copy['key']);
        expect(Storage::disk('backup-console-fake')->get($copy['key']))->toStartWith(BackupCipher::MAGIC);

        File::deleteDirectory(config('backup.path').DIRECTORY_SEPARATOR.'ethr-20261102-010000');
        expect($service->unpack($copy['key'], fromOffHost: true))->toBe('ethr-20261102-010000');
    });

    it('takes over from a configured .env disk, even while switched off', function () {
        config([
            'backup.encryption_key' => 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=',
            'filesystems.disks.s3.bucket' => 'env-bucket',
            'filesystems.disks.s3.key' => 'env-key',
            'filesystems.disks.s3.secret' => 'env-secret',
        ]);
        Storage::fake('s3');
        $service = app(BackupService::class);
        expect($service->offHostBlocker())->toBeNull();

        backupOperator();
        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload())->assertOk();

        expect($service->offHostBlocker())->toBe('off_host_switched_off');
        expect(fn () => $service->copyOffHost(consoleBackupDir('ethr-20261102-010000')))
            ->toThrow(RuntimeException::class, 'off_host_switched_off');
        expect(Storage::disk('s3')->allFiles())->toBe([]);
    });

    it('reports a key that no longer decrypts as missing, and still answers the page', function () {
        backupOperator();
        readyConsoleStorage();
        test()->putJson(BACKUP_CONSOLE.'/settings', ['enabled' => true])->assertOk();
        DB::table('backup_storage_settings')->update([
            'encryption_key' => 'not-decryptable',
            'secret_access_key' => 'not-decryptable',
        ]);

        expect(app(BackupService::class)->offHostBlocker())->toBe('off_host_key_missing');
        test()->getJson(BACKUP_CONSOLE)->assertOk()
            ->assertJsonPath('blocker', 'off_host_key_missing')
            ->assertJsonPath('storage.secret_set', true);
    });

    it('reports the console storage in the operations centre', function () {
        app()->detectEnvironment(fn () => 'production');
        backupOperator();
        test()->putJson(BACKUP_CONSOLE.'/storage', storagePayload())->assertOk();
        consoleBackupDir('ethr-20261102-010000');

        $off = app(BackupHealth::class)->snapshot();
        expect($off['state'])->toBe(BackupHealth::WARNING)
            ->and($off['reasons'])->toContain('off_host_switched_off')
            ->and($off['off_host'])->toMatchArray(['configured' => false, 'disk' => 'ethr-backups']);

        test()->postJson(BACKUP_CONSOLE.'/storage/test')->assertOk();
        test()->putJson(BACKUP_CONSOLE.'/settings', ['key_saved' => true, 'enabled' => true])->assertOk();

        expect(app(BackupHealth::class)->snapshot()['reasons'])->toBe(['off_host_copy_missing']);
    });
});
