<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EmployeeStatus;
use App\Enums\NationalityStatus;
use App\Services\Calendar\EthiopianCalendar;
use App\Services\Identity\IdentitySignals;
use Illuminate\Support\Carbon;
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
 *
 * It is also the only place a column name is given a meaning (2026-11-11).
 * The guided setup's pasted list had a second table of names in the browser,
 * and the two disagreed: "Badge" was an employee number there and a badge
 * here, Amharic column names were read there and dropped here, and a date
 * written 15/03/2024 was refused there and read here. A pasted list now comes
 * through EmployeeImportGrid, which asks this class what each column is.
 */
final class EmployeeImportRow
{
    /** @var list<string> */
    public const COLUMNS = [
        'name', 'name_am', 'employee_code', 'email', 'phone', 'gender', 'date_of_birth',
        'hire_date', 'department_code', 'branch_code', 'position_code', 'salary', 'salary_cents',
        'national_id', 'tin', 'badge_number', 'nationality', 'nationality_status', 'marital_status',
        'status', 'external_id',
    ];

    /** The columns that hold a date, each of which may name its calendar: "Hire date (E.C.)". */
    public const DATES = ['hire_date', 'date_of_birth'];

    /** A list with no column names is read in this order. Only the name is required. */
    public const DEFAULT_ORDER = ['name', 'email', 'phone', 'employee_code', 'hire_date', 'department_code'];

    /** The statuses a list may bring a new person in with. Anyone who has left is not imported as new. */
    public const STATUSES = ['hired', 'probation', 'confirmed'];

    /** Where an "ID in the other system" is kept: one identity per person, per source. */
    public const EXTERNAL_SOURCE = 'hr_system';

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
        'status', 'external_id',
    ];

    /** Other spellings of a column, after key() has lower-cased it and joined its words with `_`. */
    private const ALIASES = [
        'full_name' => 'name',
        'employee_name' => 'name',
        'employee' => 'name',
        'staff_name' => 'name',
        'ስም' => 'name',
        'ሙሉ_ስም' => 'name',
        'name_amharic' => 'name_am',
        'amharic_name' => 'name_am',
        'code' => 'employee_code',
        'employee_number' => 'employee_code',
        'employee_no' => 'employee_code',
        'employee_id' => 'employee_code',
        'staff_id' => 'employee_code',
        'staff_number' => 'employee_code',
        'id' => 'employee_code',
        'number' => 'employee_code',
        'የሰራተኛ_ቁጥር' => 'employee_code',
        'email_address' => 'email',
        'e_mail' => 'email',
        'mail' => 'email',
        'ኢሜይል' => 'email',
        'mobile' => 'phone',
        'mobile_number' => 'phone',
        'phone_number' => 'phone',
        'telephone' => 'phone',
        'tel' => 'phone',
        'ስልክ' => 'phone',
        'sex' => 'gender',
        'dob' => 'date_of_birth',
        'birth_date' => 'date_of_birth',
        'start_date' => 'hire_date',
        'date_hired' => 'hire_date',
        'hired' => 'hire_date',
        'joined' => 'hire_date',
        'date_joined' => 'hire_date',
        'employment_date' => 'hire_date',
        'የተቀጠረበት_ቀን' => 'hire_date',
        'department' => 'department_code',
        'department_name' => 'department_code',
        'dept' => 'department_code',
        'division' => 'department_code',
        'unit' => 'department_code',
        'ክፍል' => 'department_code',
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
        'device_id' => 'badge_number',
        'device_user_id' => 'badge_number',
        'device_number' => 'badge_number',
        'fayda' => 'national_id',
        'fayda_id' => 'national_id',
        'national_id_number' => 'national_id',
        'employment_status' => 'status',
        'id_in_the_other_system' => 'external_id',
        'other_system_id' => 'external_id',
        'legacy_id' => 'external_id',
        'old_id' => 'external_id',
        'source_id' => 'external_id',
    ];

    /** What a column name, or a date itself, may end with to say which calendar it is in. */
    private const CALENDAR_MARKS = [
        'ec' => 'ethiopian',
        'e_c' => 'ethiopian',
        'ዓም' => 'ethiopian',
        'ዓ_ም' => 'ethiopian',
        'gc' => 'gregorian',
        'g_c' => 'gregorian',
        'ad' => 'gregorian',
        'a_d' => 'gregorian',
    ];

    /** Other spellings of a status. Someone "active" has been confirmed in their job. */
    private const STATUS_ALIASES = [
        'active' => 'confirmed',
        'permanent' => 'confirmed',
        'probationary' => 'probation',
        'on_probation' => 'probation',
        'new' => 'hired',
    ];

    /** A header cell as the key it names: "Employee Code" and "employee-code" are `employee_code`. */
    public static function header(string $cell): string
    {
        return self::column($cell)[0];
    }

    /**
     * A header cell as the key it names, and the calendar it says its dates
     * are in, when it says: "Hire date (E.C.)" is `hire_date`, Ethiopian.
     *
     * @return array{0: string, 1: 'gregorian'|'ethiopian'|null}
     */
    public static function column(string $cell): array
    {
        $key = self::key($cell);

        if (isset(self::ALIASES[$key]) || in_array($key, self::COLUMNS, true)) {
            return [self::ALIASES[$key] ?? $key, null];
        }

        foreach (self::CALENDAR_MARKS as $mark => $calendar) {
            if (! str_ends_with($key, '_'.$mark)) {
                continue;
            }

            $named = substr($key, 0, -strlen('_'.$mark));
            $field = self::ALIASES[$named] ?? $named;
            if (in_array($field, self::DATES, true)) {
                return [$field, $calendar];
            }
        }

        return [$key, null];
    }

    /** Lower-cased, its words joined with `_`, in any script: "ሙሉ ስም" is `ሙሉ_ስም`. */
    private static function key(string $value): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '_', mb_strtolower(trim($value))), '_');
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
            'status' => ['nullable', 'string', 'in:'.implode(',', self::STATUSES)],
            'external_id' => ['nullable', 'string', 'max:100'],
        ];

        $prefixed = [];
        foreach ($rules as $field => $fieldRules) {
            $prefixed[$prefix.$field] = $fieldRules;
        }

        return $prefixed;
    }

    /**
     * The rules for a row of a staged list, which a person reviews before
     * anyone is added. Two differ from a file committed directly: a hire date
     * may be missing (the day the list is added is used, and the screen says
     * so), and so may a name, for a row that is only a number to match.
     *
     * @return array<string, list<string>>
     */
    public static function stagedRules(string $prefix = '', bool $nameRequired = true): array
    {
        $rules = self::rules($prefix);
        $rules[$prefix.'name'] = [$nameRequired ? 'required' : 'nullable', 'string', 'max:255'];
        $rules[$prefix.'hire_date'] = ['nullable', 'date'];

        return $rules;
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
     * Dates are read in `$calendar`: an Ethiopian office's file says Tahsas 15,
     * 2016 as 2016-04-15 or 15/04/2016, which is 25 December 2023, and read as
     * Gregorian it became April 2016 without a word (A-Z review B-01). Either
     * way the row leaves here in Gregorian, which is what the commit stores.
     *
     * `$calendars` names the calendar of one date column where its header said
     * ("Hire date (E.C.)"), over `$calendar`. A date that says so itself
     * ("2011-10-30 E.C.") is read as it says, over both.
     *
     * @param  array<string, mixed>  $row
     * @param  'gregorian'|'ethiopian'  $calendar
     * @param  array<string, 'gregorian'|'ethiopian'>  $calendars
     * @return array<string, mixed>
     */
    public static function normalize(array $row, string $calendar = 'gregorian', array $calendars = []): array
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

        if (is_string($row['status'] ?? null)) {
            $status = self::key($row['status']);
            $row['status'] = self::STATUS_ALIASES[$status] ?? $status;
        }

        foreach (self::DATES as $field) {
            if (is_string($row[$field] ?? null)) {
                $row[$field] = self::date($row[$field], $calendars[$field] ?? $calendar);
            }
        }

        return $row;
    }

    /**
     * A row that passed the rules, as a staged list keeps it: the keys the
     * review and the commit read (`department`, `branch`, `position`,
     * `salary_cents`), dates as Y-m-d, nothing that is empty, and where the
     * row brings an id from another system, the identity it is linked as.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function staged(array $row): array
    {
        $staged = [];
        foreach (self::COLUMNS as $column) {
            $value = $row[$column] ?? null;
            if ($value === null || $value === '' || in_array($column, ['salary', 'salary_cents'], true)) {
                continue;
            }

            $key = match ($column) {
                'department_code' => 'department',
                'branch_code' => 'branch',
                'position_code' => 'position',
                default => $column,
            };
            $staged[$key] = in_array($column, self::DATES, true)
                ? Carbon::parse((string) $value)->toDateString()
                : (string) $value;
        }

        $salary = self::salaryCents($row);
        if ($salary !== null && $salary > 0) {
            $staged['salary_cents'] = $salary;
        }

        return [...$staged, ...self::externalIdentity($staged['external_id'] ?? null)];
    }

    /**
     * The identity an "ID in the other system" is kept as, in the keys a
     * staged row and IdentitySignals share. The same id in a later list finds
     * the same person, whatever else about them has changed.
     *
     * @return array{}|array{source_type: string, source_ref: string, identifier_type: string, identifier_value: string}
     */
    public static function externalIdentity(?string $externalId): array
    {
        if ($externalId === null || trim($externalId) === '') {
            return [];
        }

        return [
            'source_type' => self::EXTERNAL_SOURCE,
            'source_ref' => 'default',
            'identifier_type' => 'external_id',
            'identifier_value' => trim($externalId),
        ];
    }

    /**
     * Who a row describes, for "is this someone we know?": the details an
     * employee record is made from, and the other system's id when it brings one.
     *
     * @param  array<string, mixed>  $attributes  the employee's own columns
     */
    public static function signals(array $attributes, ?string $externalId): IdentitySignals
    {
        $own = IdentitySignals::fromAttributes($attributes);
        $identity = self::externalIdentity($externalId);

        return new IdentitySignals(
            employeeCode: $own->employeeCode,
            badgeNumber: $own->badgeNumber,
            email: $own->email,
            phone: $own->phone,
            name: $own->name,
            nationalId: $own->nationalId,
            sourceType: $identity['source_type'] ?? null,
            sourceRef: $identity['source_ref'] ?? null,
            identifierType: $identity['identifier_type'] ?? null,
            identifierValue: $identity['identifier_value'] ?? null,
        );
    }

    /**
     * The status a new person arrives with, and the dates that go with it.
     * Nothing given is Hired, as before. Someone brought in as Confirmed was
     * confirmed before ETHR knew them; the day is not in the list, so the day
     * they were added stands for it, as a confirmation made on the form does.
     *
     * @return array{status: EmployeeStatus, confirmation_date?: string}
     */
    public static function employment(?string $status): array
    {
        return match ($status) {
            EmployeeStatus::CONFIRMED->value => ['status' => EmployeeStatus::CONFIRMED, 'confirmation_date' => now()->toDateString()],
            EmployeeStatus::PROBATION->value => ['status' => EmployeeStatus::PROBATION],
            default => ['status' => EmployeeStatus::HIRED],
        };
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
        return array_map(
            fn (string $message): string => "Row {$lineNumber}: {$message}",
            self::messages($row, self::rules()),
        );
    }

    /**
     * What is wrong with a row under the rules given, with no line number:
     * the caller knows where the row is and how it wants to say so.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, list<string>>  $rules
     * @return list<string>
     */
    public static function messages(array $row, array $rules): array
    {
        return array_values(Validator::make($row, $rules)->errors()->all());
    }

    /**
     * A date as the Gregorian Y-m-d it falls on, read in `$calendar` unless it
     * names its own ("2011-10-30 E.C.").
     *
     * Year first (2016-04-15, 2016/4/15) or day first (15/04/2016,
     * 15.04.2016). A day-first date is the way Ethiopian offices write one;
     * PHP reads a slashed date month first, which turns the 3rd of April into
     * the 4th of March without a word and refuses the 15th of March outright.
     * Anything that is not a real date in its calendar (30 February, Pagume 7)
     * is returned as written, so the date rule refuses it rather than this
     * guessing.
     *
     * @param  'gregorian'|'ethiopian'  $calendar
     */
    private static function date(string $value, string $calendar): string
    {
        $written = $value;

        if (preg_match('/^(.*\d)\s*\(?([\p{L}.]+)\)?$/u', $value, $m) === 1) {
            $named = self::CALENDAR_MARKS[self::key($m[2])] ?? null;
            if ($named === null) {
                return $written;
            }
            [$value, $calendar] = [trim($m[1]), $named];
        }

        if (preg_match('#^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$#', $value, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})$#', $value, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return $written;
        }

        if ($calendar === 'ethiopian') {
            $ethiopian = app(EthiopianCalendar::class);
            $monthLength = $month === 13 ? $ethiopian->daysInPagume($year) : 30;

            return $month < 1 || $month > 13 || $day < 1 || $day > $monthLength
                ? $written
                : $ethiopian->ethiopianToGregorian($year, $month, $day)->format('Y-m-d');
        }

        return checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : $written;
    }
}
