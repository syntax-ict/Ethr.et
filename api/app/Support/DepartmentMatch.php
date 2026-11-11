<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Finds an organisation's department from what a person typed: its code, or
 * its English or Amharic name, ignoring case and surrounding spaces. A name
 * two departments share matches neither, so the caller can ask for the code
 * instead of guessing. The same rule as the employee import's relation
 * columns (EmployeeImporter::relationLookup), for the guided setup's pasted
 * list, which used to drop the department entirely. The rule itself is
 * RelationMatch's, which branches and positions share.
 */
final class DepartmentMatch
{
    private function __construct(private readonly RelationMatch $departments) {}

    public static function forTenant(int $tenantId): self
    {
        return new self(RelationMatch::departments($tenantId));
    }

    /** The department's id, or null when nothing, or more than one, matches. */
    public function id(?string $value): ?int
    {
        return $this->departments->id($value);
    }

    /** Whether the value names more than one department and no code. */
    public function ambiguous(string $value): bool
    {
        return $this->departments->ambiguous($value);
    }
}
