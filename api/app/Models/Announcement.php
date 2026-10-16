<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property string $title
 * @property string $body
 * @property string|null $category
 * @property string $priority
 * @property string $target_type
 * @property Carbon|null $published_at
 * @property Carbon|null $notified_at
 * @property Carbon|null $expires_at
 * @property bool $show_on_landing
 * @property bool $featured
 */
class Announcement extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    /**
     * What a public reader can filter the organisation's news by. A fixed list
     * rather than free text, so the filter is short, translated and the same
     * for every organisation.
     */
    public const CATEGORIES = ['news', 'notice', 'event', 'deadline'];

    protected $fillable = [
        'tenant_id',
        'title',
        'body',
        'category',
        'priority',
        'target_type',
        'target_id',
        'published_by',
        'published_at',
        'notified_at',
        'expires_at',
        'show_on_landing',
        'featured',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'notified_at' => 'datetime',
            'expires_at' => 'datetime',
            'target_id' => 'integer',
            'show_on_landing' => 'boolean',
            'featured' => 'boolean',
        ];
    }

    /**
     * Where the announcement stands, from its two dates: a draft has no
     * publication date, a scheduled one has a future one, an archived one has
     * passed its expiry. The public page shows only `published`.
     */
    public function status(): string
    {
        if ($this->published_at === null) {
            return 'draft';
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return 'archived';
        }

        return $this->published_at->isFuture() ? 'scheduled' : 'published';
    }

    /**
     * What the organisation's public website may show: published, not yet
     * expired, marked for the public by its author, and addressed to the whole
     * organisation. One definition, read by the feed, the news list, a single
     * article, the server-rendered page head and the sitemap, so none of them
     * can come to disagree about what is public.
     *
     * @param  Builder<Announcement>  $query
     * @return Builder<Announcement>
     */
    public function scopePubliclyListed(Builder $query): Builder
    {
        return $query->published()
            ->notExpired()
            ->where('show_on_landing', true)
            ->where('target_type', 'all');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Who may read an announcement. Managers see every one, since they author
     * and edit them; everyone else sees those for the whole tenant plus those
     * aimed at their own department or branch. NotifyAnnouncementAudienceJob
     * already honoured the target, but the list and the single read did not,
     * so a department-only announcement was readable by the whole tenant.
     *
     * @param  Builder<Announcement>  $query
     * @return Builder<Announcement>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->hasPermission('announcement.manage')) {
            return $query;
        }

        $employee = $user?->employee;

        return $query->where(function (Builder $q) use ($employee): void {
            $q->where('target_type', 'all');

            if ($employee?->department_id !== null) {
                $q->orWhere(fn (Builder $q) => $q->where('target_type', 'department')
                    ->where('target_id', $employee->department_id));
            }

            if ($employee?->branch_id !== null) {
                $q->orWhere(fn (Builder $q) => $q->where('target_type', 'branch')
                    ->where('target_id', $employee->branch_id));
            }
        });
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }
}
