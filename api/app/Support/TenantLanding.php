<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;

/**
 * An organisation's sign-in page settings, as they may be shown to anyone.
 *
 * Read by the public `/auth/tenant-context` (the landing draws itself from
 * it before sign-in) and by the settings screen. Only values that pass the
 * same rules the settings request applies leave: a row written before a
 * rule existed, or edited by hand, cannot put a script URL or an unbounded
 * string on a public page.
 */
final class TenantLanding
{
    public const BACKGROUNDS = ['brand', 'gradient', 'image'];

    /**
     * What the page lists beside the sign-in form, and whether each is on by
     * default. News and vacancies are on: each item still has to be marked
     * for the public by whoever wrote it. Holidays are off until chosen.
     */
    public const SECTIONS = ['announcements' => true, 'vacancies' => true, 'holidays' => false];

    /**
     * @return array{headline: string|null, message: string|null, support_email: string|null, support_phone: string|null, background: string, background_image_url: string|null, sections: array{announcements: bool, vacancies: bool, holidays: bool}}
     */
    public static function present(Tenant $tenant): array
    {
        $stored = $tenant->getAttribute('landing');
        $landing = is_array($stored) ? $stored : [];

        $image = self::httpsUrl($landing['background_image_url'] ?? null);
        $background = in_array($landing['background'] ?? null, self::BACKGROUNDS, true)
            ? (string) $landing['background']
            : 'brand';

        return [
            'headline' => self::text($landing['headline'] ?? null, 80),
            'message' => self::text($landing['message'] ?? null, 280),
            'support_email' => is_string($landing['support_email'] ?? null)
                && filter_var($landing['support_email'], FILTER_VALIDATE_EMAIL) !== false
                    ? $landing['support_email']
                    : null,
            'support_phone' => is_string($landing['support_phone'] ?? null)
                && preg_match('/^\+?[0-9 ()-]{6,24}$/', $landing['support_phone']) === 1
                    ? $landing['support_phone']
                    : null,
            // An image background with no usable image is the brand colour.
            'background' => $background === 'image' && $image === null ? 'brand' : $background,
            'background_image_url' => $image,
            'sections' => self::sections($landing['sections'] ?? null),
        ];
    }

    /**
     * @return array{announcements: bool, vacancies: bool, holidays: bool}
     */
    public static function sections(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];

        return [
            'announcements' => is_bool($stored['announcements'] ?? null) ? $stored['announcements'] : self::SECTIONS['announcements'],
            'vacancies' => is_bool($stored['vacancies'] ?? null) ? $stored['vacancies'] : self::SECTIONS['vacancies'],
            'holidays' => is_bool($stored['holidays'] ?? null) ? $stored['holidays'] : self::SECTIONS['holidays'],
        ];
    }

    public static function httpsUrl(mixed $value): ?string
    {
        return is_string($value)
            && str_starts_with($value, 'https://')
            && filter_var($value, FILTER_VALIDATE_URL) !== false
                ? $value
                : null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
