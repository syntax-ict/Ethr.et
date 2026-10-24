<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MarketingImage;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * One row of a landing-page section: a problem and ETHR's answer, a
 * capability and its value, a step, an industry, a trust point, a line
 * under the call to action. Which of its columns are used is decided by
 * the section's kind in App\Support\MarketingContent.
 *
 * `slug` is stable across re-seeds and across databases, which is what lets
 * the seeder be first-run content (never overwriting an operator's edit) and
 * lets the frontend pick a drawn scene for a row that has no picture.
 *
 * Global, like its section.
 *
 * @property int $id
 * @property string $public_id
 * @property string $section_kind
 * @property string $slug
 * @property int $sort_order
 * @property bool $is_published
 * @property string|null $icon
 * @property string $title
 * @property string|null $title_am
 * @property string|null $body
 * @property string|null $body_am
 * @property string|null $detail
 * @property string|null $detail_am
 * @property string|null $link_path
 * @property string|null $image_path
 * @property string|null $image_version
 * @property int|null $image_width
 * @property int|null $image_height
 * @property array<int, string>|null $image_variants
 * @property string|null $image_alt
 * @property string|null $image_alt_am
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MarketingItem extends Model
{
    use HasPublicId;

    protected $fillable = [
        'section_kind',
        'slug',
        'sort_order',
        'is_published',
        'icon',
        'title',
        'title_am',
        'body',
        'body_am',
        'detail',
        'detail_am',
        'link_path',
        'image_path',
        'image_version',
        'image_width',
        'image_height',
        'image_variants',
        'image_alt',
        'image_alt_am',
    ];

    protected $hidden = [
        'id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'sort_order' => 'integer',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'image_variants' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (): void {
            Cache::forget(MarketingSection::PUBLIC_CACHE_KEY);
        });
        static::deleted(function (): void {
            Cache::forget(MarketingSection::PUBLIC_CACHE_KEY);
        });
    }

    /** @return BelongsTo<MarketingSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(MarketingSection::class, 'section_kind', 'kind');
    }

    public function hasImage(): bool
    {
        return $this->image_path !== null && $this->image_version !== null;
    }

    /** The widths this item's picture was also encoded at. */
    public function imageVariantWidths(): array
    {
        return MarketingImage::variantWidths($this->image_variants);
    }
}
