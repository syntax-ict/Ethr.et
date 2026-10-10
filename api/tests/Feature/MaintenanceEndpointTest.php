<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The HTTP-driven install and release steps.
 *
 * They exist because nothing on the production host can run artisan: no SSH,
 * no PHP in Plesk Git's chroot, no Scheduled Tasks, and a Laravel Toolkit
 * that attaches no application (2026-10-09). They can migrate the database
 * and create a platform administrator, so most of these tests are about
 * refusing.
 */
const MAINTENANCE_TOKEN = 'a-maintenance-token-of-at-least-thirty-two-chars';

beforeEach(function () {
    config()->set('maintenance.token', MAINTENANCE_TOKEN);
    config()->set('maintenance.cache_store', 'array');
    config()->set('maintenance.attempts_per_minute', 5);
    Cache::store('array')->flush();
});

function maintenancePost(string $path, array $body = [], ?string $token = MAINTENANCE_TOKEN)
{
    $headers = $token === null ? [] : ['X-Maintenance-Token' => $token];

    return test()->postJson("/api/v1/maintenance/{$path}", $body, $headers);
}

it('404s when no token is configured, rather than advertising the routes', function () {
    config()->set('maintenance.token', null);

    maintenancePost('migrate')->assertNotFound();
    maintenancePost('key')->assertNotFound();
    maintenancePost('create-admin', ['email' => 'a@b.et', 'password' => 'long-enough-password'])->assertNotFound();
});

it('404s when the configured token is too short to withstand guessing', function () {
    config()->set('maintenance.token', 'short');

    maintenancePost('migrate', [], 'short')->assertNotFound();
});

it('404s on a missing or wrong token, and never takes it from the query string', function () {
    Artisan::shouldReceive('call')->never();

    maintenancePost('migrate', [], null)->assertNotFound();
    maintenancePost('migrate', [], 'wrong-but-also-at-least-thirty-two-characters')->assertNotFound();
    test()->postJson('/api/v1/maintenance/migrate?token='.MAINTENANCE_TOKEN)->assertNotFound();
});

it('stops guessing after five attempts a minute, counting wrong tokens too', function () {
    foreach (range(1, 5) as $i) {
        maintenancePost('migrate', [], "wrong-token-number-{$i}-padded-to-thirty-two-chars")->assertNotFound();
    }

    // The right token, after the budget is spent, is refused before it is compared.
    maintenancePost('migrate')->assertStatus(429);
});

it('runs only the fixed tasks; anything else is not a route', function () {
    Artisan::shouldReceive('call')->never();

    maintenancePost('down')->assertNotFound();
    maintenancePost('tinker')->assertNotFound();
    maintenancePost('migrate:fresh')->assertNotFound();
});

it('migrates with --force and reports the output', function () {
    Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(0);
    Artisan::shouldReceive('output')->andReturn('Migrated: 2026_07_01_000001_create_tenants_table');

    maintenancePost('migrate')
        ->assertOk()
        ->assertJsonPath('task', 'migrate')
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('steps.0.command', 'migrate')
        ->assertJsonPath('steps.0.output', 'Migrated: 2026_07_01_000001_create_tenants_table');
});

it('seeds with the production seeder only', function () {
    Artisan::shouldReceive('call')->once()->with('db:seed', ['--class' => 'ProductionSeeder', '--force' => true])->andReturn(0);
    Artisan::shouldReceive('output')->andReturn('');

    maintenancePost('seed')->assertOk()->assertJsonPath('status', 'ok');
});

it('caches config, routes and views in order, and stops at the first failure', function () {
    Artisan::shouldReceive('call')->once()->with('config:cache', [])->andReturn(0);
    Artisan::shouldReceive('call')->once()->with('route:cache', [])->andReturn(1);
    Artisan::shouldReceive('call')->never()->with('view:cache', []);
    Artisan::shouldReceive('output')->andReturn('');

    maintenancePost('optimize')
        ->assertStatus(500)
        ->assertJsonPath('status', 'failed')
        ->assertJsonCount(2, 'steps');
});

it('reports a migration that throws, instead of a bare server error', function () {
    // 2026-10-10: the host's first migrate threw a QueryException (1067) and
    // the response said only "Server Error"; the cause was in the log alone.
    Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])
        ->andThrow(new RuntimeException("SQLSTATE[42000]: 1067 Invalid default value for 'expires_at'"));

    maintenancePost('migrate')
        ->assertStatus(500)
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('steps.0.exit_code', 1)
        ->assertJsonPath('steps.0.output', "RuntimeException: SQLSTATE[42000]: 1067 Invalid default value for 'expires_at'");
});

it('probes the mail server read-only, through the same token and lock', function () {
    Artisan::shouldReceive('call')->once()->with('ethr:mail-probe', [])->andReturn(1);
    Artisan::shouldReceive('output')->andReturn('Verification: FAILED - certificate verify failed');

    maintenancePost('mail-probe')
        ->assertStatus(500)
        ->assertJsonPath('task', 'mail-probe')
        ->assertJsonPath('steps.0.output', 'Verification: FAILED - certificate verify failed');
});

it('reports an unreachable mail server instead of hanging or throwing', function () {
    config()->set('mail.mailers.smtp.host', '127.0.0.1');
    config()->set('mail.mailers.smtp.port', 1);
    config()->set('mail.mailers.smtp.scheme', 'smtps');

    test()->artisan('ethr:mail-probe', ['--timeout' => 3])
        ->expectsOutputToContain('Could not complete a TLS connection')
        ->assertExitCode(1);
});

it('works before the first migration, when the database cache has no table', function () {
    // The api group's throttle would need this table; these routes must not.
    config()->set('cache.default', 'database');
    Schema::dropIfExists('cache');
    Schema::dropIfExists('cache_locks');

    Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(0);
    Artisan::shouldReceive('output')->andReturn('');

    maintenancePost('migrate')->assertOk();
});

it('runs one task at a time', function () {
    $held = Cache::store('array')->lock('maintenance:run', 300);
    expect($held->get())->toBeTrue();

    Artisan::shouldReceive('call')->never();

    maintenancePost('migrate')->assertStatus(409)->assertJsonPath('status', 'already-running');

    $held->release();
});

it('generates APP_KEY only when none is set, and never shows it', function () {
    config()->set('app.key', '');
    Artisan::shouldReceive('call')->once()->with('key:generate', ['--force' => true])->andReturn(0);
    Artisan::shouldReceive('output')->andReturn('Application key [base64:SECRETSECRETSECRET] set successfully.');

    $response = maintenancePost('key')->assertOk();

    expect($response->getContent())->not->toContain('SECRETSECRETSECRET');
});

it('refuses to replace an APP_KEY that is already set', function () {
    // A second key makes every encrypted column unreadable.
    Artisan::shouldReceive('call')->never();

    maintenancePost('key')->assertStatus(409)->assertJsonPath('status', 'refused');
});

it('creates the platform super admin, and never echoes the password', function () {
    $password = 'Correct-Horse-Battery-Staple-9';

    $response = maintenancePost('create-admin', ['email' => 'owner@ethr.test', 'password' => $password])
        ->assertOk()
        ->assertJsonPath('status', 'ok');

    expect($response->getContent())->not->toContain($password);

    $admin = User::withoutGlobalScopes()->where('email', 'owner@ethr.test')->sole();
    expect($admin->role)->toBe(UserRole::SUPER_ADMIN)
        ->and($admin->tenant_id)->toBeNull();
});

it('refuses a platform admin password shorter than twelve characters', function () {
    maintenancePost('create-admin', ['email' => 'owner@ethr.test', 'password' => 'short'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

it('follows .env, not a cached config: emptying the token there switches the routes off at once', function () {
    // After `optimize`, config() would keep the token from the moment it was
    // cached. With a cached configuration the middleware reads the file.
    $dir = sys_get_temp_dir().'/ethr-maintenance-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/.env', "APP_NAME=ETHR\nMAINTENANCE_TOKEN=\"".MAINTENANCE_TOKEN."\"\n");
    app()->useEnvironmentPath($dir);
    config()->set('maintenance.read_env_file', true);
    config()->set('maintenance.token', 'a-stale-cached-token-that-is-thirty-two-chars');

    Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(0);
    Artisan::shouldReceive('output')->andReturn('');

    // The file's token works; the stale cached one does not.
    maintenancePost('migrate')->assertOk();
    maintenancePost('migrate', [], 'a-stale-cached-token-that-is-thirty-two-chars')->assertNotFound();

    // Emptied in .env: off, whatever the cache still says.
    file_put_contents($dir.'/.env', "APP_NAME=ETHR\nMAINTENANCE_TOKEN=\n");
    maintenancePost('migrate')->assertNotFound();

    unlink($dir.'/.env');
    rmdir($dir);
});
