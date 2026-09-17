<?php

declare(strict_types=1);

namespace App\Support;

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
    ) {}

    public static function from(Tenant $tenant, TenantPublicProfile $profile): self
    {
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
     * @param  mixed  $links
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
     * @param  mixed  $theme
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
