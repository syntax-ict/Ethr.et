<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PublicPagePreset;
use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use Illuminate\Support\Str;

/**
 * Everything the public landing page is allowed to know, and nothing else.
 *
 * The Blade view never receives a Tenant or a TenantPublicProfile. It receives
 * one of these, built here from an explicit list of fields. That turns "do not
 * expose private data on the public page" from a rule someone has to remember
 * into a property of the type: a template cannot render `$tenant->settings`
 * because no `$tenant` is in scope, and adding a field to the page means adding
 * it here first, in a file whose entire purpose is to be read carefully.
 *
 * Note what is taken from Tenant: four fields. `settings` is excluded because
 * it carries `login_identifiers` and other operational configuration; the
 * relations are excluded because employees, payroll and attendance hang off
 * them. `logo_path` is excluded too — see $hasLogo, which exposes whether a
 * logo exists without exposing where it lives.
 */
final class PublicTenantPage
{
    /**
     * @param  array<string, string>  $socialLinks
     * @param  array<string, string>  $theme
     */
    private function __construct(
        public readonly string $name,
        public readonly string $subdomain,
        public readonly ?string $organizationType,
        public readonly ?string $headline,
        public readonly ?string $description,
        public readonly ?string $contactEmail,
        public readonly ?string $contactPhone,
        public readonly ?string $addressLine,
        public readonly ?string $city,
        public readonly ?string $region,
        public readonly ?string $websiteUrl,
        public readonly array $socialLinks,
        public readonly ?string $metaDescription,
        public readonly array $theme,
        public readonly bool $hasLogo,
        public readonly bool $hasHero,
        public readonly bool $isIndexable,
        public readonly PublicPagePreset $preset,
    ) {}

    /**
     * Build the view-model for a page.
     *
     * The preset is resolved by the caller and passed in rather than derived
     * here. `PresetResolver` reads `tenants.settings` to find the onboarding
     * industry, and `settings` is precisely what this class exists to keep
     * away from a template — so the blob is read outside, and only the
     * resulting enum case crosses the boundary.
     */
    public static function from(
        Tenant $tenant,
        TenantPublicProfile $profile,
        PublicPagePreset $preset = PublicPagePreset::GENERAL,
    ): self {
        return new self(
            name: $tenant->name,
            subdomain: $tenant->subdomain,
            organizationType: $tenant->type,
            headline: $profile->headline,
            description: $profile->description,
            contactEmail: $profile->contact_email,
            contactPhone: $profile->contact_phone,
            addressLine: $profile->address_line,
            city: $profile->city,
            region: $profile->region,
            websiteUrl: self::safeUrl($profile->website_url),
            socialLinks: self::safeSocialLinks($profile->social_links),
            metaDescription: self::metaDescription($profile),
            theme: self::safeTheme($tenant->theme),
            hasLogo: TenantPublicAsset::pathFor($tenant, $profile, TenantPublicAsset::LOGO) !== null,
            hasHero: TenantPublicAsset::pathFor($tenant, $profile, TenantPublicAsset::HERO) !== null,
            isIndexable: $profile->is_indexable,
            preset: $preset,
        );
    }

    /**
     * The tenant's brand colours as a `style` attribute value.
     *
     * Built here rather than in the template so no public Blade file needs an
     * `@php` block — a rule the XSS test enforces, because a block of arbitrary
     * PHP in a template is how the next unescaped echo gets written without
     * looking like one. The values were already narrowed to six-digit hex by
     * safeTheme(), so nothing user-controlled reaches the attribute.
     */
    public function themeStyleAttribute(): string
    {
        $declarations = [];

        foreach ([
            '--tenant-primary' => 'primary_color',
            '--tenant-secondary' => 'secondary_color',
            '--tenant-accent' => 'accent_color',
        ] as $property => $key) {
            if (isset($this->theme[$key])) {
                $declarations[] = "{$property}: {$this->theme[$key]}";
            }
        }

        return implode('; ', $declarations);
    }

    /**
     * The Organization JSON-LD for this page.
     *
     * Built here rather than inline in the template, and not only for tidiness:
     * Blade compiles a directive's arguments by matching brackets, and a
     * multi-line array literal followed by a second argument defeats that
     * matcher outright — `@json(array_filter([...]), $flags)` failed to compile
     * with "Unclosed '[' ... does not match ')'", taking every test that renders
     * this page with it. The template now passes two plain variables, which
     * cannot be mis-balanced.
     *
     * Reads only from this object, so the JSON-LD cannot expose a field the
     * visible page would not.
     *
     * @return array<string, mixed>
     */
    public function jsonLd(string $canonicalUrl): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $this->name,
            'url' => $canonicalUrl,
            'description' => $this->metaDescription,
            'logo' => $this->hasLogo ? $canonicalUrl.'media/logo' : null,
            'email' => $this->contactEmail,
            'telephone' => $this->contactPhone,
            'address' => $this->formattedAddress() === null ? null : array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $this->addressLine,
                'addressLocality' => $this->city,
                'addressRegion' => $this->region,
                'addressCountry' => 'ET',
            ]),
            'sameAs' => array_values($this->socialLinks) ?: null,
        ]);
    }

    /**
     * Encoding flags for the JSON-LD block.
     *
     * JSON_HEX_TAG is the load-bearing one and the reason this is a named
     * constant rather than an inline literal: without it a `</script>` typed
     * into any tenant field closes the element early and turns the rest of the
     * document into markup the browser will act on.
     */
    public const JSON_LD_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT;

    /** The full address as one line, or null when no part of it was given. */
    public function formattedAddress(): ?string
    {
        $parts = array_filter([$this->addressLine, $this->city, $this->region]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /** Whether the contact block has anything at all to show. */
    public function hasContactDetails(): bool
    {
        return $this->contactEmail !== null
            || $this->contactPhone !== null
            || $this->formattedAddress() !== null;
    }

    /**
     * A URL safe to put in an href, or null.
     *
     * Validated again at render time even though the FormRequest validated it
     * at write time. The rows this reads are years-lived and editable by more
     * than one code path; a `javascript:` URI that reached the column through
     * any of them must not reach an anchor tag because of where it was checked.
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
     * @return array<string, string>
     */
    private static function safeSocialLinks(mixed $links): array
    {
        if (! is_array($links)) {
            return [];
        }

        $safe = [];

        foreach (TenantPublicProfile::SOCIAL_PLATFORMS as $platform => $hosts) {
            $url = $links[$platform] ?? null;

            if (! is_string($url) || self::safeUrl($url) === null) {
                continue;
            }

            // The host allow-list is what stops a "social link" from being an
            // arbitrary outbound link wearing a recognisable icon.
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            if (in_array($host, $hosts, true)) {
                $safe[$platform] = $url;
            }
        }

        return $safe;
    }

    private static function metaDescription(TenantPublicProfile $profile): ?string
    {
        if (is_string($profile->meta_description) && $profile->meta_description !== '') {
            return $profile->meta_description;
        }

        if (! is_string($profile->description) || $profile->description === '') {
            return null;
        }

        // Str::limit is multibyte-aware, so an Amharic description is cut on a
        // character boundary rather than mid-sequence.
        return Str::limit($profile->description, 160);
    }

    /**
     * @return array<string, string>
     */
    private static function safeTheme(mixed $theme): array
    {
        if (! is_array($theme)) {
            return [];
        }

        $safe = [];

        foreach (['primary_color', 'secondary_color', 'accent_color'] as $key) {
            $value = $theme[$key] ?? null;

            // Re-validated here for the same reason as safeUrl: these values are
            // interpolated into a `style` attribute, so anything that is not
            // literally a six-digit hex colour must not survive this far.
            if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
