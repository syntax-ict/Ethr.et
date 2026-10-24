<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MarketingItem;
use App\Models\MarketingSection;

/**
 * How a landing-page picture is published, shared by an item and the hero
 * section: the public URL of the full image and of each narrower copy, as a
 * `srcset`, with the size the page reserves before it loads.
 *
 * One definition of the URL shape, read by the resources and by
 * MarketingImageController, which serves a file by owner, variant and
 * version and nothing else.
 */
final class MarketingImage
{
    /**
     * The picture as the page draws it, or null when the owner has none.
     * Relative, same-origin URLs, like the organisation's own cover.
     *
     * @return array{url: string, srcset: string, width: int, height: int, alt: string|null, alt_am: string|null}|null
     */
    public static function present(MarketingItem|MarketingSection $owner): ?array
    {
        if (! $owner->hasImage()) {
            return null;
        }

        $version = (string) $owner->image_version;
        $url = fn (string $variant): string => route('marketing.image', [$owner->public_id, $variant, $version], absolute: false);

        $srcset = [];
        foreach ($owner->imageVariantWidths() as $width) {
            $srcset[] = $url('image-'.$width).' '.$width.'w';
        }
        $srcset[] = $url('image').' '.(int) $owner->image_width.'w';

        return [
            'url' => $url('image'),
            'srcset' => implode(', ', $srcset),
            'width' => (int) $owner->image_width,
            'height' => (int) $owner->image_height,
            'alt' => $owner->image_alt,
            'alt_am' => $owner->image_alt_am,
        ];
    }

    /**
     * The stored path of the owner's picture at a variant, or null when
     * that variant was not encoded (a picture narrower than 1280 px has no
     * 1280 copy).
     */
    public static function pathFor(MarketingItem|MarketingSection $owner, string $variant): ?string
    {
        if (! $owner->hasImage()) {
            return null;
        }
        if ($variant === 'image') {
            return $owner->image_path;
        }
        $width = (int) substr($variant, strlen('image-'));
        $variants = is_array($owner->image_variants) ? $owner->image_variants : [];

        return $variants[$width] ?? $variants[(string) $width] ?? null;
    }

    /**
     * The widths a stored `image_variants` map holds, ascending. JSON keys
     * come back as strings, so they are cast here once.
     *
     * @return list<int>
     */
    public static function variantWidths(mixed $variants): array
    {
        if (! is_array($variants)) {
            return [];
        }
        $widths = array_map('intval', array_keys($variants));
        sort($widths);

        return $widths;
    }

    /**
     * The image columns as a stored upload fills them.
     *
     * @param  array{path: string, version: string, width: int, height: int, variants: array<int, string>}  $stored
     * @return array<string, mixed>
     */
    public static function columns(array $stored): array
    {
        return [
            'image_path' => $stored['path'],
            'image_version' => $stored['version'],
            'image_width' => $stored['width'],
            'image_height' => $stored['height'],
            'image_variants' => $stored['variants'],
        ];
    }

    /**
     * The image columns, cleared.
     *
     * @return array<string, null>
     */
    public static function emptyColumns(): array
    {
        return [
            'image_path' => null,
            'image_version' => null,
            'image_width' => null,
            'image_height' => null,
            'image_variants' => null,
        ];
    }

    /**
     * What the storage service needs to delete an owner's files.
     *
     * @return array{path: string, variants: array<int, string>}|null
     */
    public static function stored(MarketingItem|MarketingSection $owner): ?array
    {
        if (! $owner->hasImage()) {
            return null;
        }

        return [
            'path' => (string) $owner->image_path,
            'variants' => is_array($owner->image_variants) ? array_values(array_map('strval', $owner->image_variants)) : [],
        ];
    }
}
