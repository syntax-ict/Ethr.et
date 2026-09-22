<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Models\TenantPublicItem;
use App\Services\Calendar\EthiopianCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * One entry inside a section, as the public page is allowed to see it.
 *
 * The same discipline as PublicTenantPage, applied one level down. A Blade
 * partial receives one of these and never a TenantPublicItem, so it cannot
 * render `image_path` or reach `->section->tenant->settings` — not because
 * nobody would, but because the object in scope has no such thing.
 *
 * Every string here is rendered escaped. There is no rich-text field anywhere
 * in this feature, so HTML typed into a title appears as the characters that
 * were typed, which is the honest behaviour for a plain-text input.
 */
final class PublicSectionItem
{
    /** @param array<string, string> $meta */
    private function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $imageUrl,
        public readonly string $imageAlt,
        public readonly ?string $icon,
        public readonly ?string $linkUrl,
        public readonly string $linkLabel,
        public readonly array $meta,
        public readonly ?string $dateIso,
        public readonly ?string $dateLabel,
    ) {}

    public static function from(Tenant $tenant, TenantPublicItem $item, string $locale): self
    {
        $meta = self::safeMeta($item->meta);
        [$dateIso, $dateLabel] = self::date($tenant, $meta['date'] ?? null);

        return new self(
            title: self::localised($item->title, $item->title_am, $locale),
            body: self::localised($item->body, $item->body_am, $locale),
            // A URL built from the item's ULID, never the storage path. The
            // path is not something an anonymous visitor should be able to see,
            // and TenantPublicAsset re-checks ownership before serving it.
            imageUrl: TenantPublicAsset::sectionImagePath($tenant, $item) === null
                ? null
                : TenantPublicAsset::sectionUrl($item),
            imageAlt: self::localised($item->image_alt, $item->image_alt_am, $locale),
            // Validated against a closed list on the way in, and checked again
            // here: this value is interpolated into an SVG `<use href="#…">`
            // reference, where a string from the database is a string in a URL.
            icon: PublicSectionIcons::allows($item->icon) ? $item->icon : null,
            linkUrl: self::safeUrl($item->link_url),
            linkLabel: self::localised($item->link_label, $item->link_label_am, $locale),
            meta: $meta,
            dateIso: $dateIso,
            dateLabel: $dateLabel,
        );
    }

    /**
     * The Amharic value when the page is in Amharic and one was written,
     * otherwise the base language.
     *
     * Falling back rather than showing a blank is the whole rule. A tenant that
     * has filled in English and not yet Amharic should have an Amharic page
     * with English content on it, not an Amharic page with holes — and Amharic
     * is the default locale, so this is the common case rather than the edge.
     */
    private static function localised(?string $base, ?string $amharic, string $locale): string
    {
        if (str_starts_with($locale, 'am') && filled($amharic)) {
            return (string) $amharic;
        }

        return (string) ($base ?? '');
    }

    /**
     * A link safe to put in an href, or null.
     *
     * Re-validated at render time even though the FormRequest validated it on
     * the way in. The two checks answer different questions: the request asked
     * whether a human may save this, and this asks whether it may be printed
     * into an anchor — for a row that might have been written years ago, or by
     * an import, or by a rule that has since been relaxed.
     */
    private static function safeUrl(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /**
     * Kind-specific values, narrowed to short scalars.
     *
     * `meta` is json, so it can hold anything a past migration or a hand-edit
     * left in it. A partial reads the keys it knows and must get a string when
     * it does; anything nested or oversized is dropped rather than rendered.
     *
     * @return array<string, string>
     */
    private static function safeMeta(mixed $meta): array
    {
        if (! is_array($meta)) {
            return [];
        }

        $safe = [];

        foreach ($meta as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            $safe[$key] = mb_substr((string) $value, 0, 120);
        }

        return $safe;
    }

    /**
     * A notice's date, in both the machine form and the one a reader expects.
     *
     * ETHR is Ethiopian-first and `tenants.ethiopian_calendar` is a real
     * setting, so a notice on a woreda administration's page dated in the
     * Gregorian calendar is simply the wrong date to the person reading it.
     * The ISO value still goes in `<time datetime>`, so a crawler and a screen
     * reader get something unambiguous either way.
     *
     * A malformed stored value yields no date rather than an exception: this
     * runs on an anonymous page, where the only acceptable failure is the
     * missing line.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function date(Tenant $tenant, ?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [null, null];
        }

        try {
            $date = CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return [null, null];
        }

        $iso = $date->toDateString();

        if (! $tenant->ethiopian_calendar) {
            return [$iso, $date->format('j F Y')];
        }

        $ethiopian = app(EthiopianCalendar::class)->gregorianToEthiopian(Carbon::instance($date->toDateTime()));

        return [$iso, sprintf('%02d/%02d/%d', $ethiopian['day'], $ethiopian['month'], $ethiopian['year'])];
    }

    public function hasContent(): bool
    {
        return $this->title !== '' || $this->body !== '' || $this->imageUrl !== null;
    }

    /**
     * The body split into paragraphs.
     *
     * The same treatment the profile description already gets: blank lines
     * become paragraphs, and nothing else is interpreted. `nl2br` on raw output
     * would be the shorter way and would require unescaped echo, which no
     * public view is allowed.
     *
     * @return list<string>
     */
    public function paragraphs(): array
    {
        if ($this->body === '') {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\R{2,}/', trim($this->body)) ?: [],
            static fn (string $p): bool => trim($p) !== '',
        ));
    }
}
