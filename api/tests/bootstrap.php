<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/**
 * Stop the container's runtime environment from hijacking the test suite.
 *
 * PHPUnit's `<env force="true">` writes $_ENV and putenv(), but it never
 * touches $_SERVER. Laravel's Env repository reads $_SERVER *first*, so any
 * variable the environment exports silently beats phpunit.xml no matter what
 * that file says. It was found through the Docker api service (removed
 * 2026-09-30), which exported DB_CONNECTION=mariadb, CACHE_STORE=redis,
 * SESSION_DRIVER=redis, QUEUE_CONNECTION=redis and APP_ENV=local; any shell
 * exporting the same names reproduces it, which is why the guard stays.
 *
 * The consequences were not subtle: `php artisan test` inside the container ran
 * against the *live* MariaDB, so RefreshDatabase wiped the dev database; the
 * shared Redis carried rate-limiter state between runs, producing phantom 429s;
 * and APP_ENV=local meant ValidateCsrfToken never took its testing shortcut, so
 * stateful requests came back 419.
 *
 * Unsetting the keys here — rather than re-declaring their values — keeps
 * phpunit.xml the single source of truth for what the test environment *is*.
 * Laravel then falls through to $_ENV/getenv(), which PHPUnit has populated.
 * This works whichever order PHPUnit applies <env> and loads this bootstrap.
 */
$phpunitControlledKeys = [
    'APP_ENV',
    'APP_MAINTENANCE_DRIVER',
    'BCRYPT_ROUNDS',
    'BROADCAST_CONNECTION',
    'CACHE_STORE',
    'DB_CONNECTION',
    'DB_DATABASE',
    'DB_URL',
    'FILESYSTEM_DISK',
    'FRONTEND_URL',
    'DEVICE_ALLOW_PRIVATE_HOSTS',
    'MAIL_MAILER',
    'QUEUE_CONNECTION',
    'SESSION_DRIVER',
];

foreach ($phpunitControlledKeys as $key) {
    unset($_SERVER[$key]);
}

// DB_HOST/DB_PORT and friends describe the MariaDB service and are meaningless
// for the sqlite :memory: connection the suite uses; leaving them in $_SERVER
// would let a stray sqlite->mysql fallback still find a real server to talk to.
foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
    unset($_SERVER[$key]);
}

/**
 * Start every process in the application's timezone, UTC.
 *
 * A test that never boots Laravel runs in php.ini's `date.timezone`. On a
 * Linux CI runner that is UTC; under XAMPP on Windows it is Europe/Berlin. So
 * EthiopianCalendarTest's Pagume counts and EthiopianTimezoneTest passed only
 * when some earlier test in the same process had booted Laravel, which sets
 * UTC — and failed run alone, first, or under `--parallel` (measured
 * 2026-10-08: four failures, each an off-by-one day). Laravel still sets
 * config('app.timezone') on boot; this only fixes the process before it.
 */
date_default_timezone_set('UTC');
