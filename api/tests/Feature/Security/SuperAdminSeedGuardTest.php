<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

/**
 * `SUPER_ADMIN_PASSWORD=` is an empty string, not null.
 *
 * DatabaseSeeder creates a platform super admin — an account that bypasses every
 * permission check and can read every tenant — so it refuses to run in
 * production without a password supplied from the environment. That guard was
 * written as `env('SUPER_ADMIN_PASSWORD') === null`, which a present-but-blank
 * line in a .env file does not satisfy: the check passed, the empty string then
 * flowed into the password field, and the seeder produced exactly the
 * known-credential account it exists to prevent. Same trap as APP_DOMAIN in
 * TenancyDomainTest, with a worse blast radius.
 */
function runDatabaseSeederAsProduction(?string $superAdminPassword): void
{
    if ($superAdminPassword === null) {
        putenv('SUPER_ADMIN_PASSWORD');
        unset($_ENV['SUPER_ADMIN_PASSWORD'], $_SERVER['SUPER_ADMIN_PASSWORD']);
    } else {
        putenv('SUPER_ADMIN_PASSWORD='.$superAdminPassword);
        $_ENV['SUPER_ADMIN_PASSWORD'] = $superAdminPassword;
        $_SERVER['SUPER_ADMIN_PASSWORD'] = $superAdminPassword;
    }

    app()->detectEnvironment(fn () => 'production');

    try {
        app(DatabaseSeeder::class)->run();
    } finally {
        putenv('SUPER_ADMIN_PASSWORD');
        unset($_ENV['SUPER_ADMIN_PASSWORD'], $_SERVER['SUPER_ADMIN_PASSWORD']);
        app()->detectEnvironment(fn () => 'testing');
    }
}

it('refuses to seed a production super admin without a usable password', function (?string $value) {
    $threw = false;

    try {
        runDatabaseSeederAsProduction($value);
    } catch (RuntimeException $e) {
        $threw = true;
        expect($e->getMessage())->toContain('SUPER_ADMIN_PASSWORD');
    }

    expect($threw)->toBeTrue()
        ->and(User::withoutGlobalScopes()->whereNull('tenant_id')->count())->toBe(0);
})->with([
    'unset' => null,
    'empty string' => '',
    'whitespace only' => '   ',
]);
