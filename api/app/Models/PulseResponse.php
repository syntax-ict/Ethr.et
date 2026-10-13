<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One weekly pulse answer. It names no one: there is no user, employee or
 * time of day on it, and PulseParticipation - which does name the user -
 * shares no key with it.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $week
 * @property int $score
 * @property list<string>|null $tags
 * @property string|null $comment
 * @property int|null $department_id
 * @property Carbon $submitted_on
 */
class PulseResponse extends Model
{
    use BelongsToTenant, HasPublicId;

    public const TAGS = ['workload', 'manager', 'team', 'pay', 'growth', 'recognition', 'safety', 'facilities', 'tools', 'wellbeing'];

    public $timestamps = false;

    protected $fillable = ['public_id', 'tenant_id', 'week', 'score', 'tags', 'comment', 'department_id', 'submitted_on'];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'tags' => 'array',
            'submitted_on' => 'date',
        ];
    }

    /** The ISO week a moment falls in, e.g. 2026-W42. */
    public static function weekOf(Carbon $moment): string
    {
        return $moment->format('o-\WW');
    }
}
