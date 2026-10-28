<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\EmployeeStatus;
use App\Enums\NationalityStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\EmployeeNumbering;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use App\Services\Invitations\InvitationDispatcher;
use App\Services\UserProvisioningService;
use App\Support\Csv;
use App\Support\EmployeeImportRow;
use App\Support\EthiopianPhone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;

class EmployeeImporter
{
    /** The relation columns, each with the model it names and that model's name columns. */
    private const RELATIONS = [
        'department_code' => [Department::class, ['name', 'name_am'], 'department_id'],
        'branch_code' => [Branch::class, ['name', 'name_am'], 'branch_id'],
        'position_code' => [Position::class, ['title', 'title_am'], 'position_id'],
    ];

    /** Columns two rows of one file cannot share. */
    private const UNIQUE_IN_FILE = ['employee_code', 'email', 'badge_number'];

    public function __construct(
        private readonly UserProvisioningService $provisioning,
        private readonly IdentityResolver $identity,
        private readonly CurrentTenant $currentTenant,
        private readonly EmployeeNumbering $numbering,
        private readonly InvitationDispatcher $invitations,
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

        // A spreadsheet saved as CSV keeps its empty trailing rows as ",,,,".
        $lines = array_values(array_filter($lines, fn (string $line): bool => trim($line, " \t,;\"") !== ''));

        if (count($lines) < 2) {
            return ['headers' => [], 'rows' => [], 'errors' => [0 => ['CSV file must have a header row and at least one data row.']]];
        }

        $headers = array_map(EmployeeImportRow::header(...), str_getcsv(array_shift($lines)));

        $required = ['name', 'hire_date'];
        $missing = array_diff($required, $headers);
        if (! empty($missing)) {
            return ['headers' => $headers, 'rows' => [], 'errors' => [0 => ['Missing required columns: '.implode(', ', $missing)]]];
        }

        $relations = $this->relationLookup();
        $firstSeen = [];
        $rows = [];
        $errors = [];

        foreach ($lines as $i => $line) {
            $lineNumber = $i + 2;
            $values = str_getcsv($line);
            $row = [];
            foreach ($headers as $j => $header) {
                // A second column with the same meaning ("Department" beside
                // "department_code") fills a gap and never overwrites.
                if (! array_key_exists($header, $row) || $row[$header] === null || $row[$header] === '') {
                    $row[$header] = $values[$j] ?? null;
                }
            }

            // As the commit endpoint will receive it, so the shared rules see
            // the same values in both places.
            $row = EmployeeImportRow::normalize($row);

            $rowErrors = [
                ...EmployeeImportRow::errors($row, $lineNumber),
                ...$this->relationErrors($row, $relations, $lineNumber),
                ...$this->duplicateErrors($row, $firstSeen, $lineNumber),
            ];
            if (! empty($rowErrors)) {
                $errors[$lineNumber] = $rowErrors;
            }

            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows, 'errors' => $errors];
    }

    /**
     * `in_person` counts the people with a login asked for and no way to send
     * them an invitation: HR activates them with an in-person code.
     *
     * @return array{created: int, skipped: int, matched: int, users_created: int, invitations_batch: string|null, invitations_queued: int, in_person: int, errors: array<int, string[]>}
     *
     * @scramble-return array{created: int, skipped: int, matched: int, users_created: int, invitations_batch: string|null, invitations_queued: int, in_person: int, errors: array<string, list<string>>}
     */
    public function commit(string $importKey, array $rows, bool $createLogins = false, ?User $invitedBy = null): array
    {
        $created = 0;
        $skipped = 0;
        $matched = 0;
        $usersCreated = 0;
        $inPerson = 0;
        /** @var list<User> $invitees */
        $invitees = [];
        $errors = [];

        $relations = $this->relationLookup();
        $firstSeen = [];

        $tenantId = $this->currentTenant->id();
        $tenant = $this->currentTenant->get();

        // Numbers for the rows that bring none, read once rather than per row.
        $unnumbered = count(array_filter($rows, fn (array $row): bool => ($row['employee_code'] ?? null) === null || $row['employee_code'] === ''));
        $pool = $unnumbered > 0 ? $this->numbering->sequence($tenant, $unnumbered) : [];

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

            $rowErrors = [
                ...EmployeeImportRow::errors($row, $i),
                ...$this->relationErrors($row, $relations, $i),
                ...$this->duplicateErrors($row, $firstSeen, $i),
            ];
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

            // The form refuses a badge another employee holds; the schema
            // does not (an index, not a unique one), so the import asks too.
            if (($row['badge_number'] ?? null) !== null
                && Employee::withTrashed()->where('tenant_id', $tenantId)->where('badge_number', $row['badge_number'])->exists()) {
                $errors[$i] = [__('employee.import_already_taken', ['row' => $i])];

                continue;
            }

            $attributes = [
                'name' => $row['name'],
                'name_am' => $row['name_am'] ?? null,
                'email' => $row['email'] ?? null,
                'phone' => EthiopianPhone::canonicalOrRaw($row['phone'] ?? null),
                'employee_code' => $row['employee_code'] ?? null,
                'badge_number' => $row['badge_number'] ?? null,
                'national_id' => $row['national_id'] ?? null,
                'tin' => $row['tin'] ?? null,
                'gender' => $row['gender'] ?? null,
                'date_of_birth' => $row['date_of_birth'] ?? null,
                'marital_status' => $row['marital_status'] ?? null,
                'nationality' => $row['nationality'] ?? null,
                'nationality_status' => $row['nationality_status'] ?? NationalityStatus::ETHIOPIAN->value,
                'hire_date' => $row['hire_date'],
                'salary_cents' => EmployeeImportRow::salaryCents($row) ?? 0,
                'status' => EmployeeStatus::HIRED,
                'import_key' => $rowKey,
            ];
            foreach (self::RELATIONS as $column => [, , $foreignKey]) {
                $attributes[$foreignKey] = $this->relationId($row[$column] ?? null, $relations[$column]);
            }

            try {
                $employee = $attributes['employee_code'] === null
                    ? $this->createNumbered($attributes, $pool)
                    : Employee::create($attributes);
            } catch (UniqueConstraintViolationException) {
                // A code or badge another employee already holds: the resolver
                // above did not call it the same person, so the row is refused
                // rather than merged or allowed to stop the whole batch.
                $errors[$i] = [__('employee.import_already_taken', ['row' => $i])];

                continue;
            }

            $created++;

            // A login for every row, invited after the loop from the queue. The
            // email used to go out here, one mail conversation per row inside
            // this request, which a few hundred rows run past the host's limit.
            if ($createLogins && ! empty($employee->email)) {
                $user = $this->provisioning->provision(
                    email: $employee->email,
                    role: UserRole::EMPLOYEE,
                    employee: $employee,
                    invitedBy: $invitedBy?->id,
                    sendActivation: false,
                );

                if ($user !== null) {
                    $usersCreated++;
                    $invitees[] = $user;
                }
            } elseif ($createLogins && ! empty($employee->phone) && $this->invitations->textsEnabled()) {
                $usersCreated++;
                $invitees[] = $this->provisioning->provisionWithPhone($employee, $invitedBy?->id);
            } elseif ($createLogins) {
                // No way to reach them: HR activates them in person, and the
                // in-person code makes the account when it is issued.
                $inPerson++;
            }
        }

        $invited = $invitees === []
            ? ['batch' => null, 'queued' => 0, 'in_person' => 0, 'skipped' => 0]
            : $this->invitations->queue($invitees, $invitedBy);

        return [
            'created' => $created,
            'skipped' => $skipped,
            'matched' => $matched,
            'users_created' => $usersCreated,
            'invitations_batch' => $invited['batch'],
            'invitations_queued' => $invited['queued'],
            'in_person' => $inPerson + $invited['in_person'],
            'errors' => $errors,
        ];
    }

    public function templateCsv(): string
    {
        return implode(',', EmployeeImportRow::TEMPLATE)."\n";
    }

    /**
     * The next number from the batch's pool, or a fresh one when another
     * writer took it in the meantime.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $pool
     */
    private function createNumbered(array $attributes, array &$pool): Employee
    {
        $code = array_shift($pool);
        if ($code !== null) {
            try {
                return Employee::create([...$attributes, 'employee_code' => $code]);
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'employee_code')) {
                    throw $e;
                }
                $pool = [];
            }
        }

        return $this->numbering->assign(
            $this->currentTenant->get(),
            fn (string $next): Employee => Employee::create([...$attributes, 'employee_code' => $next]),
        );
    }

    /**
     * Per relation column: lower-cased code → id, and lower-cased name → ids.
     * A name two rows share maps to both, which relationErrors() reports as
     * ambiguous rather than picking one.
     *
     * @return array<string, array{codes: array<string, int>, names: array<string, list<int>>}>
     */
    private function relationLookup(): array
    {
        $lookup = [];
        foreach (self::RELATIONS as $column => [$model, $nameColumns]) {
            $codes = [];
            $names = [];
            foreach ($model::query()->get(['id', 'code', ...$nameColumns]) as $record) {
                if (is_string($record->code) && $record->code !== '') {
                    $codes[mb_strtolower(trim($record->code))] = (int) $record->id;
                }
                foreach ($nameColumns as $nameColumn) {
                    $name = $record->getAttribute($nameColumn);
                    if (is_string($name) && trim($name) !== '') {
                        $key = mb_strtolower(trim($name));
                        $names[$key] = array_values(array_unique([...($names[$key] ?? []), (int) $record->id]));
                    }
                }
            }
            $lookup[$column] = ['codes' => $codes, 'names' => $names];
        }

        return $lookup;
    }

    /** @param  array{codes: array<string, int>, names: array<string, list<int>>}  $lookup */
    private function relationId(mixed $value, array $lookup): ?int
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $key = mb_strtolower(trim($value));

        if (isset($lookup['codes'][$key])) {
            return $lookup['codes'][$key];
        }

        $ids = $lookup['names'][$key] ?? [];

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * A department, branch or position the organisation does not have used to
     * become a silent null, so the employee landed unassigned and nobody knew.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, array{codes: array<string, int>, names: array<string, list<int>>}>  $relations
     * @return list<string>
     */
    private function relationErrors(array $row, array $relations, int $line): array
    {
        $errors = [];
        foreach (array_keys(self::RELATIONS) as $column) {
            $value = $row[$column] ?? null;
            if (! is_string($value) || $value === '' || $this->relationId($value, $relations[$column]) !== null) {
                continue;
            }

            $key = mb_strtolower(trim($value));
            $errors[] = __(
                count($relations[$column]['names'][$key] ?? []) > 1 ? 'employee.import_relation_ambiguous' : 'employee.import_relation_not_found',
                ['row' => $line, 'field' => __('employee.import_field_'.$column), 'value' => $value],
            );
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, int>>  $firstSeen
     * @return list<string>
     */
    private function duplicateErrors(array $row, array &$firstSeen, int $line): array
    {
        $errors = [];
        foreach (self::UNIQUE_IN_FILE as $column) {
            $value = $row[$column] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }

            $key = mb_strtolower($value);
            if (isset($firstSeen[$column][$key])) {
                $errors[] = __('employee.import_duplicate_in_file', [
                    'row' => $line,
                    'field' => __('employee.import_field_'.$column),
                    'value' => $value,
                    'first' => $firstSeen[$column][$key],
                ]);

                continue;
            }

            $firstSeen[$column][$key] = $line;
        }

        return $errors;
    }
}
