<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The registry of the public landing page: which sections exist, what each
 * one's items may carry, and how many of them there can be.
 *
 * A typed registry rather than a block editor (PLATFORM_MANAGED_CONTENT_PLAN
 * D6). The page's anatomy is code, so a crawler and a visitor always get a
 * page that renders; what the platform operator owns is every value, the
 * order, the visibility and the pictures. Validation reads this class, so the
 * console cannot store a field a kind does not use or an eleventh step.
 */
final class MarketingContent
{
    public const KINDS = [
        'hero',
        'problems',
        'capabilities',
        'how_it_works',
        'industries',
        'trust',
        'cta',
    ];

    /**
     * Icon names an item may carry, as lucide-react spells them. The
     * frontend maps each to a component; a name outside this list has no
     * drawing, so it is refused at validation rather than rendered blank.
     *
     * No `fingerprint`: the audit of 2026-10-17 took every biometric claim
     * off the public site, and an icon is a claim too.
     */
    public const ICONS = [
        'clock',
        'wallet',
        'calendar-days',
        'wifi-off',
        'building-2',
        'languages',
        'users',
        'smartphone',
        'bar-chart-3',
        'shield',
        'landmark',
        'hospital',
        'factory',
        'graduation-cap',
        'hotel',
        'heart',
        'briefcase',
        'file-text',
        'map-pin',
        'lock',
        'scroll-text',
        'check-circle-2',
        'receipt',
        'qr-code',
        'bell',
        'clipboard-list',
        'calculator',
        'table',
        'signal-zero',
        'file-clock',
    ];

    /**
     * What each kind's items carry, how many there may be, and whether an
     * item or the section itself can hold a picture.
     *
     * @return array{fields: list<string>, max_items: int, images: bool, section_image: bool}
     */
    public static function spec(string $kind): array
    {
        return match ($kind) {
            'hero' => ['fields' => [], 'max_items' => 0, 'images' => false, 'section_image' => true],
            'problems' => ['fields' => ['icon', 'title', 'body', 'detail', 'link_path'], 'max_items' => 6, 'images' => true, 'section_image' => false],
            'capabilities' => ['fields' => ['icon', 'title', 'body', 'detail', 'link_path'], 'max_items' => 5, 'images' => true, 'section_image' => false],
            'how_it_works' => ['fields' => ['title', 'body'], 'max_items' => 4, 'images' => false, 'section_image' => false],
            'industries' => ['fields' => ['icon', 'title'], 'max_items' => 12, 'images' => false, 'section_image' => false],
            'trust' => ['fields' => ['icon', 'title', 'body'], 'max_items' => 6, 'images' => false, 'section_image' => false],
            'cta' => ['fields' => ['title'], 'max_items' => 3, 'images' => false, 'section_image' => false],
            default => throw new \InvalidArgumentException("Unknown marketing section kind: {$kind}"),
        };
    }

    /** Every text column an item has, in both languages. */
    public const ITEM_TEXT_FIELDS = ['title', 'title_am', 'body', 'body_am', 'detail', 'detail_am', 'image_alt', 'image_alt_am'];

    /** Every text column a section has, in both languages. */
    public const SECTION_TEXT_FIELDS = ['heading', 'heading_am', 'subheading', 'subheading_am', 'image_alt', 'image_alt_am'];

    /**
     * The widths an image is also encoded at, for `srcset`. The same two the
     * organisation cover uses, so one `<img sizes>` rule serves both.
     */
    public const IMAGE_VARIANT_WIDTHS = [1280, 640];
}
