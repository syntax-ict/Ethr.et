<?php

declare(strict_types=1);

/*
 * The leave year (LVR-YEAR-01, LVR-YEAR-02, LVR-YEAR-04) and the unpaid days
 * payroll counts (LVR-PAY-02, LVR-DAYS-06). DATA ONLY.
 *
 * Ethiopian year 2018 begins 2025-09-11 and 2019 begins 2026-09-11, Pagume of
 * 2018 is 6 to 10 September 2026 (5 days), Pagume of 2019 is 6 to 11 September
 * 2027 (6 days), year 2020 begins 2027-09-12 (register, "The Ethiopian
 * months"). The entitlement date of a month is its day 30, of month 12 the last
 * day of Pagume.
 */

$y = fn (string $id, array|string $rule, string $source, string $kind, string $date, array $working, array $expect): array => [
    'id' => $id,
    'rule' => (array) $rule,
    'source' => $source,
    'working' => $working,
    'org' => ['leave_year' => $kind],
    'date' => $date,
    'expect' => $expect,
];

$p = fn (string $id, array|string $rule, string $source, array $period, array $working, array $requests, int $thousandths, array $type = [], array $holidays = []): array => [
    'id' => $id,
    'rule' => (array) $rule,
    'source' => $source,
    'working' => $working,
    'type' => ['code' => 'acc_unpaid', 'default_days' => 0, 'is_paid' => false, ...$type],
    'org' => ['holidays' => $holidays],
    'pay_period' => $period,
    'requests' => $requests,
    'expect' => ['unpaid_thousandths' => $thousandths],
];
$ap = fn (string $key, string $from, string $to, int $days): array => ['key' => $key, 'from' => $from, 'to' => $to, 'days' => $days, 'status' => 'approved'];

return [
    $y('GL-YEAR-001', ['LVR-YEAR-01', 'LVR-YEAR-02'], 'register', 'ethiopian', '2026-09-11', [
        '2026-09-11 is Meskerem 1 of Ethiopian year 2019',
        'year number 2019, month 1, the year starts 2026-09-11; month 1 entitlement date = Meskerem 30 = 2026-10-10',
    ], ['year' => 2019, 'month' => 1, 'starts' => '2026-09-11', 'month_end' => '2026-10-10']),

    $y('GL-YEAR-002', ['LVR-YEAR-01', 'LVR-YEAR-04'], 'register', 'ethiopian', '2026-09-10', [
        '2026-09-10 is Pagume 5, the last day of year 2018: year 2018, month 12 (Pagume is part of month 12)',
        'the year starts 2025-09-11; the entitlement date of month 12 is the last day of Pagume = 2026-09-10',
    ], ['year' => 2018, 'month' => 12, 'starts' => '2025-09-11', 'month_end' => '2026-09-10']),

    $y('GL-YEAR-003', ['LVR-YEAR-01', 'LVR-YEAR-04'], '06 LB-010 (the register: the year ends 2027-09-11)', 'ethiopian', '2027-09-11', [
        'Pagume has six days in year 2019 (6 to 11 September 2027): 2027-09-11 is still year 2019, month 12',
        'the entitlement date of month 12 is the last day of Pagume: 2027-09-11 (the pack says the year ends 2027-09-10)',
    ], ['year' => 2019, 'month' => 12, 'starts' => '2026-09-11', 'month_end' => '2027-09-11']),

    $y('GL-YEAR-004', ['LVR-YEAR-01', 'LVR-YEAR-02'], 'register', 'ethiopian', '2027-09-12', [
        '2027-09-12 is Meskerem 1 of year 2020: year 2020, month 1, starts 2027-09-12',
        'month 1 entitlement date = Meskerem 30 = 2027-09-12 + 29 days = 2027-10-11',
    ], ['year' => 2020, 'month' => 1, 'starts' => '2027-09-12', 'month_end' => '2027-10-11']),

    $y('GL-YEAR-005', ['LVR-YEAR-01', 'LVR-ACC-01'], 'register', 'ethiopian', '2026-10-11', [
        '2026-10-11 is Tikimt 1 of year 2019: month 2, the year starts 2026-09-11',
        'month 2 entitlement date = Tikimt 30 = 2026-10-11 + 29 days = 2026-11-09',
    ], ['year' => 2019, 'month' => 2, 'starts' => '2026-09-11', 'month_end' => '2026-11-09']),

    $y('GL-YEAR-006', ['LVR-YEAR-01', 'LVR-YEAR-02'], 'register', 'gregorian', '2026-12-31', [
        'Gregorian: the year is the calendar year: year number 2026, month 12, starts 2026-01-01, the month ends 2026-12-31',
    ], ['year' => 2026, 'month' => 12, 'starts' => '2026-01-01', 'month_end' => '2026-12-31']),

    $y('GL-YEAR-007', ['LVR-YEAR-01', 'LVR-ACC-01'], 'register', 'gregorian', '2028-02-10', [
        'Gregorian, 10 February 2028: month 2 of year 2028, the month ends on its last day, 2028-02-29',
    ], ['year' => 2028, 'month' => 2, 'starts' => '2028-01-01', 'month_end' => '2028-02-29']),

    $p('GL-PAYDAYS-001', ['LVR-PAY-02', 'LVR-DAYS-06'], 'register', ['2026-07-01', '2026-07-31'], [
        'an unpaid request Mon 2026-06-29 to Fri 2026-07-03 (stored 5) crosses the pay period start 2026-07-01',
        'payroll counts again from the calendar: the days inside the period are Wed 1, Thu 2, Fri 3 July = 3',
        '3 days = 3000 thousandths',
    ], [$ap('R1', '2026-06-29', '2026-07-03', 5)], 3000),

    $p('GL-PAYDAYS-002', ['LVR-PAY-02', 'LVR-DAYS-06'], 'register', ['2026-07-01', '2026-07-31'], [
        'an unpaid request Wed 2026-07-29 to Wed 2026-08-05 (stored 6) crosses the pay period end 2026-07-31',
        'the days inside the period: Wed 29, Thu 30, Fri 31 July = 3',
        '3 days = 3000 thousandths',
    ], [$ap('R1', '2026-07-29', '2026-08-05', 6)], 3000),

    $p('GL-PAYDAYS-003', 'LVR-PAY-02', 'register', ['2026-07-01', '2026-07-31'], [
        'an unpaid request wholly inside the period: Mon 2026-07-06 to Fri 2026-07-10 = 5 days = 5000 thousandths',
    ], [$ap('R1', '2026-07-06', '2026-07-10', 5)], 5000),

    $p('GL-PAYDAYS-004', ['LVR-PAY-01', 'LVR-PAY-02'], 'register', ['2026-07-01', '2026-07-31'], [
        'a PAID annual request inside the period changes no pay: the unpaid days are 0',
    ], [$ap('R1', '2026-07-06', '2026-07-10', 5)], 0, ['code' => 'acc_paid', 'default_days' => 16, 'is_paid' => true]),

    $p('GL-PAYDAYS-005', ['LVR-DAYS-10', 'LVR-DAYS-06', 'LVR-PAY-02'], 'register', ['2028-05-01', '2028-05-31'], [
        'LVR-DAYS-10 (c): an unpaid request Mon 2028-05-01 to Fri 2028-05-05 counted at 5 before Labour Day\'s row existed',
        'the row for 1 May 2028 now exists, but payroll deducts the stored 5 for a request wholly inside the pay period: 5000 thousandths',
    ], [$ap('R1', '2028-05-01', '2028-05-05', 5)], 5000, [], [['date' => '2028-05-01']]),

    $p('GL-PAYDAYS-006', 'LVR-DAYS-11', 'register', ['2026-07-01', '2026-07-31'], [
        'a row written directly holds 0.5 days (decimal(5,1)), wholly inside the period: payroll deducts half a day',
        '0.5 day = 500 thousandths',
    ], [['key' => 'R1', 'from' => '2026-07-06', 'to' => '2026-07-06', 'days' => 0.5, 'status' => 'approved']], 500),

    $p('GL-PAYDAYS-007', ['LVR-PAY-04', 'LVR-PAY-02'], 'register', ['2026-07-01', '2026-07-31'], [
        'an Art. 85 sick type that is not paid, approved Mon 2026-07-06 to Fri 2026-07-10, is never counted as unpaid leave as well',
        'unpaid days from the unpaid-leave step: 0',
    ], [$ap('R1', '2026-07-06', '2026-07-10', 5)], 0, ['code' => 'acc_sick', 'default_days' => 30, 'is_paid' => false, 'follows_art85' => true]),
];
