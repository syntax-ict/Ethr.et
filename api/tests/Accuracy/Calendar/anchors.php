<?php

declare(strict_types=1);

/*
 * Gregorian to Ethiopian pairs typed by hand, each with where it was read.
 * DATA ONLY: nothing here computes anything.
 *
 * `independent` is true only when the pair comes from a source outside this
 * repository (read on 2026-11-28). A pair taken from this repository's own
 * tests or documents was written by the same team that wrote the calendar
 * classes, so it shows that the two have not drifted apart, not that either is
 * right. `seed` marks the pair the table walk starts from.
 *
 * Wikipedia means https://en.wikipedia.org/wiki/Ethiopian_calendar. UT is
 * api/tests/Unit/EthiopianCalendarTest.php (PHP dataset, lines 14 to 22);
 * TS is src/src/test/ethiopian-calendar.test.ts (REFERENCE_PAIRS, lines 22 to 56).
 */

return [
    // Published, outside the repository.
    ['id' => 'WP-NY-1998', 'gregorian' => '2005-09-11', 'ethiopian' => [1998, 1, 1], 'source' => "Wikipedia, section New Year's Day: 1998 EC began on 11 September 2005", 'independent' => true, 'seed' => true],
    ['id' => 'WP-NY-1992', 'gregorian' => '1999-09-12', 'ethiopian' => [1992, 1, 1], 'source' => "Wikipedia, section New Year's Day: 1992 EC began on 12 September 1999", 'independent' => true, 'seed' => false],
    ['id' => 'WP-NY-1996', 'gregorian' => '2003-09-12', 'ethiopian' => [1996, 1, 1], 'source' => "Wikipedia, section New Year's Day: 1996 EC began on 12 September 2003", 'independent' => true, 'seed' => false],
    ['id' => 'WP-TODAY', 'gregorian' => '2026-11-28', 'ethiopian' => [2019, 3, 19], 'source' => 'Wikipedia, infobox "Today": 19 Hedar 2019 is Saturday 28 November 2026 (read 2026-11-28)', 'independent' => true, 'seed' => false],

    // The repository's own unit tests.
    ['id' => 'UT-NY-2017', 'gregorian' => '2024-09-11', 'ethiopian' => [2017, 1, 1], 'source' => 'UT line 14; TS line 22', 'independent' => false, 'seed' => false],
    ['id' => 'UT-NY-2016', 'gregorian' => '2023-09-12', 'ethiopian' => [2016, 1, 1], 'source' => 'UT line 15; TS line 23', 'independent' => false, 'seed' => false],
    ['id' => 'UT-NY-2015', 'gregorian' => '2022-09-11', 'ethiopian' => [2015, 1, 1], 'source' => 'UT line 16; TS line 28', 'independent' => false, 'seed' => false],
    ['id' => 'UT-MESKEL-2017', 'gregorian' => '2024-09-27', 'ethiopian' => [2017, 1, 17], 'source' => 'UT line 17; TS line 29', 'independent' => false, 'seed' => false],
    ['id' => 'UT-GENNA-2017', 'gregorian' => '2025-01-07', 'ethiopian' => [2017, 4, 29], 'source' => 'UT line 18; TS line 30', 'independent' => false, 'seed' => false],
    ['id' => 'UT-PAG-2015-1', 'gregorian' => '2023-09-06', 'ethiopian' => [2015, 13, 1], 'source' => 'UT line 19; TS line 35', 'independent' => false, 'seed' => false],
    ['id' => 'UT-PAG-2015-6', 'gregorian' => '2023-09-11', 'ethiopian' => [2015, 13, 6], 'source' => 'UT line 20; TS line 36', 'independent' => false, 'seed' => false],
    ['id' => 'UT-PAG-2016-5', 'gregorian' => '2024-09-10', 'ethiopian' => [2016, 13, 5], 'source' => 'UT line 21; TS line 41', 'independent' => false, 'seed' => false],
    ['id' => 'UT-SENE-2016', 'gregorian' => '2024-06-15', 'ethiopian' => [2016, 10, 8], 'source' => 'UT line 22; TS line 46', 'independent' => false, 'seed' => false],
    ['id' => 'UT-JAN1-2024', 'gregorian' => '2024-01-01', 'ethiopian' => [2016, 4, 22], 'source' => 'TS lines 47 to 51 (TypeScript only)', 'independent' => false, 'seed' => false],
    ['id' => 'UT-FEB29-2020', 'gregorian' => '2020-02-29', 'ethiopian' => [2012, 6, 21], 'source' => 'TS lines 52 to 56 (TypeScript only)', 'independent' => false, 'seed' => false],

    // The repository's other tests and documents.
    ['id' => 'DOC-NY-2019', 'gregorian' => '2026-09-11', 'ethiopian' => [2019, 1, 1], 'source' => 'docs/production testing/09-NON-FUNCTIONAL.md L10N-004; api/tests/Feature/Attendance/TimesheetLedgerTest.php line 359', 'independent' => false, 'seed' => false],
    ['id' => 'DOC-TIKIMT-14', 'gregorian' => '2026-10-24', 'ethiopian' => [2019, 2, 14], 'source' => 'docs/production testing/09-NON-FUNCTIONAL.md L10N-004', 'independent' => false, 'seed' => false],
    ['id' => 'DOC-PAG-2019-1', 'gregorian' => '2027-09-06', 'ethiopian' => [2019, 13, 1], 'source' => 'docs/production testing/09-NON-FUNCTIONAL.md L10N-005: Pagume 2019 EC is 6 to 11 September 2027', 'independent' => false, 'seed' => false],
    ['id' => 'DOC-PAG-2019-6', 'gregorian' => '2027-09-11', 'ethiopian' => [2019, 13, 6], 'source' => 'docs/production testing/09-NON-FUNCTIONAL.md L10N-005: six days, ending 11 September 2027', 'independent' => false, 'seed' => false],
    ['id' => 'DOC-NY-2020', 'gregorian' => '2027-09-12', 'ethiopian' => [2020, 1, 1], 'source' => 'docs/production testing/09-NON-FUNCTIONAL.md L10N-005: Meskerem 1, 2020 falls on 12 September 2027', 'independent' => false, 'seed' => false],
    ['id' => 'TEST-PAG-2018-1', 'gregorian' => '2026-09-06', 'ethiopian' => [2018, 13, 1], 'source' => 'api/tests/Feature/Attendance/TimesheetLedgerTest.php line 359: Pagume 2018 runs 2026-09-06 to 2026-09-10', 'independent' => false, 'seed' => false],
    ['id' => 'TEST-PAG-2018-5', 'gregorian' => '2026-09-10', 'ethiopian' => [2018, 13, 5], 'source' => 'api/tests/Feature/Attendance/TimesheetLedgerTest.php line 359', 'independent' => false, 'seed' => false],
    ['id' => 'TEST-PAG-2018-3', 'gregorian' => '2026-09-08', 'ethiopian' => [2018, 13, 3], 'source' => 'api/tests/Feature/Dashboard/ExecutiveMetricCorrectnessTest.php line 105', 'independent' => false, 'seed' => false],
    ['id' => 'TEST-MESK-30', 'gregorian' => '2026-10-10', 'ethiopian' => [2019, 1, 30], 'source' => 'api/tests/Feature/Dashboard/ExecutiveMetricCorrectnessTest.php line 104', 'independent' => false, 'seed' => false],
    ['id' => 'TEST-TIKIMT-1', 'gregorian' => '2026-10-11', 'ethiopian' => [2019, 2, 1], 'source' => 'api/tests/Feature/Dashboard/ExecutiveMetricCorrectnessTest.php line 103', 'independent' => false, 'seed' => false],
    ['id' => 'TEST-TAHSAS-15', 'gregorian' => '2023-12-25', 'ethiopian' => [2016, 4, 15], 'source' => 'api/tests/Feature/Employee/EmployeeNumberingAndImportTest.php line 140', 'independent' => false, 'seed' => false],
    ['id' => 'TEST-NY-1990', 'gregorian' => '1997-09-11', 'ethiopian' => [1990, 1, 1], 'source' => 'api/tests/Feature/Employee/EmployeeNumberingAndImportTest.php line 140', 'independent' => false, 'seed' => false],
    ['id' => 'QA-HIDAR-5', 'gregorian' => '2026-11-14', 'ethiopian' => [2019, 3, 5], 'source' => 'docs/audit/QA-PRODUCTION-2026-11-13-log.md line 91: Hidar 5, 2019 (2026-11-14)', 'independent' => false, 'seed' => false],
];
