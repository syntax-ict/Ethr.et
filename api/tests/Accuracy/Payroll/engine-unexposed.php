<?php

declare(strict_types=1);

/*
 * Fields a case may state that the engine's entry and calculation_log do not
 * carry, so they cannot be compared against the engine. DATA ONLY:
 * field name (fnmatch pattern allowed) => why the engine does not carry it.
 *
 * Every use is counted and printed by PayrollGoldenCasesTest. A field the
 * EngineReader does answer must not be listed: the golden test fails on it.
 * The oracle and the hand value still carry these fields.
 */

return [
    'days_in_period' => 'The engine works the count out inside computeEntry() ($totalPeriodDays) and logs only the period dates and a proration factor, not the day count.',
    'days_employed' => 'The engine logs only a proration factor (a float, 1.0 for a full period) and the hire and termination dates, not the days worked.',
];
