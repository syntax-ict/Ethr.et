<?php

declare(strict_types=1);

namespace App\Services\Kiosk;

use App\Models\Employee;

/** What KioskEmployeeCode found: one employee, nobody, or more than one. */
final class KioskCodeMatch
{
    public function __construct(
        public readonly ?Employee $employee,
        public readonly bool $ambiguous = false,
    ) {}
}
