<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The name and code a duplicated configuration record gets: "Night (copy)",
 * then "Night (copy 2)", and the matching "NIGHT_COPY", "NIGHT_COPY2" — the
 * first one not already taken. `$taken` answers for the caller's own table
 * and tenant, including soft-deleted rows where a unique index still covers
 * them.
 */
final class CopyName
{
    /** @param callable(string): bool $taken */
    public static function name(string $original, callable $taken, int $maxLength = 255): string
    {
        for ($n = 1; $n < 1000; $n++) {
            $suffix = $n === 1 ? ' (copy)' : " (copy {$n})";
            $candidate = mb_substr($original, 0, $maxLength - mb_strlen($suffix)).$suffix;

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        return mb_substr($original, 0, $maxLength - 27).' '.strtolower((string) Str::ulid());
    }

    /** @param callable(string): bool $taken */
    public static function code(string $original, callable $taken, int $maxLength = 30): string
    {
        for ($n = 1; $n < 1000; $n++) {
            $suffix = $n === 1 ? '_copy' : "_copy{$n}";
            $candidate = substr($original, 0, $maxLength - strlen($suffix)).$suffix;

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        return substr(strtolower((string) Str::ulid()), 0, $maxLength);
    }
}
