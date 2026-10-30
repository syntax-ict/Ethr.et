<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\OrgScope;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Payroll\PayrollPeriodLock;
use App\Support\Csv;
use App\Support\TenantTime;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;

final class AttendanceImporter
{
    public function __construct(
        private readonly PayrollPeriodLock $periodLock,
        private readonly ShiftMatcher $shifts,
        private readonly AttendanceEngine $engine,
    ) {}

    public function templateCsv(): string
    {
        return "employee_code,date,check_in_time,check_out_time\n";
    }

    /**
     * Each row is the CSV line keyed by its header, plus `line`, `errors` and
     * `valid`; `check_out_time` is absent when the file has no such column.
     * Stated for the API contract, which cannot follow rows keyed by the
     * file's own header.
     *
     * @scramble-return array{rows: list<array{employee_code: string, date: string, check_in_time: string, check_out_time?: string, line: int, errors: list<string>, valid: bool}>, valid: int, invalid: int, errors: list<string>}
     */
    public function preview(UploadedFile $file, ?User $by = null): array
    {
        $lines = Csv::lines((string) file_get_contents($file->getRealPath()));

        if (count($lines) < 2) {
            return ['rows' => [], 'valid' => 0, 'invalid' => 0, 'errors' => ['File must have a header row and at least one data row.']];
        }

        $header = array_map(trim(...), str_getcsv(array_shift($lines)));
        $required = ['employee_code', 'date', 'check_in_time'];
        $missing = array_diff($required, $header);

        if (! empty($missing)) {
            return ['rows' => [], 'valid' => 0, 'invalid' => 0, 'errors' => ['Missing columns: '.implode(', ', $missing)]];
        }

        $rows = [];
        $valid = 0;
        $invalid = 0;

        foreach ($lines as $i => $line) {
            // Padded and cut to the header's width: a short row reads its
            // missing cells as empty, and a trailing comma no longer makes
            // array_combine() throw.
            $values = array_map(trim(...), str_getcsv($line) + array_fill(0, count($header), ''));
            $row = array_combine($header, array_slice($values, 0, count($header)));

            $rowErrors = [];

            if (empty($row['employee_code'])) {
                $rowErrors[] = 'employee_code is required';
            }

            if (empty($row['date']) || ! strtotime($row['date'])) {
                $rowErrors[] = 'Invalid date';
            }

            if (empty($row['check_in_time']) || ! preg_match('/^\d{2}:\d{2}$/', $row['check_in_time'])) {
                $rowErrors[] = 'Invalid check_in_time (expected HH:MM)';
            }

            if (! empty($row['check_out_time']) && ! preg_match('/^\d{2}:\d{2}$/', $row['check_out_time'])) {
                $rowErrors[] = 'Invalid check_out_time (expected HH:MM)';
            }

            if (empty($rowErrors)) {
                $employee = $this->employees($by)->where('employee_code', $row['employee_code'])->first();
                if (! $employee) {
                    $rowErrors[] = "Employee '{$row['employee_code']}' not found";
                } elseif ($by !== null && $employee->id === $by->employee_id) {
                    $rowErrors[] = __('attendance.import_row_self', ['row' => $i + 2]);
                }
            }

            $row['line'] = $i + 2;
            $row['errors'] = $rowErrors;
            $row['valid'] = empty($rowErrors);

            if ($row['valid']) {
                $valid++;
            } else {
                $invalid++;
            }

            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'valid' => $valid,
            'invalid' => $invalid,
            'errors' => [],
        ];
    }

    public function commit(string $importKey, array $rows, ?User $by = null): array
    {
        $tenant = app(CurrentTenant::class)->get();
        $zone = TenantTime::zone($tenant);
        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $idempotencyKey = "{$importKey}:{$row['employee_code']}:{$row['date']}:{$i}";

            $existing = AttendanceRecord::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                $skipped++;

                continue;
            }

            $employee = $this->employees($by)->where('employee_code', $row['employee_code'])->first();
            if (! $employee) {
                $errors[] = "Row {$i}: Employee '{$row['employee_code']}' not found";

                continue;
            }
            if ($by !== null && $employee->id === $by->employee_id) {
                $errors[] = __('attendance.import_row_self', ['row' => $i]);

                continue;
            }

            // The sheet's times are wall-clock in the tenant's zone; parsed in
            // the app zone (UTC) every imported punch was three hours late in
            // Addis Ababa. The `date` column stays the local working day.
            $date = Carbon::parse($row['date'])->format('Y-m-d');
            if ($this->periodLock->isClosed($employee->id, $date)) {
                $errors[] = __('attendance.import_row_payroll_closed', ['row' => $i]);

                continue;
            }
            $checkIn = TenantTime::wallClockToUtc($date, $row['check_in'], $zone);

            // A check-out earlier on the clock than the check-in is the next
            // morning: a night shift crossing midnight, as on manual entry.
            // Until 2026-10-27 it was stored on the row's own date, before
            // the check-in, and the night's overtime was never paid.
            $checkOut = null;
            if (! empty($row['check_out'])) {
                $outDate = $row['check_out'] < $row['check_in'] ? Carbon::parse($date)->addDay()->format('Y-m-d') : $date;
                $checkOut = TenantTime::wallClockToUtc($outDate, $row['check_out'], $zone);
            }

            // The shift the row's day is worked on, so it is rated and its
            // overtime counted as a live punch's is. Every imported row was
            // `present` with no shift until 2026-10-27: a late arrival was
            // on time and a workday's overtime was nothing. With no shift the
            // row is `pending`, as the engine leaves a punch it cannot rate,
            // so AttendanceHealth still sees the missing shift set-up.
            $shift = $this->shifts->match($employee, Carbon::parse($date));

            $record = AttendanceRecord::create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'shift_id' => $shift?->id,
                'date' => $date,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'source' => AttendanceSource::CSV,
                'confidence_score' => AttendanceSource::CSV->baseConfidence(),
                'status' => $shift !== null ? AttendanceStatus::PRESENT : AttendanceStatus::PENDING,
                'idempotency_key' => $idempotencyKey,
                'metadata' => ['import_key' => $importKey],
            ]);

            $this->engine->reclassify($record);

            $created++;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * The employees a row may name: those the importer's scope reaches, since
     * attendance.manage can be given to a role scoped to one branch. Someone
     * outside it reads as not found, the same as a code that does not exist.
     *
     * @return Builder<Employee>
     */
    private function employees(?User $by): Builder
    {
        $query = Employee::query();

        return $by === null || $by->orgScope() === OrgScope::ALL
            ? $query
            : $by->scopeAccessibleEmployees($query);
    }
}
