<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The icons a tenant may put on a public section item.
 *
 * A closed list, and the closure is the security property. The name is
 * interpolated into `<use href="#icon-{name}">` in the rendered page, so free
 * text there is an injection point into an SVG reference — `../evil` or a full
 * URL would both be syntactically valid to a browser. An allow-list makes the
 * value unforgeable rather than merely escaped.
 *
 * The icons themselves ship as one inline SVG sprite in the layout. Not an icon
 * font, because the page deliberately makes no webfont request; not one file
 * per icon, because that is a request each; not a CDN, because `img-src 'self'`
 * and `default-src 'none'` forbid it and relaxing that to decorate a heading
 * would be a poor trade.
 *
 * `IconAllowListTest` asserts this list and the sprite match in both
 * directions, so an icon cannot be offered without existing, or drawn without
 * being reachable.
 */
final class PublicSectionIcons
{
    /**
     * Chosen for what Ethiopian organisations actually publish: public
     * services, notices, offices, documents, contact routes and facilities.
     *
     * @var list<string>
     */
    public const NAMES = [
        // Services and process
        'briefcase', 'clipboard', 'file-text', 'stamp', 'scale', 'shield',
        // People and places
        'users', 'user-tie', 'building', 'landmark', 'map-pin', 'globe',
        // Contact
        'phone', 'mail', 'message', 'clock', 'calendar',
        // Sectors
        'graduation-cap', 'stethoscope', 'bank', 'factory', 'bed', 'leaf',
        // Generic
        'star', 'check', 'info',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return self::NAMES;
    }

    public static function allows(?string $name): bool
    {
        return $name !== null && in_array($name, self::NAMES, true);
    }
}
