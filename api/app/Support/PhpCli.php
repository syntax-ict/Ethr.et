<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The PHP binary a scheduled child process should run.
 *
 * Under PHP-FPM, Symfony's PhpExecutableFinder does not return PHP_BINARY
 * (the SAPI is not cli), and with a host's open_basedir its `is_executable`
 * probes fail too, so Laravel builds scheduled commands with the bare word
 * `php`. The shell then picks whatever `php` is first on PATH, which on the
 * shared host exits 255. Nothing here checks the file system, because
 * open_basedir makes `file_exists` and `is_executable` answer false for a
 * binary that runs; proc_open is not restricted by it.
 */
final class PhpCli
{
    /** Overrides PHP_SAPI in tests. */
    private static ?string $sapi = null;

    public static function useSapi(?string $sapi): void
    {
        self::$sapi = $sapi;
    }

    public static function binary(): string
    {
        $configured = config('cron.php_binary');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        if (in_array(self::$sapi ?? PHP_SAPI, ['cli', 'phpdbg'], true)) {
            return PHP_BINARY;
        }

        return PHP_BINDIR.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
    }
}
