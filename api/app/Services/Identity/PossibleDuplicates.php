<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Support\EthiopianPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Current employees who may be one person recorded twice (One employee
 * identity, PR 11).
 *
 * Since 2026-11-11 every way an employee is made asks first whether the
 * person is already here (EmployeeIntake). Nothing asked before that, and an
 * organisation that brought its staff in from a device and then from a file
 * may hold some of them twice. This finds them; it changes nothing. Whether
 * two records are one person, and which to keep, is for someone who knows the
 * people.
 *
 * Two records are put together when they share something a person has only
 * one of: a national id, an email address, a phone number, a badge number,
 * or a name together with a date of birth. An employee number cannot be
 * shared (it is unique), and a name alone is shared by many people.
 *
 * Only people who are employed now are compared. A leaver hired again is a
 * second record on purpose (RecruitmentService), and a deleted record is
 * already out of the way.
 */
final class PossibleDuplicates
{
    public const NATIONAL_ID = 'national_id';

    public const EMAIL = 'email';

    public const PHONE = 'phone';

    public const BADGE = 'badge_number';

    public const NAME_AND_BIRTH_DATE = 'name_and_birth_date';

    /** The most groups one answer carries. More than this is not a list to work through by hand. */
    public const MAX_GROUPS = 100;

    /**
     * @param  Builder<Employee>  $employees  the employees the reader may see; narrowed here to those employed now
     * @return array{groups: list<array{reasons: list<string>, employees: Collection<int, Employee>}>, truncated: bool}
     */
    public function among(Builder $employees): array
    {
        $current = array_map(fn (EmployeeStatus $status): string => $status->value, EmployeeStatus::current());
        $base = $employees->whereIn('status', $current);

        /** @var array<string, list<string>> $reasonsOf  a group's members as "3,17", and why they are together */
        $reasonsOf = [];
        foreach ($this->sharing($base) as $reason => $groups) {
            foreach ($groups as $ids) {
                sort($ids);
                $reasonsOf[implode(',', $ids)][] = $reason;
            }
        }

        // The pairs most likely to be one person first: those that share the
        // most, then the larger groups.
        uksort($reasonsOf, fn (string $a, string $b): int => [count($reasonsOf[$b]), substr_count($b, ',')] <=> [count($reasonsOf[$a]), substr_count($a, ',')]
            ?: strcmp($a, $b));

        $truncated = count($reasonsOf) > self::MAX_GROUPS;
        $reasonsOf = array_slice($reasonsOf, 0, self::MAX_GROUPS, true);

        $ids = array_unique(array_merge(...array_map(
            fn (string $key): array => array_map(intval(...), explode(',', $key)),
            array_keys($reasonsOf) ?: [''],
        )));

        $people = (clone $base)->whereIn('id', $ids)->with('department')->get()->keyBy('id');

        $groups = [];
        foreach ($reasonsOf as $key => $reasons) {
            $members = collect(explode(',', $key))
                ->map(fn (string $id): ?Employee => $people->get((int) $id))
                ->filter()
                ->sortBy('created_at')
                ->values();

            if ($members->count() > 1) {
                $groups[] = ['reasons' => $reasons, 'employees' => $members];
            }
        }

        return ['groups' => $groups, 'truncated' => $truncated];
    }

    /**
     * For each thing a person has only one of, the sets of employee ids that
     * share a value of it.
     *
     * Read as two columns and grouped here, not with GROUP BY: a phone is
     * the same number in several spellings, and one rule for "the same"
     * (the resolver's) is better than a second written in SQL.
     *
     * @param  Builder<Employee>  $base
     * @return array<string, list<list<int>>>
     */
    private function sharing(Builder $base): array
    {
        $lower = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? mb_strtolower(trim($value)) : null;

        return [
            self::NATIONAL_ID => $this->groups($base, ['national_id_hash'], fn (Employee $e): ?string => $e->national_id_hash),
            self::EMAIL => $this->groups($base, ['email'], fn (Employee $e): ?string => $lower($e->email)),
            self::PHONE => $this->groups($base, ['phone'], function (Employee $e): ?string {
                $phone = is_string($e->phone) && trim($e->phone) !== '' ? $e->phone : null;

                return $phone === null ? null : preg_replace('/\D+/', '', (string) EthiopianPhone::canonicalOrRaw($phone));
            }),
            self::BADGE => $this->groups($base, ['badge_number'], fn (Employee $e): ?string => $lower($e->badge_number)),
            self::NAME_AND_BIRTH_DATE => $this->groups($base, ['name', 'date_of_birth'], function (Employee $e) use ($lower): ?string {
                $name = $lower($e->name);
                $born = $e->getAttribute('date_of_birth');

                return $name === null || $born === null ? null : $name.'|'.substr((string) $born, 0, 10);
            }),
        ];
    }

    /**
     * @param  Builder<Employee>  $base
     * @param  list<string>  $columns
     * @param  callable(Employee): (string|null)  $key
     * @return list<list<int>>
     */
    private function groups(Builder $base, array $columns, callable $key): array
    {
        $byValue = [];
        $query = clone $base;
        foreach ($columns as $column) {
            $query->whereNotNull($column);
        }

        foreach ($query->get(['id', ...$columns]) as $employee) {
            $value = $key($employee);
            if ($value !== null && $value !== '') {
                $byValue[$value][] = (int) $employee->id;
            }
        }

        return array_values(array_filter($byValue, fn (array $ids): bool => count($ids) > 1));
    }
}
