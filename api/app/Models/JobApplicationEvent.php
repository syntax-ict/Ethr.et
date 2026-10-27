<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of an application's history. Written once, never edited; erasing
 * an application blanks the notes and keeps the line.
 *
 * @property int $id
 * @property string $public_id
 * @property int $job_application_id
 * @property string $type
 * @property string|null $from_stage
 * @property string|null $to_stage
 * @property string|null $note
 * @property int|null $score
 * @property Carbon|null $scheduled_at
 * @property int|null $actor_id
 * @property Carbon|null $created_at
 * @property-read User|null $actor
 */
class JobApplicationEvent extends Model
{
    use BelongsToTenant, HasPublicId;

    public const TYPES = ['created', 'stage_changed', 'note', 'interview', 'offer_approved', 'hired', 'erased'];

    protected $fillable = [
        'tenant_id',
        'job_application_id',
        'type',
        'from_stage',
        'to_stage',
        'note',
        'score',
        'scheduled_at',
        'actor_id',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'scheduled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<JobApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
