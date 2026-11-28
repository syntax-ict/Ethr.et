<?php

declare(strict_types=1);

/*
 * The shifts a golden case may name, as data. Both the engine run
 * (GoldenCasesTest) and the independent oracle (tests/Accuracy/Oracle) read
 * this file, so the two are given the same shift and neither defines it.
 *
 * Times are wall-clock times in the organisation's zone. working_days are ISO
 * weekdays (1 Monday to 7 Sunday).
 */

return [
    // 05's QA shift S1.
    'S1' => ['name' => 'S1', 'start_time' => '08:30', 'end_time' => '17:30', 'crosses_midnight' => false, 'grace_minutes' => 15, 'early_departure_minutes' => 15, 'break_minutes' => 60, 'working_days' => '1,2,3,4,5'],
    // 05's QA night shift S2, every day of the week.
    'S2' => ['name' => 'S2', 'start_time' => '22:00', 'end_time' => '06:00', 'crosses_midnight' => true, 'grace_minutes' => 15, 'early_departure_minutes' => 15, 'break_minutes' => 60, 'working_days' => '1,2,3,4,5,6,7'],
    // A plain 08:00 to 17:00 day.
    'S3' => ['name' => 'S3', 'start_time' => '08:00', 'end_time' => '17:00', 'crosses_midnight' => false, 'grace_minutes' => 15, 'early_departure_minutes' => 15, 'break_minutes' => 60, 'working_days' => '1,2,3,4,5'],
];
