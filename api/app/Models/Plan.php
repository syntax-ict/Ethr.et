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
 * The three limits are `int|null`, and that had to be said here rather than
 * inferred. `make_plan_limits_nullable` made the columns nullable and the
 * seeder now writes null for an uncapped plan — but Scramble builds the public
 * OpenAPI schema from this block, so while it still read `int` the contract
 * promised a number on three fields the API can send null for. Nothing caught
 * it: the drift gate compares the generated file against the committed one, and
 * neither moved. The spec just quietly stopped being true.
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
 * @property string $currency
 * @property string $billing_interval
 * @property string|null $description
 * @property string|null $description_am
 * @property int|null $max_employees
 * @property int|null $max_branches
 * @property int|null $max_devices
 * @property list<string>|null $features
 * @property list<string>|null $marketing_features
 * @property list<string>|null $marketing_features_am
 * @property bool $is_active
 * @property bool $is_public
 * @property bool $is_popular
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
        'description',
        'description_am',
        'price_cents',
        'currency',
        'billing_interval',
        'max_employees',
        'max_branches',
        'max_devices',
        'features',
        'marketing_features',
        'marketing_features_am',
        'is_active',
        'is_public',
        'is_popular',
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
            'marketing_features' => 'array',
            'marketing_features_am' => 'array',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_popular' => 'boolean',
        ];
    }
}
