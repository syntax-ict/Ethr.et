<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceSource;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;

final class AttendanceImporter
{
    public function templateCsv(): string
    {
        return "employee_code,date,check_in_time,check_out_time\n";
    }

    public function preview(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        $lines = array_filter(explode("\n", trim($content)));

        if (count($lines) < 2) {
            return ['rows' => [], 'valid' => 0, 'invalid' => 0, 'errors' => ['File must have a header row and at least one data row.']];
        }

        $header = str_getcsv(array_shift($lines));
        $required = ['employee_code', 'date', 'check_in_time'];
        $missing = array_diff($required, $header);

        if (! empty($missing)) {
            return ['rows' => [], 'valid' => 0, 'invalid' => 0, 'errors' => ['Missing columns: ' . implode(', ', $missing)]];
        }

        $rows = [];
        $valid = 0;
        $invalid = 0;

        foreach ($lines as $i => $line) {
            $values = str_getcsv($line);
            $row = array_combine($header, $values + array_fill(0, count($header), ''));

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

            $date = Carbon::parse($row['date']);
            $checkIn = $date->copy()->setTimeFromTimeString($row['check_in']);
            $checkOut = ! empty($row['check_out']) ? $date->copy()->setTimeFromTimeString($row['check_out']) : null;

            AttendanceRecord::create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'date' => $date->format('Y-m-d'),
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'source' => AttendanceSource::CSV,
                'confidence_score' => AttendanceSource::CSV->baseConfidence(),
                'status' => 'present',
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
