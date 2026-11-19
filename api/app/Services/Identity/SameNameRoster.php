<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Support\PersonName;

/**
 * An organisation's employees by full name, read once for a list being
 * staged, so a list brought in a second time is not added a second time.
 *
 * A name alone never decides who someone is (IdentityResolver weighs it at
 * 0.2, below "may be this person"), and nothing here changes that. What this
 * answers is narrower: is there already someone here under exactly this name,
 * in the department the row would put them in? A row that says yes is held
 * for a person to answer "this is them" or "a different person"; it is never
 * linked or added on its own.
 *
 * Deleted employees are not read. Leavers are, and a review screen says they
 * have left (IdentityResolver::candidate's `former_employee`).
 *
 * Built by IdentityResolver::sameNameRoster(), which states the tenant.
 */
final class SameNameRoster
{
    /**
     * @param  array<string, list<array{id: int, department_id: int|null}>>  $byName  normalised name => holders
     */
    public function __construct(private readonly array $byName) {}

    /**
     * The ids of the employees under this exact name (PersonName). When the
     * row names a department, only those in the department the row would put
     * the person in: `$departmentId`, or no department at all when the
     * organisation has none by that name, which is where the first copy of
     * the list put them.
     *
     * @return list<int>
     */
    public function holders(?string $name, bool $namesDepartment, ?int $departmentId): array
    {
        $key = PersonName::normalised($name);
        if ($key === '') {
            return [];
        }

        $ids = [];
        foreach ($this->byName[$key] ?? [] as $holder) {
            if (! $namesDepartment || $holder['department_id'] === $departmentId) {
                $ids[] = $holder['id'];
            }
        }

        return $ids;
    }
}
