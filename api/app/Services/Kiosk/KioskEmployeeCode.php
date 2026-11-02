<?php

declare(strict_types=1);

namespace App\Services\Kiosk;

use App\Models\Employee;
use App\Models\Tenant;
use App\Services\EmployeeNumbering;

/**
 * The employee a code typed at the kiosk means.
 *
 * The kiosk pad has only digits, and most codes have letters (`EMP-0012`),
 * which meant the tablet's own keyboard for every punch. A bare number now
 * stands for the full code in the organisation's format (EmployeeNumbering):
 * `12` finds `EMP-0012`. The exact code is still tried, so a code outside the
 * format, typed in full, works as before.
 *
 * When the number is itself someone's code and also stands for someone else's
 * (an imported `12` beside `EMP-0012`), it is refused as ambiguous rather than
 * given to either: a punch for the wrong person is the one outcome a kiosk
 * must not produce silently.
 *
 * Tenant-scoped through BelongsToTenant: the kiosk token made the session's
 * tenant current before any of this runs.
 */
final class KioskEmployeeCode
{
    public function __construct(private readonly EmployeeNumbering $numbering) {}

    public function find(?Tenant $tenant, string $typed): KioskCodeMatch
    {
        $typed = trim($typed);
        if ($typed === '') {
            return new KioskCodeMatch(null);
        }

        $full = $tenant === null ? null : $this->numbering->fromNumber($tenant, $typed);
        $codes = array_values(array_unique(array_filter([$typed, $full])));

        $found = Employee::query()->whereIn('employee_code', $codes)->limit(2)->get();

        if ($found->count() > 1) {
            return new KioskCodeMatch(null, ambiguous: true);
        }

        return new KioskCodeMatch($found->first());
    }
}
