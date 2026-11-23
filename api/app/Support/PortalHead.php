<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Announcement;
use App\Models\Tenant;
use App\Models\Vacancy;
use App\Services\PortalPreviewImage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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

    /** The Open Graph locale of each shipped language. */
    private const OG_LOCALES = ['en' => 'en_US', 'am' => 'am_ET', 'om' => 'om_ET'];

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

        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $tags = [
            '<title>'.$e($title).'</title>',
            '<meta name="description" content="'.$e($description).'">',
            '<meta name="robots" content="'.self::robots($tenant).'">',
            '<link rel="canonical" href="'.$e($url).'">',
            '<link rel="alternate" hreflang="en" href="'.$e($url.'?lang=en').'">',
            '<link rel="alternate" hreflang="am" href="'.$e($url.'?lang=am').'">',
            '<link rel="alternate" hreflang="om" href="'.$e($url.'?lang=om').'">',
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
            self::localeTags($tenant),
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
     * The robots line of a news page, which has one more question to answer
     * than the rest of the portal: an organisation that has published
     * nothing has a page that says so and nothing else, the same empty page
     * every one of them would have, and a shelf of identical empty pages on
     * one domain is worth less than none. Such a page is kept out of search
     * but its links are still followed, so the portal itself is reached, and
     * it comes back the moment something is published.
     *
     * An organisation that is hidden or no longer in use keeps its own line.
     * This only takes a page out of search; it never puts one back in.
     */
    public static function newsRobots(Tenant $tenant, bool $hasArticles): string
    {
        $robots = self::robots($tenant);

        return $hasArticles || str_contains($robots, 'noindex') ? $robots : 'noindex, follow';
    }

    /**
     * The head of the organisation's news page (`/news`): its title and a
     * line saying what the page is, at its canonical address, and the stories
     * its first page lists as a CollectionPage and an ItemList, in the order
     * the page itself shows them. An organisation with no stories has no list.
     *
     * @param  list<Announcement>  $articles  the first page of public stories, in `Announcement::scopeNewsOrder()` order
     */
    public static function news(Tenant $tenant, array $articles): string
    {
        $landing = TenantLanding::present($tenant);
        $name = $tenant->name;
        $locale = self::locale($tenant);
        $word = __('general.portal_news_title', [], $locale);
        $url = self::newsUrl($tenant);
        $title = "{$word} · {$name}";

        $jsonLd = [];
        if ($articles !== []) {
            $jsonLd[] = [
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'name' => $title,
                'url' => $url,
                'mainEntity' => [
                    '@type' => 'ItemList',
                    'itemListElement' => array_map(
                        fn (Announcement $article, int $at): array => [
                            '@type' => 'ListItem',
                            'position' => $at + 1,
                            'url' => self::newsUrl($tenant, $article->public_id),
                            'name' => Str::limit($article->title, 110, '…'),
                        ],
                        $articles,
                        array_keys($articles),
                    ),
                ],
            ];
        }
        $jsonLd[] = self::breadcrumb([
            ['name' => $name, 'url' => FrontendUrl::at($tenant, 'login')],
            ['name' => $word, 'url' => $url],
        ]);

        return self::page(
            $tenant,
            $landing,
            title: $title,
            description: __('general.portal_news_description', ['name' => $name], $locale),
            url: $url,
            type: 'website',
            jsonLd: $jsonLd,
            robots: self::newsRobots($tenant, $articles !== []),
        );
    }

    /**
     * The head of one article (`/news/{id}`): its own title and opening
     * words, its canonical address, and a NewsArticle record. Only what the
     * page shows: title, text, date, cover photo and the organisation's name.
     * A story with a cover is shared with its own photo as the preview image.
     */
    public static function article(Tenant $tenant, Announcement $article): string
    {
        $landing = TenantLanding::present($tenant);
        $brand = TenantBrand::present($tenant);
        $url = self::newsUrl($tenant, $article->public_id);
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($article->body)));
        $description = Str::limit($text, self::DESCRIPTION_MAX, '…');
        $published = Carbon::make($article->published_at);
        $modified = Carbon::make($article->updated_at);
        $cover = $article->coverPresented($tenant->public_id);
        $locale = self::locale($tenant);
        $body = PlainExcerpt::of($article->body, 5000);
        $home = FrontendUrl::at($tenant, 'login');
        $headline = Str::limit($article->title, 110, '…');

        return self::page(
            $tenant,
            $landing,
            title: Str::limit($article->title, 90, '…').' · '.$tenant->name,
            description: $description === '' ? __('general.portal_news_description', ['name' => $tenant->name], $locale) : $description,
            url: $url,
            type: 'article',
            jsonLd: [array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'NewsArticle',
                'headline' => $headline,
                'description' => $description === '' ? null : $description,
                'articleBody' => $body === '' ? null : $body,
                'inLanguage' => self::articleLanguage($article, $locale),
                'image' => $cover === null ? null : FrontendUrl::to($cover['url']),
                'datePublished' => $published?->toIso8601String(),
                'dateModified' => $modified?->toIso8601String() ?? $published?->toIso8601String(),
                'url' => $url,
                'mainEntityOfPage' => $url,
                'author' => ['@type' => 'Organization', 'name' => $tenant->name, 'url' => $home],
                'publisher' => array_filter([
                    '@type' => 'Organization',
                    'name' => $tenant->name,
                    'url' => $home,
                    'logo' => is_string($brand['logo_url'] ?? null) ? ['@type' => 'ImageObject', 'url' => self::absolute($brand['logo_url'])] : null,
                ], static fn ($v) => $v !== null),
            ], static fn ($v) => $v !== null), self::breadcrumb([
                ['name' => $tenant->name, 'url' => $home],
                ['name' => __('general.portal_news_title', [], $locale), 'url' => self::newsUrl($tenant)],
                ['name' => $headline, 'url' => $url],
            ])],
            published: $published,
            modified: $modified,
            image: $cover === null ? null : [
                'url' => FrontendUrl::to($cover['url']),
                'width' => $cover['width'],
                'height' => $cover['height'],
                'alt' => $article->title,
            ],
        );
    }

    /**
     * The address of the organisation's news page, or of one article: on its
     * own host when it has one, else on the shared host with `?org=` naming
     * it, which is how the shared host's pages know whose they are.
     */
    public static function newsUrl(Tenant $tenant, ?string $publicId = null): string
    {
        $path = 'news'.($publicId === null ? '' : '/'.$publicId);
        $origin = FrontendUrl::canonicalOrigin($tenant);

        return $origin !== null
            ? $origin.'/'.$path
            : FrontendUrl::to($path).'?org='.rawurlencode($tenant->subdomain);
    }

    /**
     * The tags every news page shares: title, description, robots, canonical
     * and language alternates, colour and icon, Open Graph and Twitter card
     * with the organisation's preview image.
     *
     * @param  array<string, mixed>  $landing
     * @param  list<array<string, mixed>>  $jsonLd
     * @param  array{url: string, width: int, height: int, alt: string}|null  $image  the story's cover, else the organisation's preview
     */
    private static function page(
        Tenant $tenant,
        array $landing,
        string $title,
        string $description,
        string $url,
        string $type,
        array $jsonLd,
        ?Carbon $published = null,
        ?Carbon $modified = null,
        ?array $image = null,
        ?string $robots = null,
    ): string {
        $brand = TenantBrand::present($tenant);
        $image ??= [
            'url' => FrontendUrl::to(route('organisation.preview', [$tenant->public_id, PortalPreviewImage::version($tenant)], absolute: false)),
            'width' => PortalPreviewImage::WIDTH,
            'height' => PortalPreviewImage::HEIGHT,
            'alt' => $tenant->name,
        ];
        $icon = $brand['mark_url'] === null ? null : FrontendUrl::to($brand['mark_url']);
        $colour = $brand['primary_color'] ?? '#0F4C75';
        $joiner = str_contains($url, '?') ? '&' : '?';

        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $tags = [
            '<title>'.$e($title).'</title>',
            '<meta name="description" content="'.$e($description).'">',
            '<meta name="robots" content="'.($robots ?? self::robots($tenant)).'">',
            '<link rel="canonical" href="'.$e($url).'">',
            '<link rel="alternate" hreflang="en" href="'.$e($url.$joiner.'lang=en').'">',
            '<link rel="alternate" hreflang="am" href="'.$e($url.$joiner.'lang=am').'">',
            '<link rel="alternate" hreflang="om" href="'.$e($url.$joiner.'lang=om').'">',
            '<link rel="alternate" hreflang="x-default" href="'.$e($url).'">',
            '<meta name="theme-color" content="'.$e($colour).'">',
        ];
        if ($icon !== null) {
            $tags[] = '<link rel="icon" href="'.$e($icon).'">';
            $tags[] = '<link rel="apple-touch-icon" href="'.$e($icon).'">';
        }
        array_push(
            $tags,
            '<meta property="og:type" content="'.$type.'">',
            '<meta property="og:site_name" content="'.$e($tenant->name).'">',
            '<meta property="og:title" content="'.$e($title).'">',
            '<meta property="og:description" content="'.$e($description).'">',
            '<meta property="og:url" content="'.$e($url).'">',
            '<meta property="og:image" content="'.$e($image['url']).'">',
            '<meta property="og:image:width" content="'.$image['width'].'">',
            '<meta property="og:image:height" content="'.$image['height'].'">',
            '<meta property="og:image:alt" content="'.$e($image['alt']).'">',
            self::localeTags($tenant),
        );
        if ($published !== null) {
            $tags[] = '<meta property="article:published_time" content="'.$e($published->toIso8601String()).'">';
        }
        if ($modified !== null) {
            $tags[] = '<meta property="article:modified_time" content="'.$e($modified->toIso8601String()).'">';
        }
        array_push(
            $tags,
            '<meta name="twitter:card" content="summary_large_image">',
            '<meta name="twitter:title" content="'.$e($title).'">',
            '<meta name="twitter:description" content="'.$e($description).'">',
            '<meta name="twitter:image" content="'.$e($image['url']).'">',
        );
        foreach ($jsonLd as $data) {
            $tags[] = self::jsonLd($data);
        }

        return implode("\n", $tags);
    }

    /**
     * The organisation's own language, which the fixed words of its news
     * heads are said in and its Open Graph locale follows. The application's
     * locale is left alone: this is passed to `__()` explicitly.
     */
    private static function locale(Tenant $tenant): string
    {
        return Locales::orDefault($tenant->default_locale);
    }

    /**
     * `og:locale` for the organisation's language and the other two as
     * alternates: one place, for the sign-in portal's head and the news heads.
     *
     * Several tags in one string, so a caller can place them as one entry.
     */
    private static function localeTags(Tenant $tenant): string
    {
        $primary = self::locale($tenant);
        $tags = ['<meta property="og:locale" content="'.self::OG_LOCALES[$primary].'">'];
        foreach (self::OG_LOCALES as $locale => $og) {
            if ($locale !== $primary) {
                $tags[] = '<meta property="og:locale:alternate" content="'.$og.'">';
            }
        }

        return implode("\n", $tags);
    }

    /**
     * The language a story is written in, or null when that cannot be said
     * with confidence: a wrong language is worse than none. Ethiopic letters
     * in the majority count only for an Amharic organisation, Latin letters
     * in the majority only for an English or Afaan Oromoo one, because the
     * script alone does not tell Amharic from Tigrinya, or English from
     * Afaan Oromoo, and the organisation's own language is what is known.
     */
    private static function articleLanguage(Announcement $article, string $locale): ?string
    {
        $text = $article->title.' '.strip_tags($article->body);
        $ethiopic = (int) preg_match_all('/\p{Ethiopic}/u', $text);
        $latin = (int) preg_match_all('/\p{Latin}/u', $text);

        if ($ethiopic > $latin && $locale === 'am') {
            return 'am';
        }
        if ($latin > $ethiopic && ($locale === 'en' || $locale === 'om')) {
            return $locale;
        }

        return null;
    }

    /**
     * @param  list<array{name: string, url: string}>  $crumbs  from the organisation's home to the page
     * @return array<string, mixed>
     */
    private static function breadcrumb(array $crumbs): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(
                static fn (array $crumb, int $at): array => [
                    '@type' => 'ListItem',
                    'position' => $at + 1,
                    'name' => $crumb['name'],
                    'item' => $crumb['url'],
                ],
                $crumbs,
                array_keys($crumbs),
            ),
        ];
    }

    /**
     * Whether this organisation's portal pages may be kept by a search
     * engine, as the one line a head, a header and a payload all state.
     * `TenantLanding::indexable()` decides; this is only its wording, in one
     * place, so the three cannot disagree.
     */
    public static function robots(Tenant $tenant): string
    {
        return TenantLanding::indexable($tenant) ? 'index, follow' : 'noindex, nofollow';
    }

    /**
     * The rendered head put into the exported page: the page's own title,
     * description, robots, theme colours and (with a mark) icons give way,
     * and the rest is added before `</head>`. A page with no head is returned
     * as it is, so a broken export still serves.
     *
     * `$robots` is what this organisation's head states, and it is written
     * into the page's React payload as well as its head. Removing the head's
     * own tag is not enough: the exported pages carry `robots` in their Next
     * metadata, the payload carries it too, and React puts it back into the
     * DOM at hydration. The page then held two robots tags; Google takes the
     * most restrictive of them and reads the rendered DOM, so every portal
     * answered `noindex` to a crawler while `curl` showed `index, follow`.
     * Measured 2026-11-11 on the live site by Search Console's live test,
     * which refused to index a portal in those words; see
     * `docs/operations/SEARCH-LISTINGS.md` section 7.
     *
     * The payload is Next's own serialisation, so a Next upgrade can change
     * its shape and this rewrite would then quietly match nothing. A fixture
     * test cannot see that coming — it pins today's shape. What can is the
     * sweep in `statePayloadRobots()`, which runs on every response. The
     * other half is the rendered-DOM check in that runbook.
     *
     * The description is written into the payload for the same reason. The
     * exports carry their own generic one there ("News and announcements.",
     * "Sign in to your ETHR workspace."), so after hydration a page held two
     * description tags, the organisation's and the export's. Measured
     * 2026-11-23 on `/login`, `/news` and an article. Unlike robots it is
     * free text an organisation or an author wrote, so it is encoded
     * (`statePayloadDescription()`). The description is read back out of
     * `$head`, so the head and the payload cannot disagree.
     *
     * The `.txt` payload files beside each page are left alone. They are read
     * only for navigation between pages once the app is running, and a
     * crawler renders each address from its own HTML.
     */
    public static function inject(string $html, string $head, bool $ownIcon, string $robots): string
    {
        $html = (string) preg_replace('#<title>.*?</title>#s', '', $html, 1);
        $html = (string) preg_replace('#<meta name="description"[^>]*>#', '', $html, 1);
        // The export's own robots line (the news pages ship `noindex` for the
        // shared host, where no organisation is known) gives way to the
        // organisation's listing choice, which the head states.
        $html = (string) preg_replace('#<meta name="robots"[^>]*>#', '', $html);
        $html = (string) preg_replace('#<meta name="theme-color"[^>]*>#', '', $html);
        if ($ownIcon) {
            $html = (string) preg_replace('#<link rel="(?:icon|apple-touch-icon)"[^>]*>#', '', $html);
        }
        $html = self::statePayloadRobots($html, $robots);
        if (preg_match('#<meta name="description" content="([^"]*)">#', $head, $found) === 1) {
            $html = self::statePayloadDescription($html, htmlspecialchars_decode($found[1], ENT_QUOTES));
        }

        $at = stripos($html, '</head>');
        if ($at === false) {
            return $html;
        }

        return substr($html, 0, $at).$head."\n".substr($html, $at);
    }

    /**
     * The organisation's robots line written over every `robots` entry in the
     * page's React payload, so the tag React renders at hydration says what
     * the head says. The entries are JSON inside a JavaScript string, where a
     * quote is written as a backslash and a quote, and a robots value holds
     * neither — so the value runs from the opening escaped quote to the next
     * one. Scanned rather than matched with a pattern: the surrounding text
     * is full of backslashes, and a pattern for it is unreadable and easy to
     * get subtly wrong.
     *
     * The sweep at the end says nothing about where it looked, because the
     * point is that we no longer know: anything left reading `noindex` on a
     * page meant to be kept will reach Google, through the head, the payload,
     * or whatever Next serialises next.
     */
    private static function statePayloadRobots(string $html, string $robots): string
    {
        $html = self::rewritePayloadMeta($html, 'robots', $robots);

        if (! str_contains($robots, 'noindex') && str_contains($html, 'noindex')) {
            Log::error('A portal page still says noindex after its robots line was stated.', [
                'robots' => $robots,
                'likely_cause' => 'the exported page serialises robots in a shape PortalHead no longer recognises',
            ]);
        }

        return $html;
    }

    /**
     * The organisation's description written over every `description` entry
     * in the page's React payload, so the tag React renders at hydration says
     * what the head says (see `inject()`).
     *
     * A description is free text, so it is encoded the way the payload holds
     * it: JSON, with `<`, `>` and `&` as escapes so it can never close the
     * `<script>` it sits in, then the quotes and backslashes of that JSON
     * escaped again for the JavaScript string around it. A value that starts
     * with `$` gets a second one, which is how React's payload writes a
     * literal dollar sign rather than a reference.
     *
     * The sweep says that the export's shape is no longer recognised when a
     * description entry is left that was not rewritten, or one that does not
     * read back as what was written. It never throws: the page is still served.
     */
    private static function statePayloadDescription(string $html, string $description): string
    {
        $encoded = self::payloadString(str_starts_with($description, '$') ? '$'.$description : $description);
        $html = self::rewritePayloadMeta($html, 'description', $encoded);

        $named = substr_count($html, '\"name\":\"description\"');
        $recognised = 0;
        $unchanged = true;
        $opening = '\"name\":\"description\",\"content\":\"';
        $offset = 0;
        while (($at = strpos($html, $opening, $offset)) !== false) {
            $from = $at + strlen($opening);
            $to = self::payloadValueEnd($html, $from);
            $recognised++;
            if ($to === null || substr($html, $from, $to - $from) !== $encoded) {
                $unchanged = false;
            }
            $offset = $from;
        }

        if ($named !== $recognised || ! $unchanged) {
            Log::error('A portal page still carries a description that was not the one stated: the shape of the export is no longer recognised.', [
                'likely_cause' => 'the exported page serialises its description in a shape PortalHead no longer recognises',
            ]);
        }

        return $html;
    }

    /**
     * Every `{name, content}` entry for `$name` in the React payload, its
     * content replaced by `$encoded`, which is already in payload form.
     * Scanned rather than matched with a pattern: the surrounding text is
     * full of backslashes, and a pattern for it is unreadable and easy to get
     * subtly wrong.
     */
    private static function rewritePayloadMeta(string $html, string $name, string $encoded): string
    {
        $opening = '\"name\":\"'.$name.'\",\"content\":\"';

        $offset = 0;
        while (($at = strpos($html, $opening, $offset)) !== false) {
            $from = $at + strlen($opening);
            $to = self::payloadValueEnd($html, $from);
            if ($to === null) {
                break;
            }
            $html = substr_replace($html, $encoded, $from, $to - $from);
            $offset = $from + strlen($encoded);
        }

        return $html;
    }

    /**
     * Where the payload string that starts at `$from` ends: the offset of the
     * backslash of the escaped quote that closes it, or null when it never
     * closes. The closing quote is written `\"` (one backslash). A quote that
     * is part of the value is JSON `\"`, which the JavaScript string writes
     * `\\\"` (three), and a value that ends in a backslash closes as `\\\\\"`
     * (five). So a quote closes the value when the run of backslashes before
     * it is one more than a multiple of four.
     */
    private static function payloadValueEnd(string $html, int $from): ?int
    {
        $length = strlen($html);
        $run = 0;
        for ($i = $from; $i < $length; $i++) {
            $char = $html[$i];
            if ($char === '\\') {
                $run++;

                continue;
            }
            if ($char === '"' && $run % 4 === 1) {
                return $i - 1;
            }
            $run = 0;
        }

        return null;
    }

    /** `$value` as it is held inside a payload string, without its outer quotes. */
    private static function payloadString(string $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);

        return str_replace(['\\', '"'], ['\\\\', '\\"'], substr($json, 1, -1));
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
