<?php

declare(strict_types=1);

/*
 * The calendar rules (docs/production testing/14-LEAVE-CALENDAR-AND-RESTATED-FIGURES.md,
 * section 2) and the test files that pin each. DATA ONLY. Paths are relative to
 * the repository root; RegisterConsistencyTest checks that every file exists.
 */

$php = 'api/tests/Accuracy/Calendar/EthiopianCalendarTableTest.php';
$ts = 'src/src/test/ethiopian-calendar-table.test.ts';
$clock = 'src/src/test/ethiopian-clock-roundtrip.test.ts';

return [
    'CAL-ETH-01' => [$php, $ts],
    'CAL-ETH-02' => [$php, $ts],
    'CAL-ETH-03' => [$php, $ts],
    'CAL-ETH-04' => [$php, $ts],
    'CAL-ETH-05' => [$php, $ts],
    'CAL-PAG-01' => [$php],
    'CAL-CLK-01' => [$clock],
    'CAL-CLK-02' => [$clock],
    'CAL-CLK-03' => [$clock],
];
