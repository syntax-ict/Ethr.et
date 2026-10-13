<?php

declare(strict_types=1);

use App\Models\Department;
use App\Services\Backup\BackupService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/**
 * Restoring a backup on a host with no shell (2026-10-14).
 *
 * The host had no safe restore: its deployment action has no PHP, and a
 * phpMyAdmin import breaks the audit_log triggers. The maintenance endpoint
 * now lists backups, takes one, and restores one — after a safety backup of
 * the current state, and only when the name is typed twice.
 *
 * SQLite only, for the reason BackupRestoreRehearsalTest gives: a restore
 * drops every table, which RefreshDatabase's transaction undoes on SQLite and
 * MySQL's implicit DDL commit does not.
 */
const RESTORE_TOKEN = 'a-maintenance-token-of-at-least-thirty-two-chars';

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        test()->markTestSkipped('Destructive restore is SQLite-only in the suite; use `artisan ethr:backup:rehearse` on MySQL.');
    }

    // The limiter and the lock live in this store; the array store starts
    // empty per test, as MaintenanceEndpointTest does it.
    config([
        'maintenance.token' => RESTORE_TOKEN,
        'maintenance.cache_store' => 'array',
        'backup.path' => storage_path('framework/testing/restore-backups'),
    ]);
    Cache::store('array')->flush();
    File::deleteDirectory(config('backup.path'));
});

afterEach(fn () => File::deleteDirectory(config('backup.path')));

function maintenance(string $path, array $body = []): TestResponse
{
    return test()->withHeaders(['X-Maintenance-Token' => RESTORE_TOKEN])
        ->postJson("/api/v1/maintenance/{$path}", $body === [] ? ['_' => 1] : $body);
}

it('lists the backups on the host, newest first', function () {
    $older = app(BackupService::class)->create('first');
    $this->travel(2)->seconds();
    $newer = app(BackupService::class)->create('second');

    maintenance('backups')
        ->assertOk()
        ->assertJsonPath('backups.0.name', $newer['name'])
        ->assertJsonPath('backups.1.name', $older['name']);
});

it('takes a backup on request', function () {
    maintenance('backup')->assertOk()->assertJsonPath('status', 'ok');

    expect(maintenance('backups')->json('backups'))->toHaveCount(1);
});

it('restores a backup after taking a safety backup, and checks the audit log still holds', function () {
    $tenant = createTenant();
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Before the backup']);
    $backup = app(BackupService::class)->create('known-good');
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'After the backup']);

    $this->travel(2)->seconds();

    $response = maintenance('restore', ['name' => $backup['name'], 'confirm' => $backup['name']])
        ->assertOk()
        ->assertJsonPath('status', 'ok');

    expect(array_column($response->json('steps'), 'command'))->toBe([
        'ethr:backup --label=pre-restore',
        "ethr:restore {$backup['name']}",
        'audit_log append-only check',
    ]);

    $names = DB::table('departments')->pluck('name')->all();
    expect($names)->toContain('Before the backup')->not->toContain('After the backup');

    expect(DB::table('audit_log')->where('action', 'backup.restored')->count())->toBe(1);
    expect(collect(maintenance('backups')->json('backups'))->pluck('name')->filter(fn ($n) => str_ends_with($n, '-pre-restore')))
        ->toHaveCount(1);
});

it('refuses a restore that is not typed twice, names no backup, or names a path', function (array $body, int $status) {
    app(BackupService::class)->create('known-good');

    maintenance('restore', $body)->assertStatus($status);
})->with([
    'confirmation differs' => [['name' => 'ethr-20261014-010000-known-good', 'confirm' => 'ethr-20261014-010000'], 422],
    'no such backup' => [['name' => 'ethr-20200101-000000', 'confirm' => 'ethr-20200101-000000'], 404],
    'a path, not a name' => [['name' => '../../.env', 'confirm' => '../../.env'], 422],
]);

it('is closed without the maintenance token', function () {
    $this->postJson('/api/v1/maintenance/backups', ['_' => 1])->assertNotFound();
    $this->postJson('/api/v1/maintenance/restore', ['name' => 'x', 'confirm' => 'x'])->assertNotFound();
});
