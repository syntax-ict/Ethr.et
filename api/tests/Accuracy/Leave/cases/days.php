<?php

declare(strict_types=1);

/*
 * Counting the days a request charges (LVR-DAYS-01 to LVR-DAYS-04). DATA ONLY.
 *
 * Every expected value was worked by hand from docs/LEAVE-RULES.md before the
 * engine was asked. Defaults (a Monday to Friday week, no holidays, an annual
 * type counted in working days, hired 2020-01-01, the employee's own branch)
 * are in LeaveCases::DEFAULTS.
 */

$d = fn (string $id, array|string $rule, string $source, array $span, array $working, array $expect, array $extra = []): array => [
    'id' => $id,
    'rule' => (array) $rule,
    'source' => $source,
    'working' => $working,
    'span' => $span,
    'expect' => $expect,
    ...$extra,
];

return [
    // The register's own worked example.
    $d('GL-DAYS-001', ['LVR-DAYS-01', 'LVR-DAYS-03'], 'register', ['2025-01-06', '2025-01-10'], [
        'Mon 6 to Fri 10 January 2025: 5 weekdays',
        'holiday on Wed 8 January: 5 - 1 = 4',
        'LVR-DAYS-03: no half days, every charged day counts 1, so the count is a whole number',
    ], ['days' => 4, 'dates' => ['2025-01-06', '2025-01-07', '2025-01-09', '2025-01-10']], [
        'org' => ['holidays' => [['date' => '2025-01-08']]],
    ]),
    $d('GL-DAYS-002', 'LVR-DAYS-01', 'register', ['2025-01-06', '2025-01-10'], [
        'holidays: Genna Tue 7 January and Wed 8 January',
        '5 weekdays - 2 = 3',
    ], ['days' => 3, 'dates' => ['2025-01-06', '2025-01-09', '2025-01-10']], [
        'org' => ['holidays' => [['date' => '2025-01-07'], ['date' => '2025-01-08']]],
    ]),
    $d('GL-DAYS-003', 'LVR-DAYS-01', 'register', ['2025-01-06', '2025-01-11'], [
        'Mon 6 to Sat 11 January 2025 on a Monday to Saturday shift: 6 scheduled days, no holiday = 6',
    ], ['days' => 6, 'dates' => ['2025-01-06', '2025-01-07', '2025-01-08', '2025-01-09', '2025-01-10', '2025-01-11']], [
        'employee' => ['shift' => 'S6'],
    ]),
    $d('GL-DAYS-004', 'LVR-DAYS-01', 'register', ['2025-01-06', '2025-01-11'], [
        'Mon 6 to Sat 11 January 2025 on the organisation week Monday to Friday: 5, Saturday is a rest day',
    ], ['days' => 5, 'dates' => ['2025-01-06', '2025-01-07', '2025-01-08', '2025-01-09', '2025-01-10']]),
    $d('GL-DAYS-005', ['LVR-DAYS-01'], 'register', ['2026-06-01', '2026-06-07'], [
        'rotation work, work, rest, rest anchored Mon 2026-06-01, cycle 4',
        'step of a day = distance from the anchor mod 4: 1 June 0, 2 June 1, 3 June 2, 4 June 3, 5 June 0, 6 June 1, 7 June 2',
        'worked steps (0, 1): 1, 2, 5 and 6 June = 4 days (6 June is a Saturday and is charged: the rotation governs, not the weekday)',
    ], ['days' => 4, 'dates' => ['2026-06-01', '2026-06-02', '2026-06-05', '2026-06-06']], [
        'employee' => ['rotation' => ['anchor' => '2026-06-01', 'steps' => ['S1', 'S1', null, null]]],
    ]),
    $d('GL-DAYS-006', ['LVR-DAYS-01', 'LVR-DAYS-04'], 'register', ['2025-01-11', '2025-01-12'], [
        'Sat 11 and Sun 12 January 2025 on Monday to Friday: 0 scheduled days',
        '0 charged days; LVR-DAYS-04 refuses such a request (GL-REQ-012)',
    ], ['days' => 0, 'dates' => []]),
    $d('GL-DAYS-007', 'LVR-DAYS-02', 'register', ['2025-01-04', '2025-01-17'], [
        'calendar-days type: every day of the span, rest days and holidays included',
        'Sat 4 to Fri 17 January 2025 = 14 days (the holiday on Wed 8 January is still charged)',
    ], ['days' => 14, 'dates' => [
        '2025-01-04', '2025-01-05', '2025-01-06', '2025-01-07', '2025-01-08', '2025-01-09', '2025-01-10',
        '2025-01-11', '2025-01-12', '2025-01-13', '2025-01-14', '2025-01-15', '2025-01-16', '2025-01-17',
    ]], [
        'org' => ['holidays' => [['date' => '2025-01-08']]],
        'type' => ['code' => 'acc_calendar', 'default_days' => 120, 'counts_calendar_days' => true],
    ]),
    $d('GL-DAYS-008', 'LVR-DAYS-01', 'register', ['2025-01-06', '2025-01-10'], [
        'a holiday of another branch (Wed 8 January) does not touch this person',
        '5 weekdays, no exclusion = 5',
    ], ['days' => 5, 'dates' => ['2025-01-06', '2025-01-07', '2025-01-08', '2025-01-09', '2025-01-10']], [
        'org' => ['holidays' => [['date' => '2025-01-08', 'branch' => 'other']]],
    ]),
    $d('GL-DAYS-009', 'LVR-DAYS-01', 'register', ['2025-01-06', '2025-01-10'], [
        'a holiday of the person\'s own branch (Wed 8 January) is excluded: 5 - 1 = 4',
    ], ['days' => 4, 'dates' => ['2025-01-06', '2025-01-07', '2025-01-09', '2025-01-10']], [
        'org' => ['holidays' => [['date' => '2025-01-08', 'branch' => 'own']]],
    ]),
    $d('GL-DAYS-010', 'LVR-DAYS-01', 'register', ['2025-01-06', '2025-01-12'], [
        'Mon 6 to Sun 12 January, Monday to Friday: 5 scheduled days',
        'a holiday on Sat 11 January is not a scheduled day, so nothing is subtracted: 5',
    ], ['days' => 5, 'dates' => ['2025-01-06', '2025-01-07', '2025-01-08', '2025-01-09', '2025-01-10']], [
        'org' => ['holidays' => [['date' => '2025-01-11']]],
    ]),
    $d('GL-DAYS-011', 'LVR-DAYS-01', 'register', ['2025-01-06', '2025-01-11'], [
        'the day is resolved by the assignment that governs it (AR-SHIFT-01, AR-SHIFT-02)',
        'Mon 6 and Tue 7 January on S1 (Monday to Friday): 2 days',
        'from Wed 8 January on S36 (Wednesday to Saturday): Wed 8, Thu 9, Fri 10, Sat 11 = 4 days',
        'total 6 (S1 alone would give 5, S36 alone 4)',
    ], ['days' => 6, 'dates' => ['2025-01-06', '2025-01-07', '2025-01-08', '2025-01-09', '2025-01-10', '2025-01-11']], [
        'employee' => ['assignments' => [
            ['shift' => 'S1', 'from' => '2024-01-01', 'to' => '2025-01-07'],
            ['shift' => 'S36', 'from' => '2025-01-08'],
        ]],
    ]),
    $d('GL-DAYS-012', 'LVR-DAYS-01', 'register', ['2025-01-06', '2025-01-10'], [
        'only ACTIVE holidays are excluded: an inactive holiday on Wed 8 January removes nothing',
        '5 weekdays = 5',
    ], ['days' => 5, 'dates' => ['2025-01-06', '2025-01-07', '2025-01-08', '2025-01-09', '2025-01-10']], [
        'org' => ['holidays' => [['date' => '2025-01-08', 'active' => false]]],
    ]),
    $d('GL-DAYS-013', 'LVR-DAYS-08', 'register points 13, then LVR-DAYS-08', ['2026-01-05', '2026-01-09'], [
        'first typed as 4 on the writer\'s own reading of "recurring"; the register then stated the rule, LVR-DAYS-08: that typing is withdrawn, not a finding',
        'LVR-DAYS-08: a holiday lowers the days charged only where an active row exists for that exact date; "recurring" is not read when days are counted',
        'a holiday recorded as recurring on 7 January 2025, with no row for 7 January 2026: Mon 5 to Fri 9 January 2026 stays at 5 days',
    ], ['days' => 5, 'dates' => ['2026-01-05', '2026-01-06', '2026-01-07', '2026-01-08', '2026-01-09']], [
        'org' => ['holidays' => [['date' => '2025-01-07', 'recurring' => true]]],
    ]),
    $d('GL-DAYS-019', 'LVR-DAYS-08', 'register', ['2026-01-05', '2026-01-09'], [
        'the same holiday with its 2026 row (7 January 2026, a Wednesday): 5 - 1 = 4',
    ], ['days' => 4, 'dates' => ['2026-01-05', '2026-01-06', '2026-01-08', '2026-01-09']], [
        'org' => ['holidays' => [['date' => '2025-01-07', 'recurring' => true], ['date' => '2026-01-07', 'recurring' => true]]],
    ]),
    $d('GL-DAYS-014', 'LVR-DAYS-01', 'register', ['2025-01-06', '2025-01-10'], [
        'holidays on the first and the last day of the span (Mon 6, Fri 10): 5 - 2 = 3',
    ], ['days' => 3, 'dates' => ['2025-01-07', '2025-01-08', '2025-01-09']], [
        'org' => ['holidays' => [['date' => '2025-01-06'], ['date' => '2025-01-10']]],
    ]),
    // Pack case 06 LR-002: the register disagrees with the pack's "3 days".
    $d('GL-DAYS-015', 'LVR-DAYS-01', '06 LR-002 (the register overrules the pack)', ['2025-01-03', '2025-01-07'], [
        'Fri 3 to Tue 7 January 2025, Monday 6 January a public holiday, Monday to Friday',
        'Fri 3 January: 1; Sat 4, Sun 5: rest; Mon 6: holiday; Tue 7: 1 = 2 (the pack says 3)',
    ], ['days' => 2, 'dates' => ['2025-01-03', '2025-01-07']], [
        'org' => ['holidays' => [['date' => '2025-01-06']]],
    ]),
    $d('GL-DAYS-016', 'LVR-DAYS-01', '06 LR-002 (the register: 3 only if Saturday works)', ['2025-01-03', '2025-01-07'], [
        'same span on a Monday to Saturday shift, Monday 6 January a holiday',
        'Fri 3, Sat 4, Tue 7 = 3 (the pack\'s figure is right only for such a person)',
    ], ['days' => 3, 'dates' => ['2025-01-03', '2025-01-04', '2025-01-07']], [
        'org' => ['holidays' => [['date' => '2025-01-06']]],
        'employee' => ['shift' => 'S6'],
    ]),

    // The statutory point inside the counting rule: held until it is signed.
    $d('GL-DAYS-017', ['LVR-DAYS-01', 'LVR-ENT-02'], 'register, waiting item 2', ['2026-12-28', '2027-01-08'], [
        'annual leave Mon 2026-12-28 to Fri 2027-01-08, no holiday: 10 weekdays',
        'reading A (the code): 10 charged. Reading B (counted in calendar days): 12',
    ], ['days' => 10, 'dates' => [
        '2026-12-28', '2026-12-29', '2026-12-30', '2026-12-31', '2027-01-01',
        '2027-01-04', '2027-01-05', '2027-01-06', '2027-01-07', '2027-01-08',
    ]], ['type' => ['code' => 'annual'], 'signed' => null, 'waits_on' => '2', 'readings' => ['A' => ['days' => 10], 'B' => ['days' => 12]]]),
    $d('GL-DAYS-018', ['LVR-DAYS-01'], 'register, waiting item 20', ['2027-03-01', '2027-03-05'], [
        'annual leave Mon 2027-03-01 to Fri 2027-03-05, Adwa recorded on Tue 2 March',
        'reading A (the code): 5 - 1 = 4. Reading B: the holiday is charged, 5',
    ], ['days' => 4, 'dates' => ['2027-03-01', '2027-03-03', '2027-03-04', '2027-03-05']], [
        'org' => ['holidays' => [['date' => '2027-03-02']]],
        'type' => ['code' => 'annual'],
        'signed' => null, 'waits_on' => '20',
        'readings' => ['A' => ['days' => 4], 'B' => ['days' => 5]],
    ]),
    $d('GL-DAYS-020', 'LVR-DAYS-09', 'register', ['2027-06-07', '2027-06-11'], [
        'an own holiday marked recurring with a 2026 row (Wed 10 June 2026) is copied by the 1 December 2026 run to Thu 10 June 2027',
        'Mon 7 to Fri 11 June 2027: 5 - 1 = 4',
    ], ['days' => 4, 'dates' => ['2027-06-07', '2027-06-08', '2027-06-09', '2027-06-11']], [
        'org' => ['holidays' => [['date' => '2026-06-10', 'recurring' => true]]],
        'act' => [['do' => 'roll_forward', 'at' => '2026-12-01 01:30']],
    ]),
    $d('GL-DAYS-021', 'LVR-DAYS-09', 'register', ['2027-06-07', '2027-06-11'], [
        'only the year before is copied: an own recurring holiday with a 2025 row only reaches 2027 through a 2026 row, and there is none',
        "the 1 December 2026 run creates 2027's rows; Mon 7 to Fri 11 June 2027 has no row on the 10th: 5",
    ], ['days' => 5, 'dates' => ['2027-06-07', '2027-06-08', '2027-06-09', '2027-06-10', '2027-06-11']], [
        'org' => ['holidays' => [['date' => '2025-06-10', 'recurring' => true]]],
        'act' => [['do' => 'roll_forward', 'at' => '2026-12-01 01:30']],
    ]),
    $d('GL-DAYS-022', 'LVR-DAYS-09', 'register', ['2026-06-08', '2026-06-12'], [
        "the run creates next calendar year's rows, never the current year: a 2025 recurring row, run on 1 December 2026, leaves 2026 as it was",
        'Mon 8 to Fri 12 June 2026: 5',
    ], ['days' => 5, 'dates' => ['2026-06-08', '2026-06-09', '2026-06-10', '2026-06-11', '2026-06-12']], [
        'org' => ['holidays' => [['date' => '2025-06-10', 'recurring' => true]]],
        'act' => [['do' => 'roll_forward', 'at' => '2026-12-01 01:30']],
    ]),
    $d('GL-DAYS-023', 'LVR-DAYS-09', 'register', ['2027-06-07', '2027-06-11'], [
        'a repeat changes nothing: the run is made twice on 1 December 2026 (Wed 10 June 2026, recurring)',
        'Mon 7 to Fri 11 June 2027: 4 after both runs',
    ], ['days' => 4, 'dates' => ['2027-06-07', '2027-06-08', '2027-06-09', '2027-06-11']], [
        'org' => ['holidays' => [['date' => '2026-06-10', 'recurring' => true]]],
        'act' => [['do' => 'roll_forward', 'at' => '2026-12-01 01:30'], ['do' => 'roll_forward', 'at' => '2026-12-01 01:30']],
    ]),
    $d('GL-DAYS-024', 'LVR-DAYS-09', 'register', ['2027-06-07', '2027-06-11'], [
        'a holiday not marked recurring is not copied (Wed 10 June 2026)',
        'Mon 7 to Fri 11 June 2027: 5',
    ], ['days' => 5, 'dates' => ['2027-06-07', '2027-06-08', '2027-06-09', '2027-06-10', '2027-06-11']], [
        'org' => ['holidays' => [['date' => '2026-06-10', 'recurring' => false]]],
        'act' => [['do' => 'roll_forward', 'at' => '2026-12-01 01:30']],
    ]),
    $d('GL-DAYS-025', 'LVR-DAYS-09', 'register', ['2027-06-07', '2027-06-11'], [
        'an own holiday on the Ethiopian calendar is not copied (Wed 10 June 2026, recurring)',
        'Mon 7 to Fri 11 June 2027: 5',
    ], ['days' => 5, 'dates' => ['2027-06-07', '2027-06-08', '2027-06-09', '2027-06-10', '2027-06-11']], [
        'org' => ['holidays' => [['date' => '2026-06-10', 'recurring' => true, 'ethiopian' => true]]],
        'act' => [['do' => 'roll_forward', 'at' => '2026-12-01 01:30']],
    ]),
    $d('GL-DAYS-026', 'LVR-DAYS-09', 'register', ['2027-06-07', '2027-06-11'], [
        "a copy keeps its branch: the other branch's recurring holiday (Wed 10 June 2026) is copied for that branch only",
        'Mon 7 to Fri 11 June 2027 for a person of the own branch: 5',
    ], ['days' => 5, 'dates' => ['2027-06-07', '2027-06-08', '2027-06-09', '2027-06-10', '2027-06-11']], [
        'org' => ['holidays' => [['date' => '2026-06-10', 'recurring' => true, 'branch' => 'other']]],
        'act' => [['do' => 'roll_forward', 'at' => '2026-12-01 01:30']],
    ]),
    $d('GL-DAYS-027', 'LVR-DAYS-09', 'register', ['2027-06-07', '2027-06-11'], [
        "a copy keeps its branch: the person's own branch holiday (Wed 10 June 2026, recurring) is copied for that branch",
        'Mon 7 to Fri 11 June 2027: 5 - 1 = 4',
    ], ['days' => 4, 'dates' => ['2027-06-07', '2027-06-08', '2027-06-09', '2027-06-11']], [
        'org' => ['holidays' => [['date' => '2026-06-10', 'recurring' => true, 'branch' => 'own']]],
        'act' => [['do' => 'roll_forward', 'at' => '2026-12-01 01:30']],
    ]),
];
