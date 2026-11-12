<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\EmployeeImportRow;
use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * How one of an organisation's other systems names its columns, kept so its
 * next export is read without being asked again. See the migration.
 *
 * @property string $name
 * @property string $slug
 * @property list<array{header: string, column: string|null}> $columns
 * @property string|null $calendar
 */
class ImportMapping extends Model
{
    use BelongsToTenant, HasPublicId;

    /** The most saved sources one organisation keeps. Each is a line in a menu. */
    public const LIMIT = 30;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'name',
        'slug',
        'columns',
        'calendar',
        'created_by',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    /**
     * The name a source's ids are kept against. Latin letters and digits of
     * the name; a name with none (one written in Amharic) gets a short
     * fingerprint of itself, so the same name always gives the same slug.
     */
    public static function slugFor(string $name): string
    {
        $slug = Str::limit(Str::slug($name), 50, '');

        return $slug !== '' ? $slug : 'source-'.substr(sha1(mb_strtolower(trim($name))), 0, 12);
    }

    /**
     * The columns, each entry read out as header then column.
     *
     * MySQL 8 hands a stored JSON object back with its keys in its own order
     * (`column` before `header`), so a source read from the database
     * answered differently from the one just saved. MariaDB keeps the text
     * as written. Read here, every reader of `columns` gets the same thing
     * on every engine.
     *
     * @return Attribute<list<array{header: string, column: string|null}>, array<int, mixed>>
     */
    protected function columns(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                $entries = [];
                foreach ((array) json_decode($value ?? '[]', true) as $entry) {
                    if (is_array($entry) && is_string($entry['header'] ?? null)) {
                        $column = $entry['column'] ?? null;
                        $entries[] = ['header' => $entry['header'], 'column' => is_string($column) ? $column : null];
                    }
                }

                return $entries;
            },
            set: fn (array $value): string => (string) json_encode(array_values($value)),
        );
    }

    /**
     * What each of the source's column names is, keyed as the reader keys a
     * column name (EmployeeImportRow::headerKey). Null is a column left out.
     *
     * @return array<string, string|null>
     */
    public function map(): array
    {
        return self::mapOf($this->columns);
    }

    /**
     * @param  array<int, mixed>  $columns  a list of {header, column}
     * @return array<string, string|null>
     */
    public static function mapOf(array $columns): array
    {
        $map = [];
        foreach ($columns as $entry) {
            if (! is_array($entry) || ! is_string($entry['header'] ?? null)) {
                continue;
            }

            $key = EmployeeImportRow::headerKey($entry['header']);
            $column = $entry['column'] ?? null;
            // A column name given twice keeps its first answer.
            if ($key !== '' && ! array_key_exists($key, $map)) {
                $map[$key] = is_string($column) && in_array($column, EmployeeImportRow::MAPPABLE, true) ? $column : null;
            }
        }

        return $map;
    }
}
