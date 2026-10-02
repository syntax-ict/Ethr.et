<?php

declare(strict_types=1);

use App\Models\AttendanceRecord;
use App\Models\Shift;
use App\Services\Payroll\OvertimeClassifier;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Boots the Laravel app (so Eloquent models can format datetime attributes) but
// does not use RefreshDatabase — the classifier is exercised on in-memory models
// with a pre-set shift relation and never touches the database.
uses(TestCase::class);

function makeOtRecord(string $checkIn, string $checkOut, string $end = '17:30', bool $crossesMidnight = false): AttendanceRecord
{
    $shift = new Shift([
        'start_time' => '08:30',
        'end_time' => $end,
        'crosses_midnight' => $crossesMidnight,
    ]);

    $record = new AttendanceRecord([
        'check_in' => Carbon::parse($checkIn),
        'check_out' => Carbon::parse($checkOut),
    ]);
    $record->setRelation('shift', $shift);

    return $record;
}

test('daytime non-holiday overtime is all normal', function () {
    // Shift ends 17:30; worked to 19:30 -> 120 min, all before 22:00.
    $buckets = (new OvertimeClassifier)->classify(
        makeOtRecord('2026-06-15 08:30', '2026-06-15 19:30'),
        isHoliday: false,
    );

    expect($buckets)->toBe(['normal' => 120, 'night' => 0, 'rest_day' => 0, 'holiday' => 0, 'holiday_night' => 0]);
});

test('overtime running into the night splits normal and night', function () {
    // 17:30 -> 23:30 = 360 min. Night [22:00,23:30] = 90; day [17:30,22:00] = 270.
    $buckets = (new OvertimeClassifier)->classify(
        makeOtRecord('2026-06-15 08:30', '2026-06-15 23:30'),
        isHoliday: false,
    );

    expect($buckets['normal'])->toBe(270);
    expect($buckets['night'])->toBe(90);
    expect($buckets['holiday'])->toBe(0);
});

// Art. 68(1)(d) pays work *on* a public holiday, so the whole span worked
// counts — not only what ran past the shift's end, which is all these paid
// until 2026-10-01 (120 and 270 minutes here, of 660 and 900 worked).
test('a day worked on a public holiday is holiday time from check-in', function () {
    $buckets = (new OvertimeClassifier)->classify(
        makeOtRecord('2026-06-15 08:30', '2026-06-15 19:30'),
        isHoliday: true,
    );

    expect($buckets)->toBe(['normal' => 0, 'night' => 0, 'rest_day' => 0, 'holiday' => 660, 'holiday_night' => 0]);
});

test('holiday work into the night splits holiday and holiday_night', function () {
    $buckets = (new OvertimeClassifier)->classify(
        makeOtRecord('2026-06-15 08:30', '2026-06-15 23:30'),
        isHoliday: true,
    );

    expect($buckets['holiday'])->toBe(810);
    expect($buckets['holiday_night'])->toBe(90);
    expect($buckets['normal'])->toBe(0);
    expect($buckets['night'])->toBe(0);
});

test('overtime crossing midnight counts night minutes on both days', function () {
    // 17:30 -> 02:00 next day = 510 min. Night: [22:00,24:00]=120 + [00:00,02:00]=120 = 240; day 270.
    $buckets = (new OvertimeClassifier)->classify(
        makeOtRecord('2026-06-15 08:30', '2026-06-16 02:00'),
        isHoliday: false,
    );

    expect($buckets['normal'])->toBe(270);
    expect($buckets['night'])->toBe(240);
});

test('no overtime yields all zero buckets', function () {
    $buckets = (new OvertimeClassifier)->classify(
        makeOtRecord('2026-06-15 08:30', '2026-06-15 17:00'), // left before shift end
        isHoliday: false,
    );

    expect($buckets)->toBe(['normal' => 0, 'night' => 0, 'rest_day' => 0, 'holiday' => 0, 'holiday_night' => 0]);
});

// Art. 68(1)(c): work on a weekly rest day is paid at 2x for the whole span.
// No rest-day rate existed before 2026-10-01; this was paid as weekday
// overtime past the shift's end, or not at all.
test('a day worked on a weekly rest day is rest-day time from check-in', function () {
    $buckets = (new OvertimeClassifier)->classify(
        makeOtRecord('2026-06-14 08:30', '2026-06-14 15:30'),
        isHoliday: false,
        isRestDay: true,
    );

    expect($buckets)->toBe(['normal' => 0, 'night' => 0, 'rest_day' => 420, 'holiday' => 0, 'holiday_night' => 0]);
});

test('rest-day night hours stay at the rest-day rate, which exceeds the night rate', function () {
    $buckets = (new OvertimeClassifier)->classify(
        makeOtRecord('2026-06-14 20:00', '2026-06-14 23:30'),
        isHoliday: false,
        isRestDay: true,
    );

    expect($buckets['rest_day'])->toBe(210)
        ->and($buckets['night'])->toBe(0);
});

test('a public holiday on a rest day pays the higher holiday rate', function () {
    $buckets = (new OvertimeClassifier)->classify(
        makeOtRecord('2026-06-14 08:30', '2026-06-14 12:30'),
        isHoliday: true,
        isRestDay: true,
    );

    expect($buckets['holiday'])->toBe(240)
        ->and($buckets['rest_day'])->toBe(0);
});

test('rest-day work needs no shift', function () {
    $record = new AttendanceRecord([
        'check_in' => Carbon::parse('2026-06-14 09:00'),
        'check_out' => Carbon::parse('2026-06-14 13:00'),
    ]);
    $record->setRelation('shift', null);

    $buckets = (new OvertimeClassifier)->classify($record, isHoliday: false, isRestDay: true);

    expect($buckets['rest_day'])->toBe(240);
});
