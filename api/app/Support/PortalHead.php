<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Models\Vacancy;
use App\Services\PortalPreviewImage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The `<head>` of an organisation's portal at ethr.et/{slug}, rendered by the
 * server into the exported sign-in page before it is sent: the title and
 * description a search result or a chat preview shows, the canonical address
 * and its language alternates, the organisation's colour and mark, the Open
 * Graph and Twitter card with a generated preview image, and JSON-LD for the
 * organisation and each public vacancy.
 *
 * Everything here is what the page already shows to anyone, read through
 * `TenantLanding::present` and `TenantBrand::present`, so nothing reaches a
 * crawler that a visitor could not see; no person, no employee, no count of
 * anything but places. Text is escaped for HTML, and JSON is encoded with
 * `<`, `>` and `&` as escapes so a value can never close its `<script>`.
 */
final class PortalHead
{
    public const DESCRIPTION_MAX = 160;

    private const EMPLOYMENT_TYPES = [
        'full_time' => 'FULL_TIME',
        'part_time' => 'PART_TIME',
        'contract' => 'CONTRACTOR',
        'internship' => 'INTERN',
        'temporary' => 'TEMPORARY',
    ];

    /**
     * @param  list<Vacancy>  $vacancies  the public vacancies the feed lists
     */
    public static function render(Tenant $tenant, array $vacancies): string
    {
        $landing = TenantLanding::present($tenant);
        $brand = TenantBrand::present($tenant);
        $name = $tenant->name;
        // The organisation's canonical sign-in address: its own host when it
        // has one, else ethr.et/{slug}; the same address the sitemap lists.
        $url = FrontendUrl::at($tenant, 'login');
        $title = $name.' | ETHR';
        $description = self::description($landing, $name);
        $image = FrontendUrl::to(route('organisation.preview', [$tenant->public_id, PortalPreviewImage::version($tenant)], absolute: false));
        $icon = $brand['mark_url'] === null ? null : FrontendUrl::to($brand['mark_url']);
        $colour = $brand['primary_color'] ?? '#0F4C75';
        $robots = $landing['listed'] ? 'index, follow' : 'noindex, nofollow';

        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $tags = [
            '<title>'.$e($title).'</title>',
            '<meta name="description" content="'.$e($description).'">',
            '<meta name="robots" content="'.$robots.'">',
            '<link rel="canonical" href="'.$e($url).'">',
            '<link rel="alternate" hreflang="en" href="'.$e($url.'?lang=en').'">',
            '<link rel="alternate" hreflang="am" href="'.$e($url.'?lang=am').'">',
            '<link rel="alternate" hreflang="x-default" href="'.$e($url).'">',
            '<meta name="theme-color" content="'.$e($colour).'">',
        ];
        if ($icon !== null) {
            $tags[] = '<link rel="icon" href="'.$e($icon).'">';
            $tags[] = '<link rel="apple-touch-icon" href="'.$e($icon).'">';
        }
        array_push(
            $tags,
            '<meta property="og:type" content="website">',
            '<meta property="og:site_name" content="ETHR">',
            '<meta property="og:title" content="'.$e($name).'">',
            '<meta property="og:description" content="'.$e($description).'">',
            '<meta property="og:url" content="'.$e($url).'">',
            '<meta property="og:image" content="'.$e($image).'">',
            '<meta property="og:image:width" content="'.PortalPreviewImage::WIDTH.'">',
            '<meta property="og:image:height" content="'.PortalPreviewImage::HEIGHT.'">',
            '<meta property="og:image:alt" content="'.$e($name).'">',
            '<meta property="og:locale" content="en_US">',
            '<meta property="og:locale:alternate" content="am_ET">',
            '<meta name="twitter:card" content="summary_large_image">',
            '<meta name="twitter:title" content="'.$e($name).'">',
            '<meta name="twitter:description" content="'.$e($description).'">',
            '<meta name="twitter:image" content="'.$e($image).'">',
            self::jsonLd(self::organisation($tenant, $landing, $brand, $url)),
        );
        foreach ($vacancies as $vacancy) {
            $tags[] = self::jsonLd(self::jobPosting($vacancy, $tenant, $landing, $url));
        }

        return implode("\n", $tags);
    }

    /**
     * The rendered head put into the exported page: the page's own title,
     * description, theme colours and (with a mark) icons give way, and the
     * rest is added before `</head>`. A page with no head is returned as it
     * is, so a broken export still serves.
     */
    public static function inject(string $html, string $head, bool $ownIcon): string
    {
        $html = (string) preg_replace('#<title>.*?</title>#s', '', $html, 1);
        $html = (string) preg_replace('#<meta name="description"[^>]*>#', '', $html, 1);
        $html = (string) preg_replace('#<meta name="theme-color"[^>]*>#', '', $html);
        if ($ownIcon) {
            $html = (string) preg_replace('#<link rel="(?:icon|apple-touch-icon)"[^>]*>#', '', $html);
        }

        $at = stripos($html, '</head>');
        if ($at === false) {
            return $html;
        }

        return substr($html, 0, $at).$head."\n".substr($html, $at);
    }

    /**
     * What a search result or a shared link says under the name: the welcome
     * message, else the about paragraph shortened, else a line that says what
     * the page is for.
     *
     * @param  array<string, mixed>  $landing
     */
    public static function description(array $landing, string $name): string
    {
        $text = $landing['message'] ?? $landing['about'] ?? null;
        if (! is_string($text) || trim($text) === '') {
            return "Sign in to {$name} on ETHR: attendance, leave and payslips.";
        }
        $flat = trim((string) preg_replace('/\s+/u', ' ', $text));

        return Str::limit($flat, self::DESCRIPTION_MAX, '…');
    }

    /**
     * @param  array<string, mixed>  $landing
     * @param  array<string, mixed>  $brand
     * @return array<string, mixed>
     */
    private static function organisation(Tenant $tenant, array $landing, array $brand, string $url): array
    {
        $sameAs = [];
        foreach (['website', 'linkedin', 'facebook', 'x', 'telegram', 'youtube'] as $link) {
            $value = $landing['links'][$link] ?? null;
            if (is_string($value)) {
                $sameAs[] = $value;
            }
        }
        $band = is_string($landing['staff_band'] ?? null) ? self::band($landing['staff_band']) : null;

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $tenant->name,
            'url' => $url,
            'logo' => is_string($brand['logo_url'] ?? null) ? self::absolute($brand['logo_url']) : null,
            'image' => FrontendUrl::to(route('organisation.preview', [$tenant->public_id, PortalPreviewImage::version($tenant)], absolute: false)),
            'description' => self::description($landing, $tenant->name),
            'sameAs' => $sameAs === [] ? null : $sameAs,
            'address' => is_string($landing['address'] ?? null) ? [
                '@type' => 'PostalAddress',
                'streetAddress' => $landing['address'],
                'addressCountry' => 'ET',
            ] : null,
            'telephone' => $landing['support_phone'] ?? null,
            'email' => $landing['support_email'] ?? null,
            'foundingDate' => isset($landing['founded_year']) ? (string) $landing['founded_year'] : null,
            'numberOfEmployees' => $band,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $landing
     * @return array<string, mixed>
     */
    private static function jobPosting(Vacancy $vacancy, Tenant $tenant, array $landing, string $url): array
    {
        $published = Carbon::make($vacancy->published_at);
        $closes = $vacancy->closes_on;
        $locality = is_string($vacancy->location) && $vacancy->location !== '' ? $vacancy->location : null;

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'JobPosting',
            'title' => $vacancy->title,
            'description' => trim(strip_tags((string) $vacancy->description)),
            'identifier' => ['@type' => 'PropertyValue', 'name' => $tenant->name, 'value' => $vacancy->public_id],
            'datePosted' => $published?->toDateString(),
            'validThrough' => $closes === null ? null : Carbon::make($closes)?->endOfDay()->toIso8601String(),
            'employmentType' => self::EMPLOYMENT_TYPES[$vacancy->employment_type] ?? 'OTHER',
            'hiringOrganization' => ['@type' => 'Organization', 'name' => $tenant->name, 'sameAs' => $url],
            'jobLocation' => [
                '@type' => 'Place',
                'address' => array_filter([
                    '@type' => 'PostalAddress',
                    'addressLocality' => $locality,
                    'streetAddress' => $locality === null && is_string($landing['address'] ?? null) ? $landing['address'] : null,
                    'addressCountry' => 'ET',
                ], static fn ($v) => $v !== null),
            ],
            'url' => $url.'#careers',
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /** @return array<string, mixed>|null */
    private static function band(string $band): ?array
    {
        if ($band === '1000+') {
            return ['@type' => 'QuantitativeValue', 'minValue' => 1000];
        }
        if (preg_match('/^(\d+)-(\d+)$/', $band, $m) !== 1) {
            return null;
        }

        return ['@type' => 'QuantitativeValue', 'minValue' => (int) $m[1], 'maxValue' => (int) $m[2]];
    }

    private static function absolute(string $url): string
    {
        return str_starts_with($url, '/') ? FrontendUrl::to($url) : $url;
    }

    /** @param  array<string, mixed>  $data */
    private static function jsonLd(array $data): string
    {
        return '<script type="application/ld+json">'
            .json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR)
            .'</script>';
    }
}
