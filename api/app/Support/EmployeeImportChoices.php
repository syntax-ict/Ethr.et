<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NationalityStatus;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Eloquent\Model;

/**
 * What each choice column of the employee template may hold, for the
 * dropdowns of the workbook an organisation downloads.
 *
 * The fixed lists are the ones EmployeeImportRow's rules accept. The
 * organisation's own are its departments, branches and positions as a list
 * would name them: by name, and by code where two share a name, because a
 * shared name is refused as ambiguous when the list is read (RelationMatch).
 * A dropdown that offered a value the import then refused would be worse than
 * no dropdown.
 */
final class EmployeeImportChoices
{
    /** The most entries one dropdown carries. A longer list is typed, as before. */
    public const LIMIT = 500;

    /**
     * @return array{gender: list<string>, status: list<string>, nationality_status: list<string>, marital_status: list<string>, department: list<string>, branch: list<string>, position: list<string>}
     */
    public static function forTenant(?int $tenantId): array
    {
        return [
            'gender' => ['male', 'female'],
            'status' => EmployeeImportRow::STATUSES,
            'nationality_status' => NationalityStatus::values(),
            'marital_status' => ['single', 'married', 'divorced', 'widowed'],
            'department' => self::named(Department::class, 'name', $tenantId),
            'branch' => self::named(Branch::class, 'name', $tenantId),
            'position' => self::named(Position::class, 'title', $tenantId),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @return list<string>
     */
    private static function named(string $model, string $nameColumn, ?int $tenantId): array
    {
        if ($tenantId === null) {
            return [];
        }

        $records = $model::query()
            ->where('tenant_id', $tenantId)
            ->orderBy($nameColumn)
            ->limit(self::LIMIT)
            ->get([$nameColumn, 'code']);

        $shared = [];
        foreach ($records as $record) {
            $key = mb_strtolower(trim((string) $record->getAttribute($nameColumn)));
            $shared[$key] = ($shared[$key] ?? 0) + 1;
        }

        $choices = [];
        foreach ($records as $record) {
            $name = trim((string) $record->getAttribute($nameColumn));
            $code = trim((string) $record->getAttribute('code'));

            if ($name !== '' && $shared[mb_strtolower($name)] === 1) {
                $choices[] = $name;
            } elseif ($code !== '') {
                $choices[] = $code;
            }
        }

        return array_values(array_unique($choices));
    }
}
