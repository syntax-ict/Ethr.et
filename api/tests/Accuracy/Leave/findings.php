<?php

declare(strict_types=1);

/*
 * Recorded findings for the leave golden cases: a case the engine disagrees
 * with. A hand value is never changed to make a case pass. A disagreement is
 * listed here, case id => one line saying what the engine answered, and the
 * case is then reported as incomplete with that text. Each entry is triaged
 * as an engine defect, a wrong hand value or a fixture error; once resolved
 * the entry is removed, and a case listed here that the engine now agrees
 * with fails until it is.
 */

return [
    'GL-DAYS-013' => 'a holiday recorded as recurring is matched on its exact date only: LeaveDayCalculator::chargedDates() charged Wed 2026-01-07, so Mon 5 to Fri 9 January 2026 came to 5 days, not 4 (register points 13)',
];
