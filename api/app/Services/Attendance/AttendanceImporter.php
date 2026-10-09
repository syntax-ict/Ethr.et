<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\CurrentTenant;
use App\Support\Csv;
use App\Support\TenantTime;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;

final class AttendanceImporter
{
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
    public function preview(UploadedFile $file): array
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
                $employee = Employee::where('employee_code', $row['employee_code'])->first();
                if (! $employee) {
                    $rowErrors[] = "Employee '{$row['employee_code']}' not found";
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

    public function commit(string $importKey, array $rows): array
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

            $employee = Employee::where('employee_code', $row['employee_code'])->first();
            if (! $employee) {
                $errors[] = "Row {$i}: Employee '{$row['employee_code']}' not found";

                continue;
            }

            // The sheet's times are wall-clock in the tenant's zone; parsed in
            // the app zone (UTC) every imported punch was three hours late in
            // Addis Ababa. The `date` column stays the local working day.
            $date = Carbon::parse($row['date'])->format('Y-m-d');
            $checkIn = TenantTime::wallClockToUtc($date, $row['check_in'], $zone);
            $checkOut = ! empty($row['check_out']) ? TenantTime::wallClockToUtc($date, $row['check_out'], $zone) : null;

            AttendanceRecord::create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'date' => $date,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'source' => AttendanceSource::CSV,
                'confidence_score' => AttendanceSource::CSV->baseConfidence(),
                'status' => AttendanceStatus::PRESENT,
                'idempotency_key' => $idempotencyKey,
                'metadata' => ['import_key' => $importKey],
            ]);

            $created++;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
