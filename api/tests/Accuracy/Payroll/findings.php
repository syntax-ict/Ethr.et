<?php

declare(strict_types=1);

/*
 * Recorded findings: a payroll golden case the engine disagrees with.
 *
 * A hand value is never changed to make a case pass. A disagreement is listed
 * here, case id => one line saying what the engine answered, and the case is
 * then reported as incomplete with that text instead of passing silently or
 * going red. Each entry is triaged as an engine defect, a wrong hand value or
 * a fixture error; once it is resolved the entry is removed, and a case listed
 * here that the engine now agrees with fails until it is.
 */

return [
    'GP-PRO-009' => 'PAST (status confirmed, termination_date 2026-09-20, before the period): days_employed hand 0, engine 31; prorated_basic hand 0, engine 930000; and 9 downstream fields (basic to net). The engine counts a termination date only when it falls inside the period, so a leaver before the period is paid the full month. The oracle agrees with the hand value, the format (spec 1.3, field 5) says termination counts only when inside the period.',
];
