<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\EmployeeStatus;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Http\UploadedFile;

class EmployeeImporter
{
    /** @return array{headers: string[], rows: array<int, array<string, mixed>>, errors: array<int, string[]>} */
    public function preview(UploadedFile $file): array
    {
        $content = $file->getContent();
        $lines = array_filter(explode("\n", str_replace("\r\n", "\n", $content)));

        if (count($lines) < 2) {
            return ['headers' => [], 'rows' => [], 'errors' => [0 => ['CSV file must have a header row and at least one data row.']]];
        }

        $headers = str_getcsv(array_shift($lines));
        $headers = array_map('trim', $headers);
        $headers = array_map('strtolower', $headers);

        $required = ['name', 'hire_date'];
        $missing = array_diff($required, $headers);
        if (! empty($missing)) {
            return ['headers' => $headers, 'rows' => [], 'errors' => [0 => ['Missing required columns: '.implode(', ', $missing)]]];
        }

        $rows = [];
        $errors = [];

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $values = str_getcsv($line);
            $row = [];
            foreach ($headers as $j => $header) {
                $row[$header] = $values[$j] ?? null;
            }

            $rowErrors = $this->validateRow($row, $i + 2);
            if (! empty($rowErrors)) {
                $errors[$i + 2] = $rowErrors;
            }

            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows, 'errors' => $errors];
    }

    /** @return array{created: int, skipped: int, errors: array<int, string[]>} */
    public function commit(string $importKey, array $rows): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];

        $departmentMap = Department::pluck('id', 'code')->all();
        $branchMap = Branch::pluck('id', 'code')->all();
        $positionMap = Position::pluck('id', 'code')->all();

        foreach ($rows as $i => $row) {
            $rowKey = $importKey.'_'.($row['employee_code'] ?? $i);

            $existing = Employee::withoutGlobalScopes()->where('import_key', $rowKey)->first();
            if ($existing) {
                $skipped++;

                continue;
            }

            $rowErrors = $this->validateRow($row, $i);
            if (! empty($rowErrors)) {
                $errors[$i] = $rowErrors;

                continue;
            }

            Employee::create([
                'name' => $row['name'],
                'email' => $row['email'] ?? null,
                'phone' => $row['phone'] ?? null,
                'employee_code' => $row['employee_code'] ?? null,
                'gender' => $row['gender'] ?? null,
                'hire_date' => $row['hire_date'],
                'salary_cents' => isset($row['salary_cents']) ? (int) $row['salary_cents'] : 0,
                'status' => EmployeeStatus::HIRED,
                'department_id' => isset($row['department_code']) ? ($departmentMap[$row['department_code']] ?? null) : null,
                'branch_id' => isset($row['branch_code']) ? ($branchMap[$row['branch_code']] ?? null) : null,
                'position_id' => isset($row['position_code']) ? ($positionMap[$row['position_code']] ?? null) : null,
                'import_key' => $rowKey,
            ]);

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** @return string[] */
    private function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];

        if (empty($row['name'])) {
            $errors[] = "Row {$rowNumber}: name is required.";
        }

        if (empty($row['hire_date'])) {
            $errors[] = "Row {$rowNumber}: hire_date is required.";
        } elseif (! strtotime($row['hire_date'])) {
            $errors[] = "Row {$rowNumber}: hire_date is not a valid date.";
        }

        if (! empty($row['email']) && ! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Row {$rowNumber}: email is not valid.";
        }

        if (! empty($row['gender']) && ! in_array($row['gender'], ['male', 'female'], true)) {
            $errors[] = "Row {$rowNumber}: gender must be male or female.";
        }

        return $errors;
    }

    public function templateCsv(): string
    {
        $headers = ['name', 'email', 'phone', 'employee_code', 'gender', 'hire_date', 'department_code', 'branch_code', 'position_code', 'salary_cents'];

        return implode(',', $headers)."\n";
    }
}
