<?php

declare(strict_types=1);

/*
 * Recorded findings: a golden case the engine disagrees with.
 *
 * A hand value is never changed to make a case pass. A disagreement is listed
 * here, case id => one line saying what the engine answered, and the case is
 * then reported as incomplete with that text instead of passing silently or
 * going red. Each entry is triaged as an engine defect, a wrong hand value or
 * a fixture error; once it is resolved the entry is removed, and a case listed
 * here that the engine now agrees with fails until it is.
 */

return [
    // Engine defect, triaged 2026-11-28 (BASELINE 25bq). AttendanceRecord::overtimeWindow()
    // starts at the shift's end whatever the check-in, and PayrollEngine pays from it.
    'GC-OT-010' => 'A second session in at 17:45, out at 20:00 on a shift ending 17:30: the engine counts 150 minutes of normal overtime, from the shift end, where 135 were worked after it.',
    'GC-OT-011' => 'A record in at 18:00, out at 20:00 on a shift ending 17:30: the engine counts 150 minutes of normal overtime, from the shift end, where 120 were worked.',
];
