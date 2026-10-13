<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether the `mock` device adapter may be used on this server.
 *
 * Only in development and tests: the adapter fabricates attendance for real
 * employees, which on any other environment is data that payroll will pay
 * (attendance QA A3).
 */
final class DeviceSimulator
{
    public static function available(): bool
    {
        return app()->environment(['local', 'testing']);
    }
}
