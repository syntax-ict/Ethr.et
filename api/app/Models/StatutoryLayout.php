<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One dated version of a monthly statutory schedule's layout: the agency it
 * goes to, its title and its columns. Platform-wide — the forms are national —
 * so it carries no tenant. A new form version is a new row, added from the
 * platform console (AdminStatutoryLayoutController), not a deploy.
 *
 * @property int $id
 * @property string $public_id
 * @property string $schedule
 * @property string|null $scheme
 * @property string $agency
 * @property string $title
 * @property array<int, mixed> $columns
 * @property string|null $note
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StatutoryLayout extends Model
{
    use HasPublicId;

    protected $fillable = [
        'public_id',
        'schedule',
        'scheme',
        'agency',
        'title',
        'columns',
        'note',
        'effective_from',
        'effective_to',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
