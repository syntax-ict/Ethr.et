<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A subscription tier. Global — no `tenant_id`, per the global model list in
 * CLAUDE.md.
 *
 * The `@property` block is not decoration. Larastan infers property types from
 * the migration, where `features` is `json`, so without this it reads as
 * `string|null` and every array operation on it looks like a type error while
 * being correct at runtime — the `array` cast in casts() is invisible to it.
 * `PlanFeatureService` is the caller that surfaced this.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property int $price_cents
 * @property int $max_employees
 * @property int $max_branches
 * @property int $max_devices
 * @property list<string>|null $features
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Plan extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'name',
        'slug',
        'price_cents',
        'max_employees',
        'max_branches',
        'max_devices',
        'features',
        'is_active',
        'sort_order',
    ];

    protected $hidden = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'max_employees' => 'integer',
            'max_branches' => 'integer',
            'max_devices' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
