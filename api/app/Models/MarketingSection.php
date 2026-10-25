<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MarketingContent;
use App\Support\MarketingImage;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * One section of the public landing page: its heading, its order, whether it
 * is shown, and (for the hero) its picture. The items under it are
 * {@see MarketingItem}.
 *
 * Global by design, like Plan and PlatformSetting: one marketing site, read by
 * every visitor, owned by the platform operator. On TenantIsolationTest's
 * allow-list for that reason.
 *
 * The `@property` block is what Scramble and Larastan read for nullability;
 * see Plan.php for why it is not decoration.
 *
 * @property int $id
 * @property string $public_id
 * @property string $kind
 * @property string $heading
 * @property string|null $heading_am
 * @property string|null $subheading
 * @property string|null $subheading_am
 * @property bool $is_published
 * @property int $sort_order
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
class MarketingSection extends Model
{
    use HasPublicId;

    /**
     * Cache key for the public content. Fixed key, integer TTL, forgotten
     * from both models' own hooks so any writer invalidates it, including a
     * seeder or a tinker session. Never Cache::tags(): the file and database
     * stores cannot tag.
     */
    public const PUBLIC_CACHE_KEY = 'platform.marketing_content';

    /** Seconds. */
    public const PUBLIC_CACHE_TTL = 300;

    protected $fillable = [
        'kind',
        'heading',
        'heading_am',
        'subheading',
        'subheading_am',
        'is_published',
        'sort_order',
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
            Cache::forget(self::PUBLIC_CACHE_KEY);
        });
        static::deleted(function (): void {
            Cache::forget(self::PUBLIC_CACHE_KEY);
        });
    }

    /** @return HasMany<MarketingItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MarketingItem::class, 'section_kind', 'kind')->orderBy('sort_order');
    }

    /** The hero's picture as a page draws it; see MarketingImage. */
    public function hasImage(): bool
    {
        return $this->image_path !== null && $this->image_version !== null;
    }

    /** The widths this section's picture was also encoded at. */
    public function imageVariantWidths(): array
    {
        return MarketingImage::variantWidths($this->image_variants);
    }

    /** @return array{fields: list<string>, max_items: int, images: bool, section_image: bool} */
    public function spec(): array
    {
        return MarketingContent::spec($this->kind);
    }
}
