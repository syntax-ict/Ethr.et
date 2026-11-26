<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One entry of a pick list the forms offer: a region, a sub-city, a town or a
 * bank. Which list is `kind`; `code` is the stable key a record stores, and
 * never changes once shipped. A town or sub-city names its region in
 * `parent_code`.
 *
 * A global catalogue: no tenant owns a row, and the table carries no
 * `tenant_id`. Rows are written by `ReferenceCatalogue::sync()`, from the
 * files in `database/data/reference/`, by the migration and the seeder; a row
 * that leaves the files is deactivated, never deleted.
 *
 * @property int $id
 * @property string $public_id
 * @property string $kind
 * @property string $code
 * @property string|null $parent_code
 * @property string $name
 * @property string $name_am
 * @property string|null $name_om
 * @property int $sort_order
 * @property bool $is_active
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReferenceItem extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'kind',
        'code',
        'parent_code',
        'name',
        'name_am',
        'name_om',
        'sort_order',
        'is_active',
        'meta',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    /**
     * @param  Builder<ReferenceItem>  $query
     * @return Builder<ReferenceItem>
     */
    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /**
     * @param  Builder<ReferenceItem>  $query
     * @return Builder<ReferenceItem>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
