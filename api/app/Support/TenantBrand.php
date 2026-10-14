<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;

/**
 * An organisation's brand as it may be shown to anyone: its logos, mark,
 * cover photo and colours. Read by the public `/auth/tenant-context` (the
 * portal draws itself from it before sign-in) and by the settings screen,
 * so the two cannot disagree about what is in effect.
 *
 * Every image here is one the server re-encoded and serves itself, keyed by
 * a content hash; the only outside URL is the linked logo, which is https
 * or nothing. Colours leave only when well formed.
 */
final class TenantBrand
{
    /**
     * @return array{logo_url: string|null, logo_dark_url: string|null, mark_url: string|null, cover: array{url: string, srcset: string, width: int, height: int, focal_x: float, focal_y: float}|null, primary_color: string|null, secondary_color: string|null, accent_color: string|null}
     */
    public static function present(Tenant $tenant): array
    {
        $stored = $tenant->getAttribute('theme');
        $theme = is_array($stored) ? $stored : [];

        return [
            'logo_url' => $tenant->logoUrl(),
            'logo_dark_url' => $tenant->brandAssetUrl('logo_dark'),
            'mark_url' => $tenant->brandAssetUrl('mark'),
            'cover' => self::cover($tenant),
            'primary_color' => self::hexColour($theme['primary_color'] ?? null),
            'secondary_color' => self::hexColour($theme['secondary_color'] ?? null),
            'accent_color' => self::hexColour($theme['accent_color'] ?? null),
        ];
    }

    /**
     * The uploaded images alone, for the settings screen.
     *
     * @return array{logo_dark: array{url: string}|null, mark: array{url: string}|null, cover: array{url: string, srcset: string, width: int, height: int, focal_x: float, focal_y: float}|null}
     */
    public static function assets(Tenant $tenant): array
    {
        $logoDark = $tenant->brandAssetUrl('logo_dark');
        $mark = $tenant->brandAssetUrl('mark');

        return [
            'logo_dark' => $logoDark === null ? null : ['url' => $logoDark],
            'mark' => $mark === null ? null : ['url' => $mark],
            'cover' => self::cover($tenant),
        ];
    }

    /**
     * The cover as a page draws it: the image, every width it was encoded
     * at (`srcset`, narrowest first) and the point to keep in frame when the
     * hero crops it.
     *
     * @return array{url: string, srcset: string, width: int, height: int, focal_x: float, focal_y: float}|null
     */
    public static function cover(Tenant $tenant): ?array
    {
        $cover = $tenant->brandAsset('cover');
        $url = $tenant->brandAssetUrl('cover');
        if ($cover === null || $url === null) {
            return null;
        }

        $sources = [];
        foreach (array_keys($cover['variants'] ?? []) as $width) {
            $variant = $tenant->brandAssetUrl('cover', (int) $width);
            if ($variant !== null) {
                $sources[(int) $width] = $variant.' '.(int) $width.'w';
            }
        }
        $sources[$cover['width']] = $url.' '.$cover['width'].'w';
        ksort($sources);

        return [
            'url' => $url,
            'srcset' => implode(', ', $sources),
            'width' => (int) $cover['width'],
            'height' => (int) $cover['height'],
            'focal_x' => self::fraction($cover['focal']['x'] ?? null),
            'focal_y' => self::fraction($cover['focal']['y'] ?? null),
        ];
    }

    /**
     * The sign-in page is drawn in the organisation's colours before anyone
     * signs in, so the colours are public here as the name and logo already
     * were. Only well-formed values leave: anything else falls back to
     * ETHR's own.
     */
    public static function hexColour(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : null;
    }

    /** A focal coordinate: 0 to 1 along the axis, the centre when unset. */
    private static function fraction(mixed $value): float
    {
        return is_int($value) || is_float($value) ? round(min(1.0, max(0.0, (float) $value)), 3) : 0.5;
    }
}
