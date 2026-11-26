<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\BackupDriveSetting;
use App\Models\User;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupDriveDisk;
use App\Services\Backup\GoogleDrive\GoogleDriveOAuth;
use App\Support\BackupHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

/**
 * Google Drive as the fallback destination, as the platform console drives
 * it: who may, what is never returned, the OAuth return the console page
 * posts, and the order the switch insists on. Every Google call is faked.
 */
const DRIVE_CONSOLE = 'http://admin.ethr.test/api/v1/admin/backups';

const DRIVE_CLIENT_ID = 'client-123.apps.googleusercontent.com';

const DRIVE_CLIENT_SECRET = 'GOCSPX-drive-client-secret-0123456789';

const DRIVE_REFRESH_TOKEN = '1//refresh-token-from-google-xyz';

function driveOperator(bool $mfa = true): User
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
function drivePayload(array $overrides = []): array
{
    return array_merge(['client_id' => DRIVE_CLIENT_ID, 'client_secret' => DRIVE_CLIENT_SECRET], $overrides);
}

/** Google's answers for a good connection, in place of the real thing. */
function fakeGoogleConnection(?string $refreshToken = DRIVE_REFRESH_TOKEN, bool $emailWorks = true): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(array_filter([
            'access_token' => 'ya29.access-token',
            'expires_in' => 3599,
            'refresh_token' => $refreshToken,
        ])),
        'oauth2.googleapis.com/revoke' => Http::response('', 200),
        'www.googleapis.com/drive/v3/about*' => $emailWorks
            ? Http::response(['user' => ['emailAddress' => 'owner@example.com']])
            : Http::response(['error' => ['message' => 'insufficient scope']], 403),
        'www.googleapis.com/drive/v3/files*' => Http::response(['id' => 'folder-abc']),
    ]);
}

/** Runs the whole consent round trip as the console page would. */
function connectDrive(): void
{
    $url = test()->postJson(DRIVE_CONSOLE.'/google-drive/connect')->assertOk()->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    test()->postJson(DRIVE_CONSOLE.'/google-drive/callback', ['code' => '4/0AxCode', 'state' => $query['state']])->assertOk();
}

/** A fake disk for the console's test button. */
function fakeDriveDisk(?string $probeError = null): void
{
    $fake = new class extends BackupDriveDisk
    {
        public ?string $probeError = null;

        public function probe(BackupDriveSetting $settings): ?string
        {
            return $this->probeError;
        }
    };
    $fake->probeError = $probeError;
    app()->instance(BackupDriveDisk::class, $fake);
}

function readyDrive(): void
{
    test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload())->assertOk();
    fakeGoogleConnection();
    connectDrive();
    fakeDriveDisk();
    test()->postJson(DRIVE_CONSOLE.'/google-drive/test')->assertOk();
    test()->putJson(DRIVE_CONSOLE.'/google-drive/settings', ['key_saved' => true])->assertOk();
}

beforeEach(function () {
    config([
        'backup.path' => storage_path('framework/testing/backups-drive-'.uniqid()),
        'backup.encryption_key' => null,
        'backup.off_host_disk' => 's3',
        'filesystems.disks.s3.bucket' => null,
    ]);
    Cache::flush();
    RateLimiter::clear('backup-storage-test');
});

afterEach(function () {
    File::deleteDirectory(config('backup.path'));
    app()->detectEnvironment(fn () => 'testing');
});

describe('authorization', function () {
    it('refuses a super admin without MFA', function () {
        driveOperator(mfa: false);

        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload())->assertForbidden();
        expect(BackupDriveSetting::query()->count())->toBe(0);
    });

    it('refuses a tenant admin on every Drive route', function () {
        $tenant = createTenant();
        test()->actingAs(createUser(['role' => UserRole::TENANT_ADMIN], $tenant));

        foreach ([
            ['putJson', '/google-drive', drivePayload()],
            ['deleteJson', '/google-drive', []],
            ['postJson', '/google-drive/connect', []],
            ['postJson', '/google-drive/callback', ['code' => 'c', 'state' => 's']],
            ['postJson', '/google-drive/test', []],
            ['putJson', '/google-drive/settings', ['enabled' => true]],
            ['postJson', '/google-drive/key', []],
        ] as [$method, $path, $body]) {
            expect(test()->{$method}(DRIVE_CONSOLE.$path, $body)->status())->toBeIn([403, 404]);
        }

        expect(BackupDriveSetting::query()->count())->toBe(0);
    });
});

describe('the redirect URI', function () {
    it('is the console page itself, built in one place, and shown to the owner', function () {
        driveOperator();

        $expected = GoogleDriveOAuth::redirectUri();

        expect($expected)->toEndWith('/admin/backups');
        test()->getJson(DRIVE_CONSOLE)->assertOk()->assertJsonPath('google_drive.redirect_uri', $expected);
    });

    it('is the admin host of the application domain, over the frontend scheme', function () {
        config(['app.domain' => 'ethr.et', 'app.frontend_url' => 'https://ethr.et']);

        expect(GoogleDriveOAuth::redirectUri())->toBe('https://admin.ethr.et/admin/backups');
    });
});

describe('secrets', function () {
    it('never returns the client secret, the refresh token or the key', function () {
        driveOperator();
        $saved = test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload())->assertOk();
        fakeGoogleConnection();
        connectDrive();
        $read = test()->getJson(DRIVE_CONSOLE)->assertOk();
        $key = BackupDriveSetting::query()->first()->encryption_key;

        foreach ([$saved, $read] as $response) {
            expect($response->getContent())->not->toContain(DRIVE_CLIENT_SECRET)
                ->and($response->getContent())->not->toContain(DRIVE_REFRESH_TOKEN)
                ->and($response->getContent())->not->toContain(substr($key, 7, 20));
        }

        $read->assertJsonPath('google_drive.secret_set', true)
            ->assertJsonPath('google_drive.connected', true)
            ->assertJsonMissingPath('google_drive.client_secret')
            ->assertJsonMissingPath('google_drive.refresh_token');
    });

    it('stores the secret, the token and the key encrypted, and makes the key itself', function () {
        driveOperator();
        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload())->assertOk();
        fakeGoogleConnection();
        connectDrive();

        $row = DB::table('backup_drive_settings')->first();
        $setting = BackupDriveSetting::query()->first();

        expect($row->client_secret)->not->toContain(DRIVE_CLIENT_SECRET)
            ->and($row->refresh_token)->not->toContain(DRIVE_REFRESH_TOKEN)
            ->and($row->encryption_key)->not->toContain($setting->encryption_key)
            ->and($setting->refresh_token)->toBe(DRIVE_REFRESH_TOKEN)
            ->and(BackupCipher::decodeKey($setting->encryption_key))->not->toBeNull();
    });

    it('requires a secret the first time and keeps it afterwards', function () {
        driveOperator();

        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload(['client_secret' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('client_secret');

        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload())->assertOk();
        $key = BackupDriveSetting::query()->first()->encryption_key;

        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload(['client_secret' => null]))->assertOk();

        $setting = BackupDriveSetting::query()->first();
        expect($setting->client_secret)->toBe(DRIVE_CLIENT_SECRET)->and($setting->encryption_key)->toBe($key);
    });

    it('records no secret in the audit log', function () {
        driveOperator();
        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload())->assertOk();
        fakeGoogleConnection();
        connectDrive();

        $logged = json_encode(AuditLog::withoutGlobalScopes()->where('action', 'like', 'platform.backup_drive%')->get()->toArray());

        expect($logged)->toContain('platform.backup_drive_connected')
            ->and($logged)->not->toContain(DRIVE_CLIENT_SECRET)
            ->and($logged)->not->toContain(DRIVE_REFRESH_TOKEN);
    });
});

describe('connecting', function () {
    it('answers a consent URL with the scope, the redirect and a state bound to the cache', function () {
        driveOperator();
        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload())->assertOk();

        $url = test()->postJson(DRIVE_CONSOLE.'/google-drive/connect')->assertOk()->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
            ->and($query)->toMatchArray([
                'client_id' => DRIVE_CLIENT_ID,
                'redirect_uri' => GoogleDriveOAuth::redirectUri(),
                'response_type' => 'code',
                'scope' => 'https://www.googleapis.com/auth/drive.file',
                'access_type' => 'offline',
                'prompt' => 'consent',
                'include_granted_scopes' => 'true',
            ])
            ->and(strlen($query['state']))->toBeGreaterThanOrEqual(32)
            ->and(Cache::has('backup-drive:oauth-state:'.hash('sha256', $query['state'])))->toBeTrue();
    });

    it('needs the client saved first', function () {
        driveOperator();

        test()->postJson(DRIVE_CONSOLE.'/google-drive/connect')->assertNotFound();
    });

    it('stores the refresh token, finds the email, makes the folder and leaves it switched off', function () {
        driveOperator();
        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload())->assertOk();
        fakeGoogleConnection();

        connectDrive();

        $setting = BackupDriveSetting::query()->first();
        expect($setting->isConnected())->toBeTrue()
            ->and($setting->account_email)->toBe('owner@example.com')
            ->and($setting->folder_id)->toBe('folder-abc')
            ->and($setting->enabled)->toBeFalse()
            ->and($setting->verified_at)->toBeNull();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/token')
            && $r['grant_type'] === 'authorization_code'
            && $r['code'] === '4/0AxCode'
            && $r['redirect_uri'] === GoogleDriveOAuth::redirectUri());
        Http::assertSent(fn ($r) => str_contains($r->url(), '/drive/v3/files') && ($r['mimeType'] ?? null) === 'application/vnd.google-apps.folder');
    });

    it('still connects when the account lookup is refused', function () {
        driveOperator();
        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload())->assertOk();
        fakeGoogleConnection(emailWorks: false);

        connectDrive();

        $setting = BackupDriveSetting::query()->first();
        expect($setting->isConnected())->toBeTrue()->and($setting->account_email)->toBeNull();
    });

    it('refuses a missing, wrong, reused or someone else\'s state', function () {
        driveOperator();
        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload())->assertOk();
        fakeGoogleConnection();

        test()->postJson(DRIVE_CONSOLE.'/google-drive/callback', ['code' => 'c'])
            ->assertUnprocessable()->assertJsonValidationErrors('state');
        test()->postJson(DRIVE_CONSOLE.'/google-drive/callback', ['code' => 'c', 'state' => 'never-issued'])
            ->assertUnprocessable()->assertJsonValidationErrors('state');

        $url = test()->postJson(DRIVE_CONSOLE.'/google-drive/connect')->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        // Another super admin cannot finish a flow this one began.
        $first = auth()->user();
        driveOperator();
        test()->postJson(DRIVE_CONSOLE.'/google-drive/callback', ['code' => 'c', 'state' => $query['state']])
            ->assertUnprocessable()->assertJsonValidationErrors('state');

        // Single use: the refused try already spent it.
        test()->actingAs($first);
        test()->postJson(DRIVE_CONSOLE.'/google-drive/callback', ['code' => 'c', 'state' => $query['state']])
            ->assertUnprocessable();

        expect(BackupDriveSetting::query()->first()->isConnected())->toBeFalse();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/token'));
    });

    it('spends the state on a good callback, so it cannot be replayed', function () {
        driveOperator();
        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload())->assertOk();
        fakeGoogleConnection();
        $url = test()->postJson(DRIVE_CONSOLE.'/google-drive/connect')->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        test()->postJson(DRIVE_CONSOLE.'/google-drive/callback', ['code' => 'c', 'state' => $query['state']])->assertOk();
        test()->postJson(DRIVE_CONSOLE.'/google-drive/callback', ['code' => 'c', 'state' => $query['state']])->assertUnprocessable();
    });

    it('fails cleanly when Google gives no refresh token', function () {
        driveOperator();
        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload())->assertOk();
        fakeGoogleConnection(refreshToken: null);

        $url = test()->postJson(DRIVE_CONSOLE.'/google-drive/connect')->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        test()->postJson(DRIVE_CONSOLE.'/google-drive/callback', ['code' => 'c', 'state' => $query['state']])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        expect(BackupDriveSetting::query()->first()->isConnected())->toBeFalse();
    });

    it('reports Google\'s refusal of the code, without echoing the secret', function () {
        driveOperator();
        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload())->assertOk();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Bad Request'], 400)]);

        $url = test()->postJson(DRIVE_CONSOLE.'/google-drive/connect')->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $response = test()->postJson(DRIVE_CONSOLE.'/google-drive/callback', ['code' => 'c', 'state' => $query['state']])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        expect($response->getContent())->toContain('invalid_grant')->and($response->getContent())->not->toContain(DRIVE_CLIENT_SECRET);
    });
});

describe('the order the switch insists on', function () {
    it('refuses to switch on before a test and a confirmed key', function () {
        driveOperator();
        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload())->assertOk();
        fakeGoogleConnection();
        connectDrive();

        test()->putJson(DRIVE_CONSOLE.'/google-drive/settings', ['enabled' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('enabled');

        test()->putJson(DRIVE_CONSOLE.'/google-drive/settings', ['key_saved' => true])->assertOk();
        test()->putJson(DRIVE_CONSOLE.'/google-drive/settings', ['enabled' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('enabled');

        fakeDriveDisk('Drive says no');
        test()->postJson(DRIVE_CONSOLE.'/google-drive/test')->assertOk()
            ->assertJsonPath('google_drive.verified', false)
            ->assertJsonPath('google_drive.last_test_error', 'Drive says no');
        test()->putJson(DRIVE_CONSOLE.'/google-drive/settings', ['enabled' => true])->assertUnprocessable();

        fakeDriveDisk();
        test()->postJson(DRIVE_CONSOLE.'/google-drive/test')->assertOk()->assertJsonPath('google_drive.verified', true);
        test()->putJson(DRIVE_CONSOLE.'/google-drive/settings', ['enabled' => true])->assertOk()
            ->assertJsonPath('google_drive.enabled', true)
            ->assertJsonPath('google_drive.available', true);
    });

    it('switches off when the key confirmation is withdrawn', function () {
        driveOperator();
        readyDrive();
        test()->putJson(DRIVE_CONSOLE.'/google-drive/settings', ['enabled' => true])->assertOk();

        test()->putJson(DRIVE_CONSOLE.'/google-drive/settings', ['key_saved' => false])->assertOk()
            ->assertJsonPath('google_drive.enabled', false);
    });

    it('shows its own key, audited, and keeps it apart from the main storage key', function () {
        driveOperator();
        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload())->assertOk();
        $key = BackupDriveSetting::query()->first()->encryption_key;

        test()->postJson(DRIVE_CONSOLE.'/google-drive/key')->assertOk()->assertJsonPath('key', $key);

        expect(AuditLog::withoutGlobalScopes()->where('action', 'platform.backup_drive_key_revealed')->count())->toBe(1)
            ->and(BackupDriveSetting::query()->first()->key_revealed_at)->not->toBeNull();
    });
});

describe('changing or ending the connection', function () {
    it('clears the connection, the test and the switch when the client changes', function () {
        driveOperator();
        readyDrive();
        test()->putJson(DRIVE_CONSOLE.'/google-drive/settings', ['enabled' => true])->assertOk();
        $key = BackupDriveSetting::query()->first()->encryption_key;

        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload(['client_id' => 'another-client.apps.googleusercontent.com']))
            ->assertOk()
            ->assertJsonPath('google_drive.connected', false)
            ->assertJsonPath('google_drive.enabled', false)
            ->assertJsonPath('google_drive.verified', false);

        $setting = BackupDriveSetting::query()->first();
        expect($setting->refresh_token)->toBeNull()->and($setting->folder_id)->toBeNull()
            ->and($setting->encryption_key)->toBe($key);
    });

    it('keeps the connection when the same client is saved again', function () {
        driveOperator();
        readyDrive();

        test()->putJson(DRIVE_CONSOLE.'/google-drive', drivePayload(['client_secret' => null]))->assertOk()
            ->assertJsonPath('google_drive.connected', true)
            ->assertJsonPath('google_drive.verified', true);
    });

    it('disconnects, revokes the token at Google and keeps the key', function () {
        driveOperator();
        readyDrive();
        test()->putJson(DRIVE_CONSOLE.'/google-drive/settings', ['enabled' => true])->assertOk();
        fakeGoogleConnection();

        test()->deleteJson(DRIVE_CONSOLE.'/google-drive')->assertOk()
            ->assertJsonPath('google_drive.connected', false)
            ->assertJsonPath('google_drive.enabled', false)
            ->assertJsonPath('google_drive.saved', true);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke') && $r['token'] === DRIVE_REFRESH_TOKEN);
        expect(BackupDriveSetting::query()->first()->refresh_token)->toBeNull();
    });
});

describe('before the migration has run', function () {
    it('answers the console page and the health snapshot while the table is missing', function () {
        driveOperator();
        Schema::drop('backup_drive_settings');

        test()->getJson(DRIVE_CONSOLE)->assertOk()
            ->assertJsonPath('google_drive.saved', false)
            ->assertJsonPath('google_drive.connected', false);

        expect(BackupDriveSetting::current())->toBeNull()
            ->and(app(BackupHealth::class)->snapshot())->toHaveKey('off_host');
    });
});
