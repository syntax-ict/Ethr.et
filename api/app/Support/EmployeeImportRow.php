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
 */
final class EmployeeImportRow
{
    /** @var list<string> */
    public const COLUMNS = [
        'name', 'email', 'phone', 'employee_code', 'national_id', 'gender',
        'hire_date', 'department_code', 'branch_code', 'position_code', 'salary_cents',
        'nationality', 'nationality_status',
    ];

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
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'employee_code' => ['nullable', 'string', 'max:30'],
            'national_id' => ['nullable', 'string', 'max:50'],
            'gender' => ['nullable', 'string', 'in:male,female'],
            'hire_date' => ['required', 'date'],
            'department_code' => ['nullable', 'string'],
            'branch_code' => ['nullable', 'string'],
            'position_code' => ['nullable', 'string'],
            'salary_cents' => ['nullable', 'integer', 'min:0'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'nationality_status' => ['nullable', 'string', 'in:'.implode(',', NationalityStatus::values())],
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

        return $row;
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
}
