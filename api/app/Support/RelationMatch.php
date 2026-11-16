<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Grade;
use App\Models\Position;
use Illuminate\Database\Eloquent\Model;

/**
 * Finds one of an organisation's departments, branches, positions, grades or
 * cost centres from what a person typed: its code, or its English or Amharic
 * name, ignoring case and surrounding spaces. A grade has no code and no
 * Amharic name, so it is found by its name alone. A name two of them share matches neither, so the caller
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
        private readonly bool $paths = false,
    ) {}

    public static function departments(int $tenantId): self
    {
        return self::of(Department::class, ['name', 'name_am'], $tenantId, paths: true);
    }

    public static function branches(int $tenantId): self
    {
        return self::of(Branch::class, ['name', 'name_am'], $tenantId);
    }

    public static function positions(int $tenantId): self
    {
        return self::of(Position::class, ['title', 'title_am'], $tenantId);
    }

    public static function grades(int $tenantId): self
    {
        return self::of(Grade::class, ['name'], $tenantId, hasCode: false);
    }

    public static function costCenters(int $tenantId): self
    {
        return self::of(CostCenter::class, ['name'], $tenantId);
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $nameColumns
     */
    private static function of(string $model, array $nameColumns, int $tenantId, bool $hasCode = true, bool $paths = false): self
    {
        $codes = [];
        $names = [];

        foreach ($model::query()->where('tenant_id', $tenantId)->get(['id', ...($hasCode ? ['code'] : []), ...$nameColumns]) as $record) {
            $code = $hasCode ? $record->getAttribute('code') : null;
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

        return new self($codes, $names, $paths);
    }

    /** The record's id, or null when nothing, or more than one, matches. */
    public function id(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->lookup($value)[0];
    }

    /** Whether the value names more than one record and no code. */
    public function ambiguous(string $value): bool
    {
        return $this->lookup($value)[1];
    }

    /**
     * What a value names: the one record, or whether it names several. A
     * department written as a path ("Head office / Finance", "A > B") that
     * matches nothing whole is looked up by its last part, so a name that
     * itself holds a slash is still found as written first.
     *
     * @return array{0: int|null, 1: bool}
     */
    private function lookup(string $value): array
    {
        $key = self::key($value);
        if (isset($this->codes[$key])) {
            return [$this->codes[$key], false];
        }

        $ids = $this->names[$key] ?? [];
        if (count($ids) === 1) {
            return [$ids[0], false];
        }
        if (count($ids) > 1 || ! $this->paths) {
            return [null, count($ids) > 1];
        }

        $parts = array_values(array_filter(array_map(trim(...), preg_split('#[/\\\\>]#u', $value) ?: []), fn (string $part): bool => $part !== ''));
        $last = $parts === [] ? null : $parts[array_key_last($parts)];
        if ($last === null || self::key($last) === $key) {
            return [null, false];
        }

        $key = self::key($last);
        if (isset($this->codes[$key])) {
            return [$this->codes[$key], false];
        }
        $ids = $this->names[$key] ?? [];

        return [count($ids) === 1 ? $ids[0] : null, count($ids) > 1];
    }

    private static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
