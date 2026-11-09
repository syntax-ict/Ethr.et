<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A kind of organisation whose staff the law treats alike: private, public
 * enterprise, government civil service, charity, international organisation,
 * defence and police. Statutory rules may be scoped to one
 * (StatutoryParameter, TaxBracket, PensionRule), and an organisation's type
 * decides which of those apply to it.
 *
 * A global catalogue: no tenant owns a type, and adding one is a row, not a
 * deploy. Rows are written by migrations.
 *
 * A type may name a `parent_code` (one level deep): it owns no rule rows of
 * its own until a sourced rule differs, and resolves through its parent's
 * meanwhile. `group_code` groups the picker: private, non_profit, public,
 * international.
 *
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string $name_am
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_active
 * @property string|null $parent_code
 * @property string $group_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrganizationType extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'code',
        'name',
        'name_am',
        'description',
        'sort_order',
        'is_active',
        'parent_code',
        'group_code',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public const GROUPS = ['private', 'non_profit', 'public', 'international'];

    /**
     * Types whose pension coverage the accountant has not yet confirmed
     * (ACCOUNTANT-SIGN-OFF.md row 11, research P9): each is paid as its
     * parent meanwhile, and its organisations see an advisory saying so.
     */
    public const COVERAGE_UNCONFIRMED = ['sole_proprietor', 'cooperative', 'religious_organization', 'international_organization', 'diplomatic_mission'];

    /**
     * The codes a type's rules resolve through, most specific first: the
     * type, then its parent if it has one. Empty for no type. Callers memoize
     * (App\Services\Tenancy\ProfileContext); this reads the row each time.
     *
     * @return list<string>
     */
    public static function lineage(?string $code): array
    {
        if ($code === null || $code === '') {
            return [];
        }

        $parent = self::query()->where('code', $code)->value('parent_code');

        return is_string($parent) && $parent !== '' ? [$code, $parent] : [$code];
    }

    /** @return list<string> the codes an organisation may be given */
    public static function activeCodes(): array
    {
        return self::query()->where('is_active', true)->orderBy('sort_order')->pluck('code')->all();
    }
}
