<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * An array stored encrypted, in a column that used to hold it as plain JSON.
 *
 * Laravel's own `encrypted:array` throws on a row written before the column
 * was encrypted. This reads both: a value that starts with `{` or `[` is JSON
 * from before, and anything else is ciphertext (an encrypted payload is
 * base64 and never starts with either). Everything written is encrypted, so
 * the older rows disappear as they are rewritten or expire, with no backfill.
 *
 * @implements CastsAttributes<array<array-key, mixed>|null, array<array-key, mixed>|null>
 */
final class EncryptedJson implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<array-key, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $json = $value[0] === '{' || $value[0] === '[' ? $value : Crypt::decryptString($value);
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
