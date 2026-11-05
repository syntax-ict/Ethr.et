<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\File;

/**
 * `/health` says how many migrations the database has not run, so a deploy
 * whose `migrate` was skipped shows up from outside (2026-11-04: v2026.11.04.5
 * served code ahead of its schema for five hours, and nothing could see it).
 * It reports a count, never names, and never turns the probe into a 503.
 */
test('health reports no pending migrations when the schema is current', function () {
    test()->getJson('/api/v1/health')
        ->assertJsonPath('schema_detail.pending_migrations', 0);
});

test('health counts a migration that is on disk but not run, and stays up', function () {
    $dir = storage_path('framework/testing/pending-migrations-'.uniqid());
    File::ensureDirectoryExists($dir);
    File::put($dir.'/2099_01_01_000000_not_run_yet.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;

        return new class extends Migration
        {
            public function up(): void {}
        };
        PHP);
    app(Migrator::class)->path($dir);

    try {
        $response = test()->getJson('/api/v1/health');

        expect($response->json('schema_detail.pending_migrations'))->toBe(1)
            ->and($response->json('schema_detail'))->toBe(['pending_migrations' => 1])
            ->and($response->status())->not->toBe(503);
    } finally {
        File::deleteDirectory($dir);
    }
});
