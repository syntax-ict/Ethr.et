<?php

declare(strict_types=1);

/*
 * The balance and whether a request is accepted (LVR-BAL-01 to LVR-BAL-08,
 * LVR-REQ-01 to LVR-REQ-05, LVR-ENT-09, LVR-DAYS-04, LVR-DAYS-05). DATA ONLY.
 *
 * The clock stands at 2026-12-01 09:00 UTC (organisation date Tuesday 2026-12-01)
 * unless a case says otherwise. `by` is the actor: employee, hr (an
 * administrator who is not the employee) or hr2 (a second administrator).
 * `refused` is true when the answer is a refusal; `status` is the HTTP status the register's
 * refusals paragraph gives (422 for a date, balance, notice, overlap, gender, not-pending or
 * payroll-period refusal; 403 for refusing someone else's request or one's own approval).
 */

$bal = fn (int $entitled, int $used = 0, int $carried = 0, int $pending = 0, int $year = 2026): array => [
    'year' => $year, 'entitled_tenths' => $entitled, 'used_tenths' => $used, 'carried_tenths' => $carried, 'pending_tenths' => $pending,
];
$fileReq = fn (string $key, string $from, string $to, string $by = 'employee'): array => ['do' => 'file', 'key' => $key, 'from' => $from, 'to' => $to, 'by' => $by];
$on = fn (string $do, string $key, string $by = 'hr'): array => ['do' => $do, 'key' => $key, 'by' => $by];
$pend = fn (string $key, string $from, string $to, int $days, string $status = 'pending', string $by = 'employee'): array => [
    'key' => $key, 'from' => $from, 'to' => $to, 'days' => $days, 'status' => $status, 'filed_by' => $by,
];

$c = fn (string $id, array|string $rule, string $source, array $working, array $balances, array $act, array $expect, array $extra = []): array => [
    'id' => $id,
    'rule' => (array) $rule,
    'source' => $source,
    'working' => $working,
    'balances' => $balances,
    'act' => $act,
    'expect' => $expect,
    ...$extra,
];

return [
    // The balance (LVR-BAL).
    $c('GL-BAL-001', 'LVR-BAL-01', 'register', [
        'entitled 16, carried 3, used 5, pending 2',
        'remaining = 16 + 3 - 5 - 2 = 12 = 120 tenths',
    ], [$bal(160, 50, 30, 20)], [], ['initial' => ['balance' => [2026 => ['remaining_tenths' => 120]]]]),

    $c('GL-BAL-002', ['LVR-BAL-02', 'LVR-DAYS-01'], 'register', [
        'remaining 12 (entitled 16, carried 3, used 5, pending 2)',
        'a 5-day request, Mon 7 to Fri 11 December 2026, is filed: pending 2 + 5 = 7, remaining 12 - 5 = 7',
    ], [$bal(160, 50, 30, 20)], [$fileReq('R1', '2026-12-07', '2026-12-11')], [
        'after' => [['refused' => false, 'requests' => ['R1' => ['days' => 5, 'status' => 'pending']], 'balance' => [2026 => ['pending_tenths' => 70, 'remaining_tenths' => 70]]]],
    ], [
        'requests' => [$pend('R0', '2026-10-05', '2026-10-06', 2)],
    ]),

    $c('GL-BAL-003', 'LVR-BAL-03', 'register', [
        'remaining 4.3 (entitled 4.3): a 4-day request, Mon 7 to Thu 10 December, fits',
        'accepted; pending 4.0, remaining 4.3 - 4 = 0.3',
    ], [$bal(43)], [$fileReq('R1', '2026-12-07', '2026-12-10')], [
        'after' => [['refused' => false, 'requests' => ['R1' => ['days' => 4]], 'balance' => [2026 => ['pending_tenths' => 40, 'remaining_tenths' => 3]]]],
    ]),

    $c('GL-BAL-004', 'LVR-BAL-03', 'register', [
        'remaining 4.3: a 5-day request, Mon 7 to Fri 11 December, does not fit',
        'refused; nothing changes: pending 0, remaining 4.3',
    ], [$bal(43)], [$fileReq('R1', '2026-12-07', '2026-12-11')], [
        'after' => [['refused' => true, 'status' => 422, 'balance' => [2026 => ['pending_tenths' => 0, 'remaining_tenths' => 43]]]],
    ]),

    $c('GL-BAL-005', ['LVR-BAL-03', 'LVR-BAL-04'], 'register', [
        'an unpaid type with 0 days is not limited by the balance: a 5-day request is accepted',
        'pending 5, remaining 0 - 5 = -5 = -50 tenths',
    ], [$bal(0)], [$fileReq('R1', '2026-12-07', '2026-12-11')], [
        'after' => [['refused' => false, 'balance' => [2026 => ['pending_tenths' => 50, 'remaining_tenths' => -50]]]],
    ], ['type' => ['code' => 'acc_unpaid', 'default_days' => 0, 'is_paid' => false]]),

    $c('GL-BAL-006', ['LVR-BAL-05'], 'register', [
        'entitled 16, a pending 5-day request',
        'approval moves 5 from pending to used: pending 0, used 5, remaining 16 - 5 = 11',
    ], [$bal(160, 0, 0, 50)], [$on('approve', 'R1')], [
        'after' => [['refused' => false, 'requests' => ['R1' => ['status' => 'approved']], 'balance' => [2026 => ['pending_tenths' => 0, 'used_tenths' => 50, 'remaining_tenths' => 110]]]],
    ], ['requests' => [$pend('R1', '2026-12-07', '2026-12-11', 5)]]),

    $c('GL-BAL-007', ['LVR-BAL-06'], 'register', [
        'entitled 16, a pending 5-day request is rejected: its days come off pending',
        'pending 0, used 0, remaining 16',
    ], [$bal(160, 0, 0, 50)], [$on('reject', 'R1')], [
        'after' => [['refused' => false, 'requests' => ['R1' => ['status' => 'rejected']], 'balance' => [2026 => ['pending_tenths' => 0, 'used_tenths' => 0, 'remaining_tenths' => 160]]]],
    ], ['requests' => [$pend('R1', '2026-12-07', '2026-12-11', 5)]]),

    $c('GL-BAL-008', ['LVR-BAL-06'], 'register', [
        'the employee cancels their own pending 5-day request: its days come off pending',
        'pending 0, used 0, remaining 16',
    ], [$bal(160, 0, 0, 50)], [$on('cancel', 'R1', 'employee')], [
        'after' => [['refused' => false, 'requests' => ['R1' => ['status' => 'cancelled']], 'balance' => [2026 => ['pending_tenths' => 0, 'used_tenths' => 0, 'remaining_tenths' => 160]]]],
    ], ['requests' => [$pend('R1', '2026-12-07', '2026-12-11', 5)]]),

    $c('GL-BAL-009', ['LVR-BAL-06'], 'register', [
        'the employee cannot cancel an APPROVED request: refused',
        'used stays 5, remaining 11',
    ], [$bal(160, 50)], [$on('cancel', 'R1', 'employee')], [
        'after' => [['refused' => true, 'status' => 422, 'requests' => ['R1' => ['status' => 'approved']], 'balance' => [2026 => ['used_tenths' => 50, 'remaining_tenths' => 110]]]],
    ], ['requests' => [$pend('R1', '2026-12-07', '2026-12-11', 5, 'approved')]]),

    $c('GL-BAL-010', 'LVR-BAL-08', 'register', [
        'an Art. 85 sick type, entitled 30, used 41',
        'the API still returns remaining as LVR-BAL-01 computes it: 30 - 41 = -11 = -110 tenths',
    ], [$bal(300, 410)], [], ['initial' => ['balance' => [2026 => ['remaining_tenths' => -110]]]], [
        'type' => ['code' => 'acc_sick', 'default_days' => 30, 'follows_art85' => true],
    ]),

    $c('GL-BAL-011', 'LVR-BAL-05', 'register', [
        'approval does not check the balance again',
        'entitled 3, a pending 5-day request (filed when more was left): approved, used 5, remaining 3 - 5 = -2',
    ], [$bal(30, 0, 0, 50)], [$on('approve', 'R1')], [
        'after' => [['refused' => false, 'balance' => [2026 => ['pending_tenths' => 0, 'used_tenths' => 50, 'remaining_tenths' => -20]]]],
    ], ['requests' => [$pend('R1', '2026-12-07', '2026-12-11', 5)]]),

    // Whether a request is accepted (LVR-REQ).
    $c('GL-REQ-001', 'LVR-REQ-02', 'register, 06 LR-009', [
        '7 days\' notice, today Tue 2026-12-01: the earliest start is 2026-12-08',
        'an employee asks for Mon 2026-12-07 (6 days from today): refused',
    ], [$bal(160)], [$fileReq('R1', '2026-12-07', '2026-12-07')], [
        'after' => [['refused' => true, 'status' => 422, 'balance' => [2026 => ['pending_tenths' => 0]]]],
    ], ['type' => ['min_notice_days' => 7]]),

    $c('GL-REQ-002', 'LVR-REQ-02', 'register, 06 LR-009', [
        '7 days\' notice, today Tue 2026-12-01: an employee asks for Tue 2026-12-08 (exactly 7 days): accepted',
        '1 working day: pending 1.0',
    ], [$bal(160)], [$fileReq('R1', '2026-12-08', '2026-12-08')], [
        'after' => [['refused' => false, 'balance' => [2026 => ['pending_tenths' => 10]]]],
    ], ['type' => ['min_notice_days' => 7]]),

    $c('GL-REQ-003', 'LVR-REQ-03', 'register, 06 LR-010', [
        'maximum 2 consecutive days; Mon 7 to Wed 9 December is 3 working days: refused',
    ], [$bal(160)], [$fileReq('R1', '2026-12-07', '2026-12-09')], [
        'after' => [['refused' => true, 'status' => 422, 'balance' => [2026 => ['pending_tenths' => 0]]]],
    ], ['type' => ['max_consecutive_days' => 2]]),

    $c('GL-REQ-004', 'LVR-REQ-03', 'register, 06 LR-010', [
        'maximum 2 consecutive days; Mon 7 to Tue 8 December is exactly 2 working days: accepted, pending 2.0',
    ], [$bal(160)], [$fileReq('R1', '2026-12-07', '2026-12-08')], [
        'after' => [['refused' => false, 'balance' => [2026 => ['pending_tenths' => 20]]]],
    ], ['type' => ['max_consecutive_days' => 2]]),

    $c('GL-REQ-005', 'LVR-REQ-04', 'register, 06 LR-007', [
        'R1 is pending Mon 7 to Fri 11 December',
        'a second request Wed 9 to Thu 10 December overlaps it: refused; pending stays 5.0',
    ], [$bal(160, 0, 0, 50)], [$fileReq('R2', '2026-12-09', '2026-12-10')], [
        'after' => [['refused' => true, 'status' => 422, 'balance' => [2026 => ['pending_tenths' => 50]]]],
    ], ['requests' => [$pend('R1', '2026-12-07', '2026-12-11', 5)]]),

    $c('GL-REQ-006', 'LVR-REQ-01', 'register, 06 LR-004', [
        'today is Tue 2026-12-01; an employee asks for Mon 2026-11-30 (yesterday): refused',
    ], [$bal(160)], [$fileReq('R1', '2026-11-30', '2026-11-30')], [
        'after' => [['refused' => true, 'status' => 422, 'balance' => [2026 => ['pending_tenths' => 0]]]],
    ]),

    $c('GL-REQ-007', 'LVR-REQ-01', 'register, 06 LR-017', [
        'HR filing on someone\'s behalf may date it in the past: Mon 2026-11-30 accepted, pending 1.0',
    ], [$bal(160)], [$fileReq('R1', '2026-11-30', '2026-11-30', 'hr')], [
        'after' => [['refused' => false, 'balance' => [2026 => ['pending_tenths' => 10]]]],
    ]),

    $c('GL-REQ-008', 'LVR-REQ-02', 'register, 06 LR-017', [
        '7 days\' notice, today Tue 2026-12-01: HR filing on someone\'s behalf is not held to it',
        'Mon 2026-12-07 accepted, pending 1.0',
    ], [$bal(160)], [$fileReq('R1', '2026-12-07', '2026-12-07', 'hr')], [
        'after' => [['refused' => false, 'balance' => [2026 => ['pending_tenths' => 10]]]],
    ], ['type' => ['min_notice_days' => 7]]),

    $c('GL-REQ-009', 'LVR-ENT-09', 'register, 06 LR-008', [
        'a type restricted to women, a male employee asks for Mon 7 to Fri 11 December: refused',
    ], [$bal(1200)], [$fileReq('R1', '2026-12-07', '2026-12-11')], [
        'after' => [['refused' => true, 'status' => 422, 'balance' => [2026 => ['pending_tenths' => 0]]]],
    ], [
        'type' => ['code' => 'acc_female', 'default_days' => 120, 'gender' => 'female'],
        'employee' => ['gender' => 'male'],
    ]),

    $c('GL-REQ-010', 'LVR-ENT-09', 'register', [
        'a type restricted to women, an employee with no gender recorded: refused',
    ], [$bal(1200)], [$fileReq('R1', '2026-12-07', '2026-12-11')], [
        'after' => [['refused' => true, 'status' => 422, 'balance' => [2026 => ['pending_tenths' => 0]]]],
    ], [
        'type' => ['code' => 'acc_female', 'default_days' => 120, 'gender' => 'female'],
        'employee' => ['gender' => null],
    ]),

    $c('GL-REQ-011', 'LVR-REQ-05', 'register, 06 LD-003', [
        'HR files a 5-day request on the employee\'s behalf; the same HR may not approve it: refused',
        'still pending 5.0, used 0',
    ], [$bal(160, 0, 0, 50)], [$on('approve', 'R1', 'hr')], [
        'after' => [['refused' => true, 'status' => 403, 'requests' => ['R1' => ['status' => 'pending']], 'balance' => [2026 => ['pending_tenths' => 50, 'used_tenths' => 0]]]],
    ], ['requests' => [$pend('R1', '2026-12-07', '2026-12-11', 5, 'pending', 'hr')]]),

    $c('GL-REQ-012', ['LVR-DAYS-04', 'LVR-DAYS-01'], 'register, 06 LR-003', [
        'Sat 2026-12-12 to Sun 2026-12-13 on Monday to Friday: no charged day: refused',
    ], [$bal(160)], [$fileReq('R1', '2026-12-12', '2026-12-13')], [
        'after' => [['refused' => true, 'status' => 422, 'balance' => [2026 => ['pending_tenths' => 0]]]],
    ]),

    $c('GL-REQ-013', 'LVR-REQ-05', 'register, 06 LD-003', [
        'the same request filed by HR may be approved by a second administrator: accepted',
        'pending 0, used 5.0, remaining 11.0',
    ], [$bal(160, 0, 0, 50)], [$on('approve', 'R1', 'hr2')], [
        'after' => [['refused' => false, 'requests' => ['R1' => ['status' => 'approved']], 'balance' => [2026 => ['pending_tenths' => 0, 'used_tenths' => 50, 'remaining_tenths' => 110]]]],
    ], ['requests' => [$pend('R1', '2026-12-07', '2026-12-11', 5, 'pending', 'hr')]]),

    $c('GL-REQ-014', 'LVR-DAYS-05', 'register (reading A, the code)', [
        'Gregorian year, Mon 2026-12-28 to Fri 2027-01-08: 10 charged days (4 in 2026 and 6 in 2027)',
        'reading A (the code): all 10 are charged to the year it starts in, 2026: pending 10.0 in 2026, 0 in 2027',
        'reading B (AZ E-04): 4 and 6. Reading C (AZ U-E5): refused',
    ], [$bal(160), $bal(160, 0, 0, 0, 2027)], [$fileReq('R1', '2026-12-28', '2027-01-08')], [
        'after' => [['refused' => false, 'requests' => ['R1' => ['days' => 10]], 'balance' => [2026 => ['pending_tenths' => 100], 2027 => ['pending_tenths' => 0]]]],
    ], ['undecided' => true]),

    $c('GL-REQ-015', ['LVR-OTHER-01', 'LVR-DAYS-02'], 'register, waiting item 2', [
        'maternity of 120 days, a female employee, 14 consecutive days Mon 7 to Sun 20 December filed by HR',
        'stored 14; pending 14.0, remaining 120 - 14 = 106 = 1060 tenths (the 120 is LVR-OTHER-02, item 2)',
    ], [$bal(1200)], [$fileReq('R1', '2026-12-07', '2026-12-20', 'hr')], [
        'after' => [['refused' => false, 'requests' => ['R1' => ['days' => 14]], 'balance' => [2026 => ['pending_tenths' => 140, 'remaining_tenths' => 1060]]]],
    ], [
        'type' => ['code' => 'maternity', 'default_days' => 120, 'counts_calendar_days' => true, 'gender' => 'female'],
        'employee' => ['gender' => 'female'],
        'signed' => null, 'waits_on' => '2',
    ]),

    $c('GL-REQ-016', 'LVR-REQ-01', 'register', [
        'an employee asks for today, Tue 2026-12-01 (on or after today): accepted, pending 1.0',
    ], [$bal(160)], [$fileReq('R1', '2026-12-01', '2026-12-01')], [
        'after' => [['refused' => false, 'balance' => [2026 => ['pending_tenths' => 10]]]],
    ]),

    // What follows from the holiday rows existing only from their roll-forward (LVR-DAYS-08 to LVR-DAYS-10).
    $c('GL-REQ-017', ['LVR-DAYS-10', 'LVR-DAYS-08'], 'register', [
        'an own recurring holiday for 24 December, created 25 November 2026 (row 2026-12-24)',
        'filed on 28 November 2026, before the 1 December run: Friday 2027-12-24 has no row, so the one-day request charges 1',
    ], [$bal(160, 0, 0, 0, 2027)], [['at' => '2026-11-28 09:00', ...$fileReq('R1', '2027-12-24', '2027-12-24')]], [
        'after' => [['refused' => false, 'requests' => ['R1' => ['days' => 1]], 'balance' => [2027 => ['pending_tenths' => 10]]]],
    ], ['org' => ['holidays' => [['date' => '2026-12-24', 'recurring' => true]]]]),

    $c('GL-REQ-018', ['LVR-DAYS-10', 'LVR-DAYS-09', 'LVR-DAYS-04'], 'register', [
        'the same holiday; the 1 December 2026 01:30 run copies it to Friday 2027-12-24',
        'filed after the run, the one-day request charges nothing and is refused (422, LVR-DAYS-04); pending stays 0',
    ], [$bal(160, 0, 0, 0, 2027)], [
        ['do' => 'roll_forward', 'at' => '2026-12-01 01:30'],
        ['at' => '2026-12-01 09:00', ...$fileReq('R1', '2027-12-24', '2027-12-24')],
    ], [
        'after' => [null, ['refused' => true, 'status' => 422, 'balance' => [2027 => ['pending_tenths' => 0]]]],
    ], ['org' => ['holidays' => [['date' => '2026-12-24', 'recurring' => true]]]]),

    $c('GL-REQ-019', 'LVR-DAYS-10', 'register', [
        'filed 15 December 2026, Mon 2028-05-01 to Fri 2028-05-05: 2028 has no holiday rows yet, so 5 are charged',
    ], [$bal(160, 0, 0, 0, 2028)], [['at' => '2026-12-15 09:00', ...$fileReq('R1', '2028-05-01', '2028-05-05')]], [
        'after' => [['refused' => false, 'requests' => ['R1' => ['days' => 5]], 'balance' => [2028 => ['pending_tenths' => 50]]]],
    ]),

    $c('GL-REQ-020', ['LVR-DAYS-10', 'LVR-DAYS-09'], 'register', [
        "first typed as 4 from the register's example, which named one holiday; the register was corrected to name both",
        "LVR-DAYS-10 (b): the 1 January 2027 01:30 run creates 2028's rows: Labour Day on Mon 1 May and Patriots Day on Fri 5 May",
        'filed after it, the same Mon 2028-05-01 to Fri 2028-05-05 charges 5 - 2 = 3',
    ], [$bal(160, 0, 0, 0, 2028)], [
        ['do' => 'roll_forward', 'at' => '2027-01-01 01:30'],
        ['at' => '2027-01-02 09:00', ...$fileReq('R1', '2028-05-01', '2028-05-05')],
    ], [
        'after' => [null, ['refused' => false, 'requests' => ['R1' => ['days' => 3]], 'balance' => [2028 => ['pending_tenths' => 30]]]],
    ]),

    $c('GL-REQ-021', ['LVR-DAYS-10', 'LVR-DAYS-06'], 'register', [
        'request (b) filed 15 December 2026 stores 5; the 1 January 2027 run then creates Labour Day\'s row',
        'the stored count is never recounted: R1 stays 5 days and pending stays 5.0',
    ], [$bal(160, 0, 0, 0, 2028)], [
        ['at' => '2026-12-15 09:00', ...$fileReq('R1', '2028-05-01', '2028-05-05')],
        ['do' => 'roll_forward', 'at' => '2027-01-01 01:30'],
    ], [
        'after' => [
            ['refused' => false, 'requests' => ['R1' => ['days' => 5]], 'balance' => [2028 => ['pending_tenths' => 50]]],
            ['requests' => ['R1' => ['days' => 5]], 'balance' => [2028 => ['pending_tenths' => 50]]],
        ],
    ]),
];
