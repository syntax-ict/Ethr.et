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
