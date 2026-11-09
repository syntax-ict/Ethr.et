<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One published batch of statutory parameters: the label every platform row
 * it brought in carries, reported in each value's provenance. Publishing a
 * batch is a migration that adds dated rows; it never touches an
 * organisation's own rows, and no organisation pins a batch: the law in force
 * on a payroll date is the law applied.
 *
 * A global catalogue, written only by migrations.
 *
 * @property int $id
 * @property string $public_id
 * @property string $version
 * @property string|null $organization_type
 * @property Carbon $published_at
 * @property string|null $notes
 */
class ProfileVersion extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'version',
        'organization_type',
        'published_at',
        'notes',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }
}
