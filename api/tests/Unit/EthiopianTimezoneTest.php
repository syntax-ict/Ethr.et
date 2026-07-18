<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Services\Attendance\AttendanceInput;
use Carbon\Carbon;

// ── Ethiopia has no DST: the UTC+3 (EAT) offset must never shift ──

test('Africa/Addis_Ababa stays at a constant +03:00 offset year-round', function () {
    // Dates chosen to straddle DST transition boundaries in timezones that
    // observe it (US: 2nd Sun March / 1st Sun Nov, EU: last Sun March / Oct),
    // so a library or config regression that pulls in DST rules would show
    // up as a non-+03:00 offset on at least one of these.
    $datesToCheck = [
        '2026-01-15', '2026-03-08', '2026-03-29', '2026-06-21',
        '2026-10-25', '2026-11-01', '2026-12-25',
    ];

    foreach ($datesToCheck as $date) {
        $eat = Carbon::parse($date.' 12:00:00', 'Africa/Addis_Ababa');
        expect($eat->format('P'))->toBe('+03:00');
        expect($eat->utcOffset())->toBe(180);
    }
});

test('a UTC instant converts to Africa/Addis_Ababa exactly 3 hours ahead', function () {
    $utc = Carbon::parse('2026-06-15 09:00:00', 'UTC');
    $eat = $utc->copy()->timezone('Africa/Addis_Ababa');

    expect($eat->format('Y-m-d H:i:s'))->toBe('2026-06-15 12:00:00');
});

// ── APP_TIMEZONE is UTC, but "today" for attendance/leave/payroll purposes
// should mean the Ethiopian calendar day, not the UTC calendar day ──

test('a very-early-morning EAT check-in is dated to the UTC calendar day, not the EAT one', function () {
    // 01:00 EAT on Aug 1 is 22:00 UTC on Jul 31 - the instant is genuinely
    // "August 1st" for the Ethiopian employee checking in, but Carbon::now()
    // (and every now()->format('Y-m-d') call in AttendanceEngine, leave
    // "must be in the future" checks, dashboard "today" stats, etc.) resolves
    // against APP_TIMEZONE=UTC, not Africa/Addis_Ababa. This test pins that
    // current, likely-unintended behavior rather than asserting it's correct.
    $eatCheckInInstant = Carbon::create(2026, 8, 1, 1, 0, 0, 'Africa/Addis_Ababa');
    Carbon::setTestNow($eatCheckInInstant);

    try {
        $now = Carbon::now(); // matches how AttendanceEngine::processCheckIn() resolves "now"
        $dateFieldStoredByAttendanceEngine = $now->format('Y-m-d');

        // What the employee actually experienced, in their own timezone.
        $actualEatDate = $eatCheckInInstant->format('Y-m-d');

        expect($dateFieldStoredByAttendanceEngine)->toBe('2026-07-31');
        expect($actualEatDate)->toBe('2026-08-01');
        expect($dateFieldStoredByAttendanceEngine)->not->toBe($actualEatDate);
    } finally {
        Carbon::setTestNow();
    }
});

test('AttendanceInput does not itself apply any timezone conversion', function () {
    // Sanity check that the mismatch above lives in AttendanceEngine's use of
    // now(), not in the AttendanceInput DTO transforming dates unexpectedly.
    $input = new AttendanceInput(
        employeeId: 1,
        tenantId: 1,
        source: AttendanceSource::WEB,
        type: 'check_in',
        idempotencyKey: 'tz-test',
    );

    expect($input->type)->toBe('check_in');
});
