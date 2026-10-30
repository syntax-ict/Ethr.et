<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Department;

/**
 * Finds an organisation's department from what a person typed: its code, or
 * its English or Amharic name, ignoring case and surrounding spaces. A name
 * two departments share matches neither, so the caller can ask for the code
 * instead of guessing. The same rule as the employee import's relation
 * columns (EmployeeImporter::relationLookup), for the guided setup's pasted
 * list, which used to drop the department entirely.
 */
final class DepartmentMatch
{
    /**
     * @param  array<string, int>  $codes
     * @param  array<string, list<int>>  $names
     */
    private function __construct(
        private readonly array $codes,
        private readonly array $names,
    ) {}

    public static function forTenant(int $tenantId): self
    {
        $codes = [];
        $names = [];

        foreach (Department::query()->where('tenant_id', $tenantId)->get(['id', 'code', 'name', 'name_am']) as $department) {
            if (is_string($department->code) && trim($department->code) !== '') {
                $codes[self::key($department->code)] = (int) $department->id;
            }
            foreach (['name', 'name_am'] as $column) {
                $name = $department->getAttribute($column);
                if (is_string($name) && trim($name) !== '') {
                    $key = self::key($name);
                    $names[$key] = array_values(array_unique([...($names[$key] ?? []), (int) $department->id]));
                }
            }
        }

        return new self($codes, $names);
    }

    /** The department's id, or null when nothing, or more than one, matches. */
    public function id(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $key = self::key($value);
        if (isset($this->codes[$key])) {
            return $this->codes[$key];
        }

        $ids = $this->names[$key] ?? [];

        return count($ids) === 1 ? $ids[0] : null;
    }

    /** Whether the value names more than one department and no code. */
    public function ambiguous(string $value): bool
    {
        $key = self::key($value);

        return ! isset($this->codes[$key]) && count($this->names[$key] ?? []) > 1;
    }

    private static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
