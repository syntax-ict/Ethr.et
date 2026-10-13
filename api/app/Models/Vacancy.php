<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An open position the organisation advertises - to its own employees
 * (`internal`) or also on its public sign-in page (`public`).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int|null $department_id
 * @property int|null $branch_id
 * @property string $title
 * @property string $employment_type
 * @property string|null $location
 * @property string $description
 * @property string|null $requirements
 * @property int $positions
 * @property string $visibility
 * @property string $status
 * @property Carbon|null $published_at
 * @property Carbon|null $closes_on
 * @property string|null $apply_email
 * @property string|null $apply_url
 * @property int|null $created_by
 * @property-read Department|null $department
 * @property-read Branch|null $branch
 */
class Vacancy extends Model
{
    use BelongsToTenant, HasPublicId, SoftDeletes;

    public const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'contract', 'temporary', 'internship'];

    public const VISIBILITIES = ['internal', 'public'];

    public const STATUSES = ['draft', 'open', 'closed'];

    protected $fillable = [
        'tenant_id',
        'department_id',
        'branch_id',
        'title',
        'employment_type',
        'location',
        'description',
        'requirements',
        'positions',
        'visibility',
        'status',
        'published_at',
        'closes_on',
        'apply_email',
        'apply_url',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'positions' => 'integer',
            'published_at' => 'datetime',
            'closes_on' => 'date',
        ];
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Advertised right now: open, published, and not past its closing date.
     * A closing date is the last day applications are taken, so it still
     * counts on that day.
     *
     * @param  Builder<Vacancy>  $query
     * @return Builder<Vacancy>
     */
    public function scopeAdvertised(Builder $query): Builder
    {
        return $query->where('status', 'open')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(function (Builder $q): void {
                $q->whereNull('closes_on')->orWhereDate('closes_on', '>=', Carbon::today());
            });
    }

    /**
     * @param  Builder<Vacancy>  $query
     * @return Builder<Vacancy>
     */
    public function scopePubliclyListed(Builder $query): Builder
    {
        return $query->advertised()->where('visibility', 'public');
    }
}
