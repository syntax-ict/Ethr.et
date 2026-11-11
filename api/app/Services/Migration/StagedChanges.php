<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\Department;
use App\Models\Employee;
use App\Models\MigrationStagingRow;
use App\Models\PersonnelAction;
use App\Support\DepartmentMatch;
use App\Support\EthiopianPhone;
use Illuminate\Support\Facades\Lang;

/**
 * What a staged row would change on the employee it matched.
 *
 * A match used to be a link and nothing more: the row's phone, department or
 * hire date were dropped, and the person adding the list typed them in again.
 * Owner decision 2026-11-10: fill what ETHR has blank, show a person every
 * difference, never a second employee.
 *
 * Each field the row brings is one of:
 *
 *   - **fill**: ETHR has nothing there. Applied when the list is committed.
 *   - **conflict**: ETHR has something else. Applied only when a reviewer
 *     accepted that field, and only while both values are still the ones they
 *     were shown.
 *
 * What counts as blank in ETHR: null or empty; a salary of 0; a name ETHR made
 * up for a device user who had none; a hire date ETHR set to the day of an
 * earlier list because that list gave none. A blank in the row never
 * overwrites anything.
 *
 * A terminal's own number is never offered as an employee number or a badge:
 * it is linked as that device's identity (IdentityResolver::link), which is
 * what punches resolve through.
 *
 * Values are compared, and applied, from the row and the employee as they are
 * at that moment. The list stored on the row is what the reviewer saw and what
 * they accepted; it is never applied as written.
 */
final class StagedChanges
{
    public const FILL = 'fill';

    public const CONFLICT = 'conflict';

    /** Staged key => the employee attribute it writes. */
    public const FIELDS = [
        'name' => 'name',
        'email' => 'email',
        'phone' => 'phone',
        'employee_code' => 'employee_code',
        'badge_number' => 'badge_number',
        'national_id' => 'national_id',
        'tin' => 'tin',
        'hire_date' => 'hire_date',
        'department' => 'department_id',
        'salary_cents' => 'salary_cents',
    ];

    /** Fields whose values are never sent to the review screen. */
    public const MASKED = ['national_id', 'tin', 'salary_cents'];

    /** Fields that are pay data: changing them needs employee.updateFinancial. */
    public const PAY = ['tin', 'salary_cents'];

    /** Fields one employee holds at a time. */
    private const HELD_BY_ONE = ['employee_code', 'badge_number'];

    private const LOCALES = ['en', 'am', 'om'];

    private ?string $placeholderPattern = null;

    /**
     * @param  array<string, mixed>  $raw  the staged row
     * @param  array<int, mixed>  $previous  the list stored on the row, for what was accepted
     * @return list<array{field: string, kind: string, current: string|null, incoming: string, accepted: bool, taken: bool}>
     */
    public function between(Employee $employee, array $raw, DepartmentMatch $departments, array $previous = []): array
    {
        $fromDevice = ($raw['code_is_guess'] ?? false) === true;
        $changes = [];

        foreach (array_keys(self::FIELDS) as $field) {
            if ($fromDevice && in_array($field, self::HELD_BY_ONE, true)) {
                continue;
            }

            $incoming = $this->incoming($field, $raw[$field] ?? null, $departments);
            if ($incoming === null) {
                continue;
            }

            $current = $this->current($field, $employee);
            if ($this->same($field, $employee, $current, $incoming, $departments)) {
                continue;
            }

            $kind = $current === null || $this->madeUp($field, $employee, $current) ? self::FILL : self::CONFLICT;

            $changes[] = [
                'field' => $field,
                'kind' => $kind,
                'current' => $current,
                'incoming' => $incoming,
                'accepted' => $kind === self::CONFLICT && $this->wasAccepted($previous, $field, $current, $incoming),
                'taken' => in_array($field, self::HELD_BY_ONE, true) && $this->heldBySomeoneElse($employee, $field, $incoming),
            ];
        }

        return $changes;
    }

    /**
     * The fields of a list of changes that committing would write: every fill,
     * and every conflict a reviewer accepted. Never one somebody else holds.
     *
     * @param  list<array{field: string, kind: string, current: string|null, incoming: string, accepted: bool, taken: bool}>  $changes
     * @return list<string>
     */
    public function applicable(array $changes): array
    {
        $fields = [];

        foreach ($changes as $change) {
            if (! $change['taken'] && ($change['kind'] === self::FILL || $change['accepted'])) {
                $fields[] = $change['field'];
            }
        }

        return $fields;
    }

    /**
     * The edit `EmployeeUpdater` is handed for `$fields`: validated values,
     * relations as public ids, as `UpdateEmployeeRequest` produces them.
     *
     * @param  list<array{field: string, kind: string, current: string|null, incoming: string, accepted: bool, taken: bool}>  $changes
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function attributes(Employee $employee, array $changes, array $fields, DepartmentMatch $departments): array
    {
        $attributes = [];

        foreach ($changes as $change) {
            $field = $change['field'];
            if (! in_array($field, $fields, true)) {
                continue;
            }

            if ($field === 'department') {
                $publicId = Department::query()
                    ->where('tenant_id', $employee->tenant_id)
                    ->whereKey($departments->id($change['incoming']))
                    ->value('public_id');
                if (is_string($publicId)) {
                    $attributes['department_id'] = $publicId;
                }

                continue;
            }

            $attributes[self::FIELDS[$field]] = $field === 'salary_cents' ? (int) $change['incoming'] : $change['incoming'];
        }

        return $attributes;
    }

    /**
     * A change to this employee is waiting for its date (a scheduled personnel
     * action). Its values were decided on purpose and for a day that has not
     * come, so a list does not write over the record meanwhile.
     */
    public function scheduled(Employee $employee): bool
    {
        return PersonnelAction::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereNull('applied_at')
            ->exists();
    }

    /** What the row brings for a field, or null when it brings nothing usable. */
    private function incoming(string $field, mixed $value, DepartmentMatch $departments): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return match ($field) {
            'phone' => EthiopianPhone::canonicalOrRaw($text),
            'salary_cents' => (int) $text > 0 ? (string) (int) $text : null,
            'hire_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : null,
            'department' => $departments->id($text) === null ? null : $text,
            default => $text,
        };
    }

    /** What ETHR holds for a field, or null when it holds nothing. */
    private function current(string $field, Employee $employee): ?string
    {
        if ($field === 'department') {
            if ($employee->department_id === null) {
                return null;
            }

            $name = Department::query()
                ->where('tenant_id', $employee->tenant_id)
                ->whereKey($employee->department_id)
                ->value('name');

            return is_string($name) && $name !== '' ? $name : '#'.$employee->department_id;
        }

        if ($field === 'hire_date') {
            return $employee->hire_date?->toDateString();
        }

        if ($field === 'salary_cents') {
            return (int) $employee->salary_cents > 0 ? (string) (int) $employee->salary_cents : null;
        }

        $value = trim((string) $employee->getAttribute(self::FIELDS[$field]));

        return $value === '' ? null : $value;
    }

    private function same(string $field, Employee $employee, ?string $current, string $incoming, DepartmentMatch $departments): bool
    {
        if ($current === null) {
            return false;
        }

        return match ($field) {
            'department' => $departments->id($incoming) === (int) $employee->department_id,
            'phone' => EthiopianPhone::canonicalOrRaw($current) === $incoming,
            'national_id', 'tin', 'hire_date', 'salary_cents' => $current === $incoming,
            default => self::folded($current) === self::folded($incoming),
        };
    }

    /**
     * ETHR has a value, and ETHR invented it: a "Device user 17" name, or a
     * hire date that is the day an earlier list was committed because that
     * list gave none. The second is known only while that list is kept
     * (MigrationBatch::RETENTION_DAYS); after that a different date is a
     * conflict, which is the safe way to be wrong.
     */
    private function madeUp(string $field, Employee $employee, string $current): bool
    {
        if ($field === 'name') {
            return preg_match($this->placeholderPattern(), $current) === 1;
        }

        if ($field !== 'hire_date') {
            return false;
        }

        return MigrationStagingRow::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('resolved_employee_id', $employee->id)
            ->where('action', MigrationStagingRow::ACTION_CREATE)
            ->whereNotNull('processed_at')
            ->get()
            ->contains(function (MigrationStagingRow $row) use ($current): bool {
                $raw = $row->getAttribute('raw');
                $processed = $row->getAttribute('processed_at');

                return (! is_array($raw) || empty($raw['hire_date']))
                    && $processed instanceof \DateTimeInterface
                    && $processed->format('Y-m-d') === $current;
            });
    }

    /**
     * @param  array<int, mixed>  $previous
     */
    private function wasAccepted(array $previous, string $field, ?string $current, string $incoming): bool
    {
        foreach ($previous as $change) {
            if (is_array($change) && ($change['field'] ?? null) === $field) {
                return ($change['accepted'] ?? false) === true
                    && ($change['current'] ?? null) === $current
                    && ($change['incoming'] ?? null) === $incoming;
            }
        }

        return false;
    }

    /**
     * Another record of this organisation, deleted ones included, already has
     * the number. Writing it would fail on the unique index (or, for a badge,
     * send two people's punches to one record), so it is shown and left.
     */
    private function heldBySomeoneElse(Employee $employee, string $field, string $incoming): bool
    {
        return Employee::withTrashed()
            ->where('tenant_id', $employee->tenant_id)
            ->where(self::FIELDS[$field], $incoming)
            ->whereKeyNot($employee->id)
            ->exists();
    }

    /** The name ETHR gives a device user with none, in every language it is written in. */
    private function placeholderPattern(): string
    {
        if ($this->placeholderPattern === null) {
            $forms = [];
            foreach (self::LOCALES as $locale) {
                $line = Lang::get('onboarding.migration.device_user', ['id' => 'IDHERE'], $locale);
                $forms[] = str_replace('IDHERE', '.+', preg_quote(is_string($line) ? $line : '', '/'));
            }

            $this->placeholderPattern = '/^(?:'.implode('|', $forms).')$/u';
        }

        return $this->placeholderPattern;
    }

    private static function folded(string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($value)));
    }
}
