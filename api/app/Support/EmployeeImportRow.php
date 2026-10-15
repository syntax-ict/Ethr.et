<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NationalityStatus;
use Illuminate\Support\Facades\Validator;

/**
 * The one definition of a valid employee import row.
 *
 * Preview and commit used to validate separately — the importer's hand-written
 * `validateRow()` (four checks) against `ImportCommitRequest` (eleven rules) — so
 * a row the preview passed could still fail the commit: a salary of "1,500", a
 * 21-character phone, a 31-character employee code. The page sends only the rows
 * the preview passed, and one of those 422'd the whole batch (audit N11).
 * Both now read these rules, and the preview validates each row exactly as the
 * commit will receive it.
 *
 * `national_id` is here because the template offers it and the importer reads
 * it for identity matching and stores it — but the commit request had no rule
 * for it, and `validated()` drops a nested key without one, so every imported
 * national id was silently discarded.
 *
 * A file is read as people actually make them (2026-10-15). Headers are matched
 * loosely ("Employee Code", "Department", "Start date"), so the employee export
 * imports as it stands; `department_code`, `branch_code` and `position_code`
 * take a code or a name; `salary` is in birr as people write it, beside the
 * older `salary_cents`; and a day-first date, the way Ethiopian offices write
 * one, is read day first rather than by PHP's month-first guess.
 */
final class EmployeeImportRow
{
    /** @var list<string> */
    public const COLUMNS = [
        'name', 'name_am', 'employee_code', 'email', 'phone', 'gender', 'date_of_birth',
        'hire_date', 'department_code', 'branch_code', 'position_code', 'salary', 'salary_cents',
        'national_id', 'tin', 'badge_number', 'nationality', 'nationality_status', 'marital_status',
    ];

    /**
     * The header row of the downloadable template: the friendly spelling of each
     * column, every one of which header() reads back to a key in COLUMNS.
     *
     * @var list<string>
     */
    public const TEMPLATE = [
        'name', 'name_am', 'employee_code', 'email', 'phone', 'gender', 'date_of_birth',
        'hire_date', 'department', 'branch', 'position', 'salary',
        'national_id', 'tin', 'badge_number', 'nationality', 'nationality_status', 'marital_status',
    ];

    /** Other spellings of a column, after header() has lower-cased it and joined its words with `_`. */
    private const ALIASES = [
        'full_name' => 'name',
        'employee_name' => 'name',
        'name_amharic' => 'name_am',
        'amharic_name' => 'name_am',
        'code' => 'employee_code',
        'employee_number' => 'employee_code',
        'employee_no' => 'employee_code',
        'employee_id' => 'employee_code',
        'staff_id' => 'employee_code',
        'email_address' => 'email',
        'mobile' => 'phone',
        'phone_number' => 'phone',
        'telephone' => 'phone',
        'sex' => 'gender',
        'dob' => 'date_of_birth',
        'birth_date' => 'date_of_birth',
        'start_date' => 'hire_date',
        'date_hired' => 'hire_date',
        'employment_date' => 'hire_date',
        'department' => 'department_code',
        'department_name' => 'department_code',
        'branch' => 'branch_code',
        'branch_name' => 'branch_code',
        'position' => 'position_code',
        'position_title' => 'position_code',
        'job_title' => 'position_code',
        'basic_salary' => 'salary',
        'monthly_salary' => 'salary',
        'salary_etb' => 'salary',
        'tin_number' => 'tin',
        'badge' => 'badge_number',
        'fayda' => 'national_id',
        'fayda_id' => 'national_id',
        'national_id_number' => 'national_id',
    ];

    /** A header cell as the key it names: "Employee Code" and "employee-code" are `employee_code`. */
    public static function header(string $cell): string
    {
        $key = mb_strtolower(trim($cell));
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', $key), '_');

        return self::ALIASES[$key] ?? $key;
    }

    /**
     * The rules for one row, each key prefixed — `rows.*.` for the commit request,
     * nothing for a single previewed row.
     *
     * @return array<string, list<string>>
     */
    public static function rules(string $prefix = ''): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
            'employee_code' => ['nullable', 'string', 'max:30'],
            'national_id' => ['nullable', 'string', 'max:50'],
            'tin' => ['nullable', 'string', 'max:20'],
            'badge_number' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9-]+$/'],
            'gender' => ['nullable', 'string', 'in:male,female'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'hire_date' => ['required', 'date'],
            'department_code' => ['nullable', 'string', 'max:255'],
            'branch_code' => ['nullable', 'string', 'max:255'],
            'position_code' => ['nullable', 'string', 'max:255'],
            'salary' => ['nullable', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'salary_cents' => ['nullable', 'integer', 'min:0'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'nationality_status' => ['nullable', 'string', 'in:'.implode(',', NationalityStatus::values())],
            'marital_status' => ['nullable', 'string', 'in:single,married,divorced,widowed'],
        ];

        $prefixed = [];
        foreach ($rules as $field => $fieldRules) {
            $prefixed[$prefix.$field] = $fieldRules;
        }

        return $prefixed;
    }

    /**
     * A CSV cell as the commit endpoint would receive it.
     *
     * The commit body passes through the global TrimStrings and
     * ConvertEmptyStringsToNull middleware; a previewed file does not. Applying
     * the same two steps here is what makes "the preview passed it" mean "the
     * commit will accept it" — and the rows the preview returns are the rows the
     * page sends back.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function normalize(array $row): array
    {
        $row = array_map(function (mixed $value): mixed {
            if (! is_string($value)) {
                return $value;
            }

            $value = trim($value);

            // Undo Csv::cell()'s formula guard, so an exported "'+251 91…"
            // comes back as the number it was.
            if (preg_match('/^\'[=+\-@]/', $value) === 1) {
                $value = substr($value, 1);
            }

            return $value === '' ? null : $value;
        }, $row);

        // "Ethiopian origin" and "FOREIGN" mean what they say; anything else
        // is left as written, so the rule above refuses it rather than this
        // guessing. A blank cell stays blank and the importer applies the
        // column default, ethiopian — the same as the new-employee form.
        if (is_string($row['nationality_status'] ?? null)) {
            $status = NationalityStatus::fromInput($row['nationality_status']);
            $row['nationality_status'] = $status === null ? $row['nationality_status'] : $status->value;
        }

        if (is_string($row['gender'] ?? null)) {
            $gender = mb_strtolower($row['gender']);
            $row['gender'] = match ($gender) {
                'm' => 'male',
                'f' => 'female',
                default => $gender,
            };
        }

        if (is_string($row['marital_status'] ?? null)) {
            $row['marital_status'] = mb_strtolower($row['marital_status']);
        }

        // "12,500.00 ETB" and "Br 12 500" are how a salary is written; the
        // rule then sees only digits and an optional two-place decimal.
        if (is_string($row['salary'] ?? null)) {
            $row['salary'] = (string) preg_replace('/etb|birr|br|[\s,]/i', '', $row['salary']);
        }

        foreach (['hire_date', 'date_of_birth'] as $field) {
            if (is_string($row[$field] ?? null)) {
                $row[$field] = self::dayFirst($row[$field]);
            }
        }

        return $row;
    }

    /**
     * Salary in cents: `salary_cents` when given, else `salary` in birr, else null.
     *
     * @param  array<string, mixed>  $row
     */
    public static function salaryCents(array $row): ?int
    {
        if (isset($row['salary_cents']) && $row['salary_cents'] !== '') {
            return (int) $row['salary_cents'];
        }

        $salary = $row['salary'] ?? null;
        if (! is_string($salary) || preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/', $salary, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /**
     * The row's errors, each prefixed with its CSV line number.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public static function errors(array $row, int $lineNumber): array
    {
        $validator = Validator::make($row, self::rules());

        return array_map(
            fn (string $message): string => "Row {$lineNumber}: {$message}",
            $validator->errors()->all(),
        );
    }

    /**
     * 15/03/2024 and 15.03.2024 as 2024-03-15. PHP reads a slashed date month
     * first, which turns the 3rd of April into the 4th of March without a word
     * and refuses the 15th of March outright.
     */
    private static function dayFirst(string $value): string
    {
        if (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})$#', $value, $m) !== 1) {
            return $value;
        }

        [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        return checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : $value;
    }
}
