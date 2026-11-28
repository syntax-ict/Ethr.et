<?php

declare(strict_types=1);

/*
 * The shifts a leave case may name. S1 is the attendance suite's own shift
 * (Monday to Friday); the others are leave-only and live here so the file the
 * attendance oracle reads is not touched. working_days are ISO weekdays.
 */

$attendance = require dirname(__DIR__, 2).'/fixtures/shifts.php';

$day = fn (string $name, string $days): array => [
    'name' => $name, 'start_time' => '08:30', 'end_time' => '17:30', 'crosses_midnight' => false,
    'grace_minutes' => 15, 'early_departure_minutes' => 15, 'break_minutes' => 60, 'working_days' => $days,
];

return [
    'S1' => $attendance['S1'],
    // Monday to Saturday.
    'S6' => $day('S6', '1,2,3,4,5,6'),
    // Wednesday to Saturday.
    'S36' => $day('S36', '3,4,5,6'),
];
