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
 * @property array<string, mixed>|null $cover
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
            'cover' => 'array',
        ];
    }

    /**
     * The story's stored cover photo, or null when it has none or the entry is
     * not well formed. Read as `Tenant::brandAsset()` reads its own: a value
     * edited by hand must not put a half entry on a public page.
     *
     * @return array{path: string, version: string, width: int, height: int, variants: array<int, string>}|null
     */
    public function coverImage(): ?array
    {
        $cover = $this->cover;

        if (! is_array($cover)
            || ! is_string($cover['path'] ?? null)
            || ! is_string($cover['version'] ?? null)
            || ! is_int($cover['width'] ?? null)
            || ! is_int($cover['height'] ?? null)) {
            return null;
        }
        $cover['variants'] = is_array($cover['variants'] ?? null) ? $cover['variants'] : [];

        /** @var array{path: string, version: string, width: int, height: int, variants: array<int, string>} $cover */
        return $cover;
    }

    /**
     * The cover as a page draws it: the full image, a `srcset` over its
     * narrower copies, and its size, so the space it takes is reserved before
     * it loads. Relative, same-origin URLs, like the organisation's own cover.
     *
     * @return array{url: string, srcset: string, width: int, height: int}|null
     */
    public function coverPresented(string $tenantPublicId): ?array
    {
        $cover = $this->coverImage();
        if ($cover === null) {
            return null;
        }

        // The size is cast where it is returned below: Scramble types values
        // read from the stored JSON as strings otherwise.
        $url = fn (string $size): string => route('organisation.news-cover', [$tenantPublicId, $this->public_id, $size, $cover['version']], absolute: false);
        $srcset = [];
        foreach (array_keys($cover['variants']) as $width) {
            $srcset[] = $url('cover-'.$width).' '.$width.'w';
        }
        $srcset[] = $url('cover').' '.$cover['width'].'w';

        return [
            'url' => $url('cover'),
            'srcset' => implode(', ', $srcset),
            'width' => (int) $cover['width'],
            'height' => (int) $cover['height'],
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
