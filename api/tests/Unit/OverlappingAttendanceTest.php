<?php

declare(strict_types=1);

use App\Models\AttendanceRecord;
use App\Services\Payroll\OverlappingAttendance;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

function overlapRecord(int $id, string $in, string $out): AttendanceRecord
{
    $record = new AttendanceRecord;
    $record->forceFill(['id' => $id, 'check_in' => Carbon::parse($in, 'UTC'), 'check_out' => Carbon::parse($out, 'UTC')]);

    return $record;
}

/** @param list<AttendanceRecord> $records */
function overlapSkipped(array $records): array
{
    return OverlappingAttendance::skipped(new Collection($records));
}

test('records that do not overlap skip nothing', function () {
    expect(overlapSkipped([
        overlapRecord(1, '2026-06-01 08:00', '2026-06-01 12:00'),
        overlapRecord(2, '2026-06-01 13:00', '2026-06-01 17:00'),
    ]))->toBe([]);
});

test('two identical records keep the lowest id', function () {
    expect(overlapSkipped([
        overlapRecord(7, '2026-06-01 08:00', '2026-06-01 18:00'),
        overlapRecord(3, '2026-06-01 08:00', '2026-06-01 18:00'),
    ]))->toBe([7 => 3]);
});

test('the longer record is kept whatever its id', function () {
    expect(overlapSkipped([
        overlapRecord(1, '2026-06-01 08:00', '2026-06-01 17:00'),
        overlapRecord(2, '2026-06-01 08:00', '2026-06-01 20:00'),
    ]))->toBe([1 => 2]);
});

test('intervals that only touch do not overlap', function () {
    expect(overlapSkipped([
        overlapRecord(1, '2026-06-01 08:00', '2026-06-01 12:00'),
        overlapRecord(2, '2026-06-01 12:00', '2026-06-01 17:00'),
    ]))->toBe([]);
});

test('a chain of three is one group and the longest pays', function () {
    expect(overlapSkipped([
        overlapRecord(1, '2026-06-01 08:00', '2026-06-01 12:00'),
        overlapRecord(2, '2026-06-01 11:00', '2026-06-01 18:00'),
        overlapRecord(3, '2026-06-01 17:00', '2026-06-01 20:00'),
    ]))->toBe([1 => 2, 3 => 2]);
});

test('a record crossing midnight overlaps one dated the next day', function () {
    expect(overlapSkipped([
        overlapRecord(1, '2026-06-01 20:00', '2026-06-02 04:00'),
        overlapRecord(2, '2026-06-02 02:00', '2026-06-02 03:00'),
    ]))->toBe([2 => 1]);
});

test('separate groups are decided separately', function () {
    expect(overlapSkipped([
        overlapRecord(1, '2026-06-01 08:00', '2026-06-01 12:00'),
        overlapRecord(2, '2026-06-01 09:00', '2026-06-01 12:00'),
        overlapRecord(3, '2026-06-02 08:00', '2026-06-02 12:00'),
        overlapRecord(4, '2026-06-02 08:00', '2026-06-02 12:00'),
    ]))->toBe([2 => 1, 4 => 3]);
});
