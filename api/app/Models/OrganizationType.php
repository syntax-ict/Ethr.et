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
 * A global catalogue, edited from the platform console: no tenant owns a
 * type, and adding one is a row, not a deploy.
 *
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string $name_am
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_active
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
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return list<string> the codes an organisation may be given */
    public static function activeCodes(): array
    {
        return self::query()->where('is_active', true)->orderBy('sort_order')->pluck('code')->all();
    }
}
