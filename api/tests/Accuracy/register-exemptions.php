<?php

declare(strict_types=1);

/*
 * Decided rules that no case file cites, and why. DATA ONLY: rule id => reason.
 * RegisterConsistencyTest fails on a Decided rule that has neither a case nor an
 * entry here, and on an entry whose rule is not Decided, is not in its register,
 * or is now cited by a case (delete it then).
 * Two kinds of reason appear: a known gap (a case should be written and has not
 * been; 12-ATTENDANCE-ACCURACY.md section 8 lists these), and a rule a figure
 * cannot express, with the place that does cover it.
 */

return [
    // Attendance: known gaps, listed in 12 section 8 as having no golden case yet.
    'AR-CLOCK-03' => 'Pinned in TypeScript only: the reading of a time (08:40 is 2:40, 17:35 is 11:35) exists only in src/src/lib/calendar/ethiopian-time.ts and is tested by src/src/test/ethiopian-clock-roundtrip.test.ts. No golden case cites it; 12 section 8 lists it as having none.',
    'AR-CLOCK-05' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet.',
    'AR-SHIFT-01' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet.',
    'AR-SHIFT-02' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet.',
    'AR-SHIFT-03' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet.',
    'AR-SHIFT-04' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet.',
    'AR-STATUS-02' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet.',
    'AR-BREAK-04' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet.',
    'AR-BREAK-05' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet.',
    'AR-NIGHT-03' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet.',
    'AR-AUTO-03' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet.',
    'AR-ABS-06' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet. It is a payroll figure: the payroll cases are where a case for it belongs.',
    'AR-OT-04' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet. It is a payroll figure: the payroll cases are where a case for it belongs.',
    'AR-OT-05' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet. It is a payroll figure: the payroll cases are where a case for it belongs.',
    'AR-OT-06' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet. It is a payroll figure: the payroll cases are where a case for it belongs.',
    'AR-OT-07' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet.',
    'AR-LATE-01' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet. It is a payroll figure: the payroll cases are where a case for it belongs.',
    'AR-LATE-02' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet. It is a payroll figure: the payroll cases are where a case for it belongs.',
    'AR-LATE-03' => 'Known gap: docs/production testing/12-ATTENDANCE-ACCURACY.md section 8 lists this rule as having no golden case yet. It is a payroll figure: the payroll cases are where a case for it belongs.',

    // Payroll: not figures.
    'PYR-PER-02' => 'A refusal, not a figure. Feature tests cover the overlap refusal.',
    'PYR-LEAVE-04' => 'A refusal, not a figure. Feature tests cover it.',
    'PYR-SICK-04' => 'A refusal, not a figure. Feature tests cover it.',
    'PYR-CS-02' => 'A refusal, not a figure. Feature tests cover it.',
    'PYR-BANK-01' => 'A refusal, not a figure. Feature tests cover it.',
    'PYR-RUN-02' => 'A refusal, not a figure. Feature tests cover it.',
    'PYR-RUN-04' => 'Run lifecycle, not a figure. Feature tests cover the states.',
    'PYR-RUN-05' => 'Run lifecycle, not a figure. Feature tests cover the states.',
    'PYR-RUN-06' => 'Run lifecycle, not a figure. Feature tests cover the states.',
    'PYR-PER-05' => 'A stored label, not a figure.',
    'PYR-TAX-05' => 'An organisation own tax ladder; TaxLadderSourcesTest covers it, not a golden figure.',
    'PYR-ABS-01' => 'Absences are listed only and no money moves; the AR-ABS cases cover what is listed.',
    'PYR-OT-05' => 'Flags only, no figure.',

    // Leave: Decided rules that are not computations a golden case can pin.
    'LVR-YEAR-03' => 'A settings refusal (the leave year cannot change once leave is recorded), not a figure. 06 LB-009 and LB-011 and the Feature tests cover it.',
    'LVR-ENT-05' => 'A refusal on saving a leave type (the statutory floor on default_days), not a figure. 06 LT-006 and the Feature tests cover it.',
    'LVR-CARRY-02' => 'A setting the setup wizard writes (annual carries forward, capped at 16), not a computation. The carry arithmetic is covered by the GL-CARRY cases.',
    'LVR-DAYS-07' => 'The attendance side of a leave day (how attendance reads an approved leave day), covered by the attendance cases AR-ABS-01, not by a leave figure.',
    'LVR-BAL-07' => 'Concurrency: two requests filed at the same moment need separate processes, which a golden case in one process cannot make. Covered by 06 LR-022 and gates.sh concurrency.',
    'LVR-BAL-09' => 'The opening-balance import: an endpoint with its own validation and file handling, not a leave computation. Covered by 06 LB-005, LB-006 and the import Feature tests.',
    'LVR-REQ-06' => 'A refusal that needs an approved payroll run to exist; not a leave figure. Covered by LeaveInPaidPeriodTest and 07 PAY-038, SICK-004.',
    'LVR-RECALL-03' => 'Refusals (own leave; a day given back in an approved payroll period), not figures. Covered by LeaveRecallTest and 06 LD-012, LD-014.',
    'LVR-OTHER-06' => 'A statement that study, sabbatical, injury and rest-and-recuperation days are employer defaults to edit, not law and not a computation.',
    'LVR-OTHER-07' => 'Which balance cards list a duty or excused type: a read of the screen, not a computation. 06 LT-005 covers it.',
    'LVR-PAY-03' => 'Art. 85 payroll deduction figures wait on the signed tiers, item 4 (LVR-SICK-07); the figure belongs in the payroll cases (07 SICK-002), which read the same tiers.',
];
