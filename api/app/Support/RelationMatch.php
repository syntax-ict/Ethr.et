<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Eloquent\Model;

/**
 * Finds one of an organisation's departments, branches or positions from what
 * a person typed: its code, or its English or Amharic name, ignoring case and
 * surrounding spaces. A name two of them share matches neither, so the caller
 * can ask for the code instead of guessing.
 *
 * DepartmentMatch held this rule for departments alone, and a staged list
 * could name nothing else. It now reads through here.
 */
final class RelationMatch
{
    /**
     * @param  array<string, int>  $codes
     * @param  array<string, list<int>>  $names
     */
    private function __construct(
        private readonly array $codes,
        private readonly array $names,
    ) {}

    public static function departments(int $tenantId): self
    {
        return self::of(Department::class, ['name', 'name_am'], $tenantId);
    }

    public static function branches(int $tenantId): self
    {
        return self::of(Branch::class, ['name', 'name_am'], $tenantId);
    }

    public static function positions(int $tenantId): self
    {
        return self::of(Position::class, ['title', 'title_am'], $tenantId);
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $nameColumns
     */
    private static function of(string $model, array $nameColumns, int $tenantId): self
    {
        $codes = [];
        $names = [];

        foreach ($model::query()->where('tenant_id', $tenantId)->get(['id', 'code', ...$nameColumns]) as $record) {
            $code = $record->getAttribute('code');
            if (is_string($code) && trim($code) !== '') {
                $codes[self::key($code)] = (int) $record->getKey();
            }
            foreach ($nameColumns as $column) {
                $name = $record->getAttribute($column);
                if (is_string($name) && trim($name) !== '') {
                    $key = self::key($name);
                    $names[$key] = array_values(array_unique([...($names[$key] ?? []), (int) $record->getKey()]));
                }
            }
        }

        return new self($codes, $names);
    }

    /** The record's id, or null when nothing, or more than one, matches. */
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

    /** Whether the value names more than one record and no code. */
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
