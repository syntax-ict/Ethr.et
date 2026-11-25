<?php

declare(strict_types=1);

namespace App\Services\Migration;

/**
 * What HeldNumbers found: for each number asked about that a record holds, who
 * holds it and whether they are archived. Keys are lower case, as the
 * organisation's own comparison is.
 */
final class HeldSet
{
    /**
     * @param  array<string, array{id: int, archived: bool}>  $codes
     * @param  array<string, array{id: int, archived: bool}>  $badges
     */
    public function __construct(private array $codes, private array $badges) {}

    /** @return array{id: int, archived: bool}|null */
    public function code(mixed $code): ?array
    {
        return $this->codes[self::key($code)] ?? null;
    }

    /** @return array{id: int, archived: bool}|null */
    public function badge(mixed $badge): ?array
    {
        return $this->badges[self::key($badge)] ?? null;
    }

    /** @var array<int, list<string>> archived holders asked about: why each is in use, empty when not */
    private array $use = [];

    /**
     * The archived people holding one of the employee numbers asked about, by
     * id: who a corrected list's rows may bring back.
     *
     * @return list<int>
     */
    public function archivedCodeHolders(): array
    {
        $ids = [];

        foreach ($this->codes as $holder) {
            if ($holder['archived']) {
                $ids[$holder['id']] = $holder['id'];
            }
        }

        return array_values($ids);
    }

    /**
     * Record how an archived holder is in use (EmployeeArchiver::reasons()).
     * Everyone in `$asked` was asked, and is unused unless `$reasons` says so.
     *
     * @param  list<int>  $asked
     * @param  array<int, list<string>>  $reasons
     */
    public function recordUse(array $asked, array $reasons): void
    {
        foreach ($asked as $id) {
            $this->use[$id] = $reasons[$id] ?? [];
        }
    }

    /**
     * Why this archived person cannot be brought back by a list: empty when
     * nothing stops it, null when nobody asked.
     *
     * @return list<string>|null
     */
    public function useOf(int $employeeId): ?array
    {
        return $this->use[$employeeId] ?? null;
    }

    /**
     * Count a number a later row of the same chunk brings as held, once a
     * person has been made with it.
     */
    public function addBadge(mixed $badge, int $employeeId): void
    {
        $key = self::key($badge);

        if ($key !== '') {
            $this->badges[$key] ??= ['id' => $employeeId, 'archived' => false];
        }
    }

    private static function key(mixed $value): string
    {
        return is_scalar($value) ? mb_strtolower(trim((string) $value)) : '';
    }
}
