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
    // Engine defect, triaged 2026-11-28 (BASELINE 25bt). AttendanceEngine::lateMinutes()
    // and earlyMinutes() rate a record against its shift whatever the day, and the
    // status is `late`; AttendanceReconciliation lists it as a late arrival with
    // missedMinutes(), so lateness can be deducted for work on a day off.
    'GC-REST-001' => 'S1 worked 09:00 to 13:00 on a rest day (Saturday): the record says 30 minutes late and 270 early (300 missed), where the day has no scheduled hours.',
    'GC-REST-002' => 'S1 worked 09:00 to 13:00 on a public holiday of the branch: the record says 30 minutes late and 270 early (300 missed), where the day has no scheduled hours.',
];
