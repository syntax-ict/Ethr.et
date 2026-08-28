<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/**
 * Stop the container's runtime environment from hijacking the test suite.
 *
 * PHPUnit's `<env force="true">` writes $_ENV and putenv(), but it never
 * touches $_SERVER. Laravel's Env repository reads $_SERVER *first*, so any
 * variable the api service exports in docker-compose.yml — DB_CONNECTION=mariadb,
 * CACHE_STORE=redis, SESSION_DRIVER=redis, QUEUE_CONNECTION=redis, APP_ENV=local —
 * silently beat phpunit.xml no matter what that file says.
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
    'MAIL_MAILER',
    'QUEUE_CONNECTION',
    'SESSION_DRIVER',
    'PULSE_ENABLED',
    'TELESCOPE_ENABLED',
    'NIGHTWATCH_ENABLED',
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
