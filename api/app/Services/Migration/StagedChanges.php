<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Enums\ContractType;
use App\Enums\EmployeeStatus;
use App\Enums\NationalityStatus;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\EmployeeContract;
use App\Models\EmployeeEmergencyContact;
use App\Models\Grade;
use App\Models\MigrationStagingRow;
use App\Models\PersonnelAction;
use App\Models\Position;
use App\Services\CurrentTenant;
use App\Support\DepartmentMatch;
use App\Support\EmployeeImportChoices;
use App\Support\EmployeeImportRow;
use App\Support\EthiopianPhone;
use App\Support\RelationMatch;
use Illuminate\Support\Carbon;
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
 *   - **locked**: ETHR has something else, and a list is not how it is
 *     changed. Shown, never applied, never acceptable. A position, a branch,
 *     a grade, a cost centre and a probation end are changed with a personnel
 *     action, which leaves a record of who moved whom and when.
 *
 * What counts as blank in ETHR: null or empty; a salary of 0; a name ETHR made
 * up for a device user who had none; a hire date ETHR set to the day of an
 * earlier list because that list gave none. A blank in the row never
 * overwrites anything. A column ETHR gives a default (the nationality
 * status) is never blank: the default is a value.
 *
 * A list staged to complete people (`MigrationBatch::isCompleting`) fills
 * blanks and nothing else on a person's job and pay: a department and a
 * salary that differ are locked too, and a person still `hired` is moved to
 * the `probation` or `confirmed` the row says, through the transition service.
 *
 * A bank account, an emergency contact and a contract are records of their
 * own. When the row brings a whole one and the person has none, it is added;
 * when the person has one, nothing is written and nothing is shown, because
 * comparing would reveal an account number or a relative's phone.
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

    public const LOCKED = 'locked';

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
        'name_am' => 'name_am',
        'gender' => 'gender',
        'date_of_birth' => 'date_of_birth',
        'marital_status' => 'marital_status',
        'nationality' => 'nationality',
        'nationality_status' => 'nationality_status',
        'position' => 'position_id',
        'branch' => 'branch_id',
        'grade' => 'grade_id',
        'cost_center' => 'cost_center_id',
        'probation_end_date' => 'probation_end_date',
    ];

    /** Fields of a person's job: filled when blank, never changed from a list. */
    public const JOB = ['position', 'branch', 'grade', 'cost_center', 'probation_end_date'];

    /** Fields a list that completes people never changes, though a list that adds them may. */
    public const LOCKED_WHEN_COMPLETING = ['department', 'salary_cents'];

    /**
     * Changes that are records of their own, named for what they add. They are
     * not employee attributes and are written by the commit, not by an edit.
     */
    public const RECORDS = ['bank', 'emergency_contact', 'contract'];

    /** The change that moves a person from `hired`. Written by the transition service. */
    public const STATUS = 'status';

    /**
     * Fields whose values are never sent to the review screen. A bank account
     * is not one of FIELDS (it is written for a new person only), but a list
     * that carries one is described by the same two lists.
     */
    public const MASKED = ['national_id', 'tin', 'salary_cents', 'bank_account'];

    /** Fields that are pay data: changing them needs employee.updateFinancial. */
    public const PAY = ['tin', 'salary_cents', 'bank_name', 'bank_branch', 'bank_account', 'bank'];

    /** Fields one employee holds at a time. */
    private const HELD_BY_ONE = ['employee_code', 'badge_number'];

    /** Fields that name something the organisation keeps, and the model and column that give its name. */
    private const RELATIONS = [
        'department' => [Department::class, 'name'],
        'position' => [Position::class, 'title'],
        'branch' => [Branch::class, 'name'],
        'grade' => [Grade::class, 'name'],
        'cost_center' => [CostCenter::class, 'name'],
    ];

    private const DATES = ['hire_date', 'date_of_birth', 'probation_end_date'];

    private const LOCALES = ['en', 'am', 'om'];

    private ?string $placeholderPattern = null;

    /** @var array<string, RelationMatch> positions, branches, grades and cost centres, read once per organisation */
    private array $relations = [];

    /**
     * The fields a reviewer may accept a difference for. A job field is never
     * one of them, and in a list that completes people nor is a department or
     * a salary.
     *
     * @return list<string>
     */
    public static function acceptable(bool $completing = false): array
    {
        return array_values(array_filter(
            array_keys(self::FIELDS),
            fn (string $field): bool => ! in_array($field, self::JOB, true)
                && ! ($completing && in_array($field, self::LOCKED_WHEN_COMPLETING, true)),
        ));
    }

    /**
     * @param  array<string, mixed>  $raw  the staged row
     * @param  array<int, mixed>  $previous  the list stored on the row, for what was accepted
     * @param  bool  $completing  the list completes people who are here and adds nobody
     * @return list<array{field: string, kind: string, current: string|null, incoming: string, accepted: bool, taken: bool}>
     */
    public function between(Employee $employee, array $raw, DepartmentMatch $departments, array $previous = [], bool $completing = false): array
    {
        $fromDevice = ($raw['code_is_guess'] ?? false) === true;
        $changes = [];

        foreach (array_keys(self::FIELDS) as $field) {
            if ($fromDevice && in_array($field, self::HELD_BY_ONE, true)) {
                continue;
            }

            $incoming = $this->incoming($field, $raw[$field] ?? null, $employee, $departments);
            if ($incoming === null) {
                continue;
            }

            $current = $this->current($field, $employee);
            if ($this->same($field, $employee, $current, $incoming, $departments)) {
                continue;
            }

            $kind = match (true) {
                $current === null || $this->madeUp($field, $employee, $current) => self::FILL,
                $this->locks($field, $completing) => self::LOCKED,
                default => self::CONFLICT,
            };

            $changes[] = [
                'field' => $field,
                'kind' => $kind,
                'current' => $current,
                'incoming' => $incoming,
                'accepted' => $kind === self::CONFLICT && $this->wasAccepted($previous, $field, $current, $incoming),
                'taken' => in_array($field, self::HELD_BY_ONE, true) && $this->heldBySomeoneElse($employee, $field, $incoming),
            ];
        }

        if ($completing) {
            $status = $this->statusChange($employee, $raw);
            if ($status !== null) {
                $changes[] = $status;
            }
        }

        return [...$changes, ...$this->recordsToAdd($employee, $raw)];
    }

    /**
     * The list with exactly the differences of `$accept` marked as accepted: a
     * conflict that is not taken, whose field is named. Every other change is
     * left unaccepted, a locked one and one somebody else holds included.
     *
     * The one place an acceptance is stored, for a reviewer's choice on one
     * row (WorkforceMigrationService::setAction) and for many rows at once
     * (WorkforceMigrationService::acceptDifferences), so the two cannot drift.
     * It says only what was chosen: the commit still writes a field only while
     * both of its values are the ones shown (wasAccepted).
     *
     * @param  list<array<string, mixed>>  $changes
     * @param  list<string>  $accept
     * @return list<array<string, mixed>>
     */
    public function withAccepted(array $changes, array $accept): array
    {
        return array_map(
            fn (array $change): array => [
                ...$change,
                'accepted' => ($change['kind'] ?? null) === self::CONFLICT
                    && ($change['taken'] ?? false) !== true
                    && in_array($change['field'] ?? null, $accept, true),
            ],
            $changes,
        );
    }

    /**
     * The fields of a list of changes that committing would write: every fill,
     * and every conflict a reviewer accepted. Never one somebody else holds,
     * and never a locked one.
     *
     * @param  list<array{field: string, kind: string, current: string|null, incoming: string, accepted: bool, taken: bool}>  $changes
     * @return list<string>
     */
    public function applicable(array $changes): array
    {
        $fields = [];

        foreach ($changes as $change) {
            if ($change['kind'] === self::LOCKED || $change['taken']) {
                continue;
            }

            if ($change['kind'] === self::FILL || $change['accepted']) {
                $fields[] = $change['field'];
            }
        }

        return $fields;
    }

    /**
     * The edit `EmployeeUpdater` is handed for `$fields`: validated values,
     * relations as public ids, as `UpdateEmployeeRequest` produces them.
     * Records of their own and the status are not an edit and are left out.
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
            if (! in_array($field, $fields, true) || ! isset(self::FIELDS[$field])) {
                continue;
            }

            if (isset(self::RELATIONS[$field])) {
                $model = self::RELATIONS[$field][0];
                $publicId = $model::query()
                    ->where('tenant_id', $employee->tenant_id)
                    ->whereKey($this->relationId($field, $change['incoming'], $employee->tenant_id, $departments))
                    ->value('public_id');
                if (is_string($publicId)) {
                    $attributes[self::FIELDS[$field]] = $publicId;
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

    /** A difference the list may not write: shown, and left. */
    private function locks(string $field, bool $completing): bool
    {
        return in_array($field, self::JOB, true)
            || ($completing && in_array($field, self::LOCKED_WHEN_COMPLETING, true));
    }

    /** What the row brings for a field, or null when it brings nothing usable. */
    private function incoming(string $field, mixed $value, Employee $employee, DepartmentMatch $departments): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (isset(self::RELATIONS[$field])) {
            return $this->relationId($field, $text, $employee->tenant_id, $departments) === null ? null : $text;
        }

        return match ($field) {
            'phone' => EthiopianPhone::canonicalOrRaw($text),
            'salary_cents' => (int) $text > 0 ? (string) (int) $text : null,
            'hire_date', 'probation_end_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : null,
            'date_of_birth' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 && $text < Carbon::today()->toDateString() ? $text : null,
            'gender', 'marital_status' => $this->choice($field, $text),
            'nationality_status' => NationalityStatus::fromInput($text)?->value,
            default => $text,
        };
    }

    /**
     * A value of a column with a fixed list, read as the import reads it (M and
     * F are male and female) and kept only when the list has it.
     */
    private function choice(string $field, string $text): ?string
    {
        $value = EmployeeImportRow::normalize([$field => $text])[$field] ?? null;

        return is_string($value) && in_array($value, EmployeeImportChoices::forTenant(null)[$field], true) ? $value : null;
    }

    /** What ETHR holds for a field, or null when it holds nothing. */
    private function current(string $field, Employee $employee): ?string
    {
        if (isset(self::RELATIONS[$field])) {
            $id = $employee->getAttribute(self::FIELDS[$field]);
            if ($id === null) {
                return null;
            }

            [$model, $column] = self::RELATIONS[$field];
            $name = $model::query()
                ->where('tenant_id', $employee->tenant_id)
                ->whereKey($id)
                ->value($column);

            return is_string($name) && $name !== '' ? $name : '#'.$id;
        }

        if (in_array($field, self::DATES, true)) {
            $date = $employee->getAttribute(self::FIELDS[$field]);

            return $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : null;
        }

        if ($field === 'salary_cents') {
            return (int) $employee->salary_cents > 0 ? (string) (int) $employee->salary_cents : null;
        }

        if ($field === 'nationality_status') {
            return $employee->nationality_status?->value;
        }

        $value = trim((string) $employee->getAttribute(self::FIELDS[$field]));

        return $value === '' ? null : $value;
    }

    private function same(string $field, Employee $employee, ?string $current, string $incoming, DepartmentMatch $departments): bool
    {
        if ($current === null) {
            return false;
        }

        if (isset(self::RELATIONS[$field])) {
            return $this->relationId($field, $incoming, $employee->tenant_id, $departments) === (int) $employee->getAttribute(self::FIELDS[$field]);
        }

        return match ($field) {
            'phone' => EthiopianPhone::canonicalOrRaw($current) === $incoming,
            'national_id', 'tin', 'hire_date', 'salary_cents', 'date_of_birth', 'probation_end_date', 'nationality_status' => $current === $incoming,
            default => self::folded($current) === self::folded($incoming),
        };
    }

    /** The organisation's record the value names, or null when nothing, or more than one, does. */
    private function relationId(string $field, ?string $value, int $tenantId, DepartmentMatch $departments): ?int
    {
        if ($field === 'department') {
            return $departments->id($value);
        }

        return ($this->relations["{$field}:{$tenantId}"] ??= match ($field) {
            'branch' => RelationMatch::branches($tenantId),
            'grade' => RelationMatch::grades($tenantId),
            'cost_center' => RelationMatch::costCenters($tenantId),
            default => RelationMatch::positions($tenantId),
        })->id($value);
    }

    /**
     * The row asks for a status, in a list that completes people. A person
     * still `hired` is moved to `probation` or `confirmed`, which is a fill;
     * any other difference is the person's own history and is locked.
     *
     * @param  array<string, mixed>  $raw
     * @return array{field: string, kind: string, current: string|null, incoming: string, accepted: bool, taken: bool}|null
     */
    private function statusChange(Employee $employee, array $raw): ?array
    {
        $value = $raw['status'] ?? null;
        $wanted = is_scalar($value) ? mb_strtolower(trim((string) $value)) : '';
        if (! in_array($wanted, ['hired', 'probation', 'confirmed'], true)) {
            return null;
        }

        $has = $employee->status->value;
        if ($has === $wanted) {
            return null;
        }

        $moves = $has === EmployeeStatus::HIRED->value && $wanted !== 'hired';

        return [
            'field' => self::STATUS,
            'kind' => $moves ? self::FILL : self::LOCKED,
            'current' => $moves ? null : $has,
            'incoming' => $wanted,
            'accepted' => false,
            'taken' => false,
        ];
    }

    /**
     * The bank account, emergency contact and contract the row brings whole
     * for a person who has none. Each is a fill that names what would be
     * added (the bank, the contact, the type of contract) and never what the
     * person already holds.
     *
     * Told only when the organisation is the one this request is for: a
     * question about what the person holds is asked through the tenant scope,
     * and with none resolved it answers "nothing" about everyone.
     *
     * @param  array<string, mixed>  $raw
     * @return list<array{field: string, kind: string, current: string|null, incoming: string, accepted: bool, taken: bool}>
     */
    private function recordsToAdd(Employee $employee, array $raw): array
    {
        if (app(CurrentTenant::class)->id() !== $employee->tenant_id) {
            return [];
        }

        $text = static fn (string $key): ?string => isset($raw[$key]) && is_scalar($raw[$key]) && trim((string) $raw[$key]) !== '' ? trim((string) $raw[$key]) : null;
        $found = [];
        $add = function (string $field, string $incoming) use (&$found): void {
            $found[] = ['field' => $field, 'kind' => self::FILL, 'current' => null, 'incoming' => $incoming, 'accepted' => false, 'taken' => false];
        };

        if ($text('bank_name') !== null && $text('bank_account') !== null && ! $this->holds(EmployeeBankDetail::class, $employee)) {
            $add('bank', (string) $text('bank_name'));
        }

        if ($text('emergency_name') !== null && $text('emergency_relationship') !== null && $text('emergency_phone') !== null
            && ! $this->holds(EmployeeEmergencyContact::class, $employee)) {
            $add('emergency_contact', (string) $text('emergency_name'));
        }

        $type = ContractType::tryFrom((string) $text('contract_type'));
        if ($type !== null && $employee->hire_date !== null && $this->contractFits($type, $text('contract_end_date'), $employee->hire_date)
            && ! $this->holds(EmployeeContract::class, $employee)) {
            $add('contract', $type->value);
        }

        return $found;
    }

    /**
     * A contract starts on the hire date. One that must end needs an end after
     * it; a list that cannot say so is left for a person to add on the profile.
     */
    private function contractFits(ContractType $type, ?string $end, \DateTimeInterface $hired): bool
    {
        if ($end === null) {
            return ! $type->requiresEndDate();
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) === 1 && $end > $hired->format('Y-m-d');
    }

    /**
     * The person has a record of this kind, of any state. A contract that has
     * ended is still a contract: the list adds the first one, not another.
     *
     * @param  class-string<EmployeeBankDetail|EmployeeEmergencyContact|EmployeeContract>  $model
     */
    private function holds(string $model, Employee $employee): bool
    {
        return $model::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->exists();
    }

    /**
     * Whether the employee's name (or Amharic name) is one ETHR made up, a
     * "Device user 17", which is a placeholder and not somebody's name.
     */
    public function isMadeUpName(Employee $employee, string $field): bool
    {
        $value = $field === 'name' || $field === 'name_am' ? $employee->{$field} : null;

        return is_string($value) && $this->madeUp($field === 'name_am' ? 'name' : $field, $employee, $value);
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
