<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;

/**
 * An organisation's portal settings, as they may be shown to anyone.
 *
 * Read by the public `/auth/tenant-context` (the portal draws itself from
 * it before sign-in) and by the settings screen. Only values that pass the
 * same rules the settings request applies leave: a row written before a
 * rule existed, or edited by hand, cannot put a script URL or an unbounded
 * string on a public page.
 *
 * Every text an organisation writes has an English and an Amharic key; the
 * page shows the viewer's language and falls back to the other. The photo
 * background is the uploaded cover (`TenantBrand::cover()`), never a URL the
 * organisation typed: a row that still holds one from before 2026-10-14 is
 * read as having none. Links are the only outside URLs, https and, for the
 * networks, on that network's own host.
 */
final class TenantLanding
{
    public const BACKGROUNDS = ['brand', 'gradient', 'pattern', 'image'];

    /**
     * What the page shows beside the sign-in form, and whether each is on by
     * default. News and vacancies are on: each item still has to be marked
     * for the public by whoever wrote it. Holidays are off until chosen.
     * About, services, questions and contact are on, and draw nothing until
     * something is written.
     */
    public const SECTIONS = ['about' => true, 'services' => true, 'announcements' => true, 'vacancies' => true, 'holidays' => false, 'faq' => true, 'contact' => true];

    /** The sections in the order the page shows them until an admin reorders. */
    public const ORDER = ['about', 'services', 'announcements', 'vacancies', 'holidays', 'faq', 'contact'];

    /** The sectors an organisation may say it is in; the page names each in both languages. */
    public const SECTORS = [
        'agriculture', 'construction', 'education', 'finance', 'government', 'health', 'hospitality',
        'logistics', 'manufacturing', 'media', 'mining', 'ngo', 'retail', 'services', 'technology',
        'telecom', 'utilities', 'other',
    ];

    public const STAFF_BANDS = ['1-10', '11-50', '51-200', '201-500', '501-1000', '1000+'];

    /** The links the footer offers, each https; the networks on their own host. */
    public const LINKS = ['website', 'linkedin', 'facebook', 'x', 'telegram', 'youtube', 'privacy', 'terms'];

    public const LINK_HOSTS = [
        'linkedin' => ['linkedin.com'],
        'facebook' => ['facebook.com', 'fb.com'],
        'x' => ['x.com', 'twitter.com'],
        'telegram' => ['t.me', 'telegram.me'],
        'youtube' => ['youtube.com', 'youtu.be'],
    ];

    public const HEADLINE_MAX = 80;

    public const MESSAGE_MAX = 280;

    public const ABOUT_MAX = 600;

    public const ADDRESS_MAX = 200;

    public const HOURS_MAX = 120;

    /** How many questions the page answers, and how long each may be. */
    public const FAQ_MAX = 12;

    public const QUESTION_MAX = 160;

    public const ANSWER_MAX = 1000;

    /** How many services the page lists, and how long each may be. */
    public const SERVICES_MAX = 8;

    public const SERVICE_TITLE_MAX = 80;

    public const SERVICE_TEXT_MAX = 400;

    /**
     * @return array{headline: string|null, headline_am: string|null, message: string|null, message_am: string|null, support_email: string|null, support_phone: string|null, background: string, about: string|null, about_am: string|null, founded_year: int|null, sector: string|null, staff_band: string|null, show_branch_count: bool, address: string|null, address_am: string|null, hours: string|null, hours_am: string|null, latitude: float|null, longitude: float|null, show_branches: bool, links: array<string, string|null>, faqs: list<array{question: string|null, question_am: string|null, answer: string|null, answer_am: string|null}>, services: list<array{title: string|null, title_am: string|null, description: string|null, description_am: string|null}>, sections: array<string, bool>, order: list<string>, listed: bool}
     */
    public static function present(Tenant $tenant): array
    {
        $stored = $tenant->getAttribute('landing');
        $landing = is_array($stored) ? $stored : [];

        $background = in_array($landing['background'] ?? null, self::BACKGROUNDS, true)
            ? (string) $landing['background']
            : 'brand';

        $latitude = $landing['latitude'] ?? null;
        $longitude = $landing['longitude'] ?? null;

        return [
            'headline' => self::text($landing['headline'] ?? null, self::HEADLINE_MAX),
            'headline_am' => self::text($landing['headline_am'] ?? null, self::HEADLINE_MAX),
            'message' => self::text($landing['message'] ?? null, self::MESSAGE_MAX),
            'message_am' => self::text($landing['message_am'] ?? null, self::MESSAGE_MAX),
            'support_email' => is_string($landing['support_email'] ?? null)
                && filter_var($landing['support_email'], FILTER_VALIDATE_EMAIL) !== false
                    ? $landing['support_email']
                    : null,
            'support_phone' => self::phone($landing['support_phone'] ?? null),
            // A photo background with no cover uploaded is the brand colour.
            'background' => $background === 'image' && $tenant->brandAsset('cover') === null ? 'brand' : $background,
            'about' => self::text($landing['about'] ?? null, self::ABOUT_MAX),
            'about_am' => self::text($landing['about_am'] ?? null, self::ABOUT_MAX),
            'founded_year' => self::year($landing['founded_year'] ?? null),
            'sector' => self::choice($landing['sector'] ?? null, self::SECTORS),
            'staff_band' => self::choice($landing['staff_band'] ?? null, self::STAFF_BANDS),
            'show_branch_count' => ($landing['show_branch_count'] ?? null) === true,
            'address' => self::text($landing['address'] ?? null, self::ADDRESS_MAX),
            'address_am' => self::text($landing['address_am'] ?? null, self::ADDRESS_MAX),
            'hours' => self::text($landing['hours'] ?? null, self::HOURS_MAX),
            'hours_am' => self::text($landing['hours_am'] ?? null, self::HOURS_MAX),
            'latitude' => self::coordinate($latitude, $longitude, 90),
            'longitude' => self::coordinate($longitude, $latitude, 180),
            'show_branches' => ($landing['show_branches'] ?? null) === true,
            'links' => self::links($landing['links'] ?? null),
            'faqs' => self::faqs($landing['faqs'] ?? null),
            'services' => self::services($landing['services'] ?? null),
            'sections' => self::sections($landing['sections'] ?? null),
            'order' => self::order($landing['order'] ?? null),
            // Listed unless the organisation switched it off (owner decision
            // 2026-10-26: every organisation in use is findable by its name).
            // A missing value is the default; a hand-written one that is not
            // a boolean is read as off, so no row edited by hand lists a page.
            'listed' => ($landing['listed'] ?? true) === true,
        ];
    }

    /**
     * Whether the organisation's portal pages may appear in search results
     * and in its sitemap: it is in use (active, or a trial not yet ended) and
     * has not switched listing off. One rule for the page head, the
     * `X-Robots-Tag` header and the sitemap, so a suspended organisation or
     * an ended trial leaves all three at once.
     */
    public static function indexable(Tenant $tenant): bool
    {
        return $tenant->isOperational() && self::present($tenant)['listed'];
    }

    /**
     * @return array<string, bool>
     */
    public static function sections(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $sections = [];
        foreach (self::SECTIONS as $section => $default) {
            $sections[$section] = is_bool($stored[$section] ?? null) ? $stored[$section] : $default;
        }

        return $sections;
    }

    /**
     * The stored order with anything unknown dropped, and every section it
     * lacks placed right after the nearest section that comes before it in
     * the default order (at the end when none does). A section added after
     * an organisation chose its order (services, questions) lands where the
     * default would put it relative to the rest, not below Contact.
     *
     * @return list<string>
     */
    public static function order(mixed $stored): array
    {
        $order = [];
        foreach (is_array($stored) ? $stored : [] as $section) {
            if (in_array($section, self::ORDER, true) && ! in_array($section, $order, true)) {
                $order[] = $section;
            }
        }
        foreach (self::ORDER as $index => $section) {
            if (in_array($section, $order, true)) {
                continue;
            }
            $at = count($order);
            for ($before = $index - 1; $before >= 0; $before--) {
                $found = array_search(self::ORDER[$before], $order, true);
                if (is_int($found)) {
                    $at = $found + 1;
                    break;
                }
            }
            array_splice($order, $at, 0, [$section]);
        }

        return $order;
    }

    /**
     * The organisation's questions and answers, in its order. A pair is shown
     * only when it has a question and an answer, each in at least one
     * language; the page shows the viewer's language and falls back to the
     * other, as it does for every text here.
     *
     * @return list<array{question: string|null, question_am: string|null, answer: string|null, answer_am: string|null}>
     */
    public static function faqs(mixed $stored): array
    {
        $faqs = [];
        foreach (is_array($stored) ? $stored : [] as $item) {
            if (! is_array($item) || count($faqs) >= self::FAQ_MAX) {
                continue;
            }
            $faq = [
                'question' => self::text($item['question'] ?? null, self::QUESTION_MAX),
                'question_am' => self::text($item['question_am'] ?? null, self::QUESTION_MAX),
                'answer' => self::text($item['answer'] ?? null, self::ANSWER_MAX),
                'answer_am' => self::text($item['answer_am'] ?? null, self::ANSWER_MAX),
            ];
            if (($faq['question'] ?? $faq['question_am']) !== null && ($faq['answer'] ?? $faq['answer_am']) !== null) {
                $faqs[] = $faq;
            }
        }

        return $faqs;
    }

    /**
     * What the organisation offers, in its order: a title in at least one
     * language, and optionally a few words about it.
     *
     * @return list<array{title: string|null, title_am: string|null, description: string|null, description_am: string|null}>
     */
    public static function services(mixed $stored): array
    {
        $services = [];
        foreach (is_array($stored) ? $stored : [] as $item) {
            if (! is_array($item) || count($services) >= self::SERVICES_MAX) {
                continue;
            }
            $service = [
                'title' => self::text($item['title'] ?? null, self::SERVICE_TITLE_MAX),
                'title_am' => self::text($item['title_am'] ?? null, self::SERVICE_TITLE_MAX),
                'description' => self::text($item['description'] ?? null, self::SERVICE_TEXT_MAX),
                'description_am' => self::text($item['description_am'] ?? null, self::SERVICE_TEXT_MAX),
            ];
            if (($service['title'] ?? $service['title_am']) !== null) {
                $services[] = $service;
            }
        }

        return $services;
    }

    /**
     * @return array<string, string|null>
     */
    public static function links(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $links = [];
        foreach (self::LINKS as $link) {
            $links[$link] = self::linkUrl($link, $stored[$link] ?? null);
        }

        return $links;
    }

    /** An https URL, and for a network one on that network's own host. */
    public static function linkUrl(string $link, mixed $value): ?string
    {
        $url = self::httpsUrl($value);
        if ($url === null || mb_strlen($url) > 500) {
            return null;
        }
        $hosts = self::LINK_HOSTS[$link] ?? null;
        if ($hosts === null) {
            return $url;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach ($hosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return $url;
            }
        }

        return null;
    }

    public static function httpsUrl(mixed $value): ?string
    {
        return is_string($value)
            && str_starts_with($value, 'https://')
            && filter_var($value, FILTER_VALIDATE_URL) !== false
                ? $value
                : null;
    }

    public static function phone(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\+?[0-9 ()-]{6,24}$/', $value) === 1 ? $value : null;
    }

    /**
     * One of a curated list, or nothing.
     *
     * @param  list<string>  $choices
     */
    private static function choice(mixed $value, array $choices): ?string
    {
        return is_string($value) && in_array($value, $choices, true) ? $value : null;
    }

    private static function year(mixed $value): ?int
    {
        $year = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);

        return $year !== null && $year >= 1800 && $year <= (int) date('Y') ? $year : null;
    }

    /**
     * One coordinate, within its range, and only together with the other:
     * a latitude with no longitude places nothing.
     */
    private static function coordinate(mixed $value, mixed $other, float $limit): ?float
    {
        if (! is_numeric($value) || ! is_numeric($other)) {
            return null;
        }
        $rounded = round((float) $value, 6);
        $otherLimit = $limit === 90.0 ? 180.0 : 90.0;

        return abs($rounded) <= $limit && abs((float) $other) <= $otherLimit ? $rounded : null;
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
