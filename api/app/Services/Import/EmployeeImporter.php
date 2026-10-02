<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Services\CurrentTenant;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use App\Services\UserProvisioningService;
use App\Support\Csv;
use App\Support\EmployeeImportRow;
use App\Support\EthiopianPhone;
use Illuminate\Http\UploadedFile;

class EmployeeImporter
{
    public function __construct(
        private readonly UserProvisioningService $provisioning,
        private readonly IdentityResolver $identity,
        private readonly CurrentTenant $currentTenant,
    ) {}

    /**
     * `errors` is keyed by CSV line number (the header is line 1); key 0 is a
     * file-level error. The `@scramble-return` states the shape for the API
     * contract, which cannot follow rows built from the file's own headers;
     * it types the line-number keys as strings, which is what JSON makes them.
     *
     * @return array{headers: string[], rows: array<int, array<string, mixed>>, errors: array<int, string[]>}
     *
     * @scramble-return array{headers: list<string>, rows: list<array<string, string|null>>, errors: array<string, list<string>>}
     */
    public function preview(UploadedFile $file): array
    {
        // Csv::lines, as the attendance import uses: this split on "\n" after
        // folding CRLF, so a UTF-8 BOM (Excel's "CSV UTF-8") glued itself to the
        // first header — "name" was reported missing from a file that had it —
        // and a classic-Mac CR-only file was one line.
        $lines = Csv::lines((string) $file->getContent());

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
            $values = str_getcsv($line);
            $row = [];
            foreach ($headers as $j => $header) {
                $row[$header] = $values[$j] ?? null;
            }

            // As the commit endpoint will receive it, so the shared rules see
            // the same values in both places.
            $row = EmployeeImportRow::normalize($row);

            $rowErrors = EmployeeImportRow::errors($row, $i + 2);
            if (! empty($rowErrors)) {
                $errors[$i + 2] = $rowErrors;
            }

            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows, 'errors' => $errors];
    }

    /**
     * @return array{created: int, skipped: int, matched: int, users_created: int, errors: array<int, string[]>}
     *
     * @scramble-return array{created: int, skipped: int, matched: int, users_created: int, errors: array<string, list<string>>}
     */
    public function commit(string $importKey, array $rows, bool $createLogins = false): array
    {
        $created = 0;
        $skipped = 0;
        $matched = 0;
        $usersCreated = 0;
        $errors = [];

        $departmentMap = Department::pluck('id', 'code')->all();
        $branchMap = Branch::pluck('id', 'code')->all();
        $positionMap = Position::pluck('id', 'code')->all();

        $tenantId = $this->currentTenant->id();

        foreach ($rows as $i => $row) {
            $rowKey = $importKey.'_'.($row['employee_code'] ?? $i);

            // `import_key` is caller-supplied (`required|string|max:50`) and has
            // no uniqueness constraint and no tenant binding, so this lookup has
            // to state the tenant itself.
            //
            // `withoutGlobalScopes()` is deliberate and stays: it drops the
            // soft-delete scope, so re-importing a key whose employee was
            // soft-deleted is still skipped rather than duplicated. But it drops
            // the tenant scope along with it, and without the predicate below the
            // de-duplication read every tenant's rows — a colliding key silently
            // skipped another tenant's row and reported it as `skipped`, which is
            // both a cross-tenant existence oracle and silent data loss.
            //
            // The sibling read path, EmployeeImportController::status(), was
            // always scoped (see "import status does not count another tenant
            // rows"); only this write path was not.
            $existing = Employee::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('import_key', $rowKey)
                ->first();
            if ($existing) {
                $skipped++;

                continue;
            }

            $rowErrors = EmployeeImportRow::errors($row, $i);
            if (! empty($rowErrors)) {
                $errors[$i] = $rowErrors;

                continue;
            }

            // Guard against importing a person who already exists under a
            // different import batch (a re-exported file, a manual entry). Only
            // a confident match on a strong identifier (code/email/phone) skips;
            // a mere name resemblance never blocks a genuine new hire. See
            // ONBOARDING_V2.md D4.
            if ($tenantId !== null) {
                $match = $this->identity->resolve($tenantId, new IdentitySignals(
                    employeeCode: $row['employee_code'] ?? null,
                    email: $row['email'] ?? null,
                    phone: $row['phone'] ?? null,
                    name: $row['name'] ?? null,
                    nationalId: $row['national_id'] ?? null,
                ));

                if ($match->isMatch()) {
                    $matched++;

                    continue;
                }
            }

            $employee = Employee::create([
                'name' => $row['name'],
                'email' => $row['email'] ?? null,
                'phone' => EthiopianPhone::canonicalOrRaw($row['phone'] ?? null),
                'employee_code' => $row['employee_code'] ?? null,
                'national_id' => $row['national_id'] ?? null,
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

            // Provision a login (employee role) for rows that carry an email.
            // Rows without an email are attendance/payroll-only records.
            if ($createLogins && ! empty($employee->email)) {
                $user = $this->provisioning->provision(
                    email: $employee->email,
                    role: UserRole::EMPLOYEE,
                    employee: $employee,
                );

                if ($user !== null) {
                    $usersCreated++;
                }
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'matched' => $matched, 'users_created' => $usersCreated, 'errors' => $errors];
    }

    public function templateCsv(): string
    {
        return implode(',', EmployeeImportRow::COLUMNS)."\n";
    }
}
