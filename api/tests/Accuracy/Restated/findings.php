<?php

declare(strict_types=1);

/*
 * Recorded findings: a restated figure that an output shows differently from
 * the engine's own. DATA ONLY: restated case id => one line saying which output,
 * which field, what the engine has and what the output shows.
 *
 * Nothing is adjusted to make a check pass. A listed case is reported as
 * incomplete with that text, and a case listed here that now agrees fails until
 * its entry is removed, as the payroll and attendance findings lists do.
 */

return [
    'GR-TIMESHEET-001 grid' => 'grand total worked_hours: the ledger holds 9050 worked minutes for the three people, 150.8 hours at one decimal; the footer shows 150.9, which is the three rows (34.5, 84.2, 32.2) added after each was rounded.',
    'GR-TIMESHEET-001 xlsx' => 'grand total worked_hours, Summary sheet, Grand total row, Worked hours: the ledger holds 9050 worked minutes, 150.8 hours at one decimal; the cell holds 150.9, the three rows (34.5, 84.2, 32.2) added after each was rounded.',
    'GR-TIMESHEET-001 pdf' => 'grand total worked_hours, Summary table, Grand total line, Worked hours: the ledger holds 9050 worked minutes, 150.8 hours at one decimal; the page prints 150.9, the three rows (34.5, 84.2, 32.2) added after each was rounded.',
];
