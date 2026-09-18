<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Models\TenantPublicItem;
use App\Models\TenantPublicProfile;

/**
 * Which stored file backs a public asset, and whether there is one at all.
 *
 * The public asset route accepts a *kind* — `logo` or `hero` — and never a
 * path. This class is where a kind becomes a path, by reading the database.
 * That is the whole defence against traversal and IDOR on the public surface:
 * there is no user-supplied component to sanitise, because there is no
 * user-supplied component.
 *
 * It also enforces the one rule that the legacy `tenants.logo_path` column
 * makes necessary. That column is validated as `string|max:500` and has held
 * arbitrary values — including external URLs — since before this feature
 * existed. Rendering one on a public page would mean the page issues a request
 * to a host a tenant administrator chose, which is a tracking vector at best.
 * So a value that is not a path this application wrote is treated as *no logo*.
 * The tenant re-uploads and it works; until then the page renders without one,
 * which is the correct direction to fail.
 */
final class TenantPublicAsset
{
    public const LOGO = 'logo';

    public const HERO = 'hero';

    /** The kinds the public route will serve. Anything else is a 404. */
    public const KINDS = [self::LOGO, self::HERO];

    /**
     * The storage path behind one section item's image, or null.
     *
     * The same ownership test as the logo and hero. It is applied again here
     * rather than trusted from the upload, because this path is reached from an
     * anonymous route by a ULID in the URL: the ULID is resolved through the
     * tenant-scoped model, so it cannot name another tenant's row, and then the
     * path itself is re-checked so a row whose column was written by some other
     * means still cannot point outside the tenant's own prefix.
     */
    public static function sectionImagePath(Tenant $tenant, TenantPublicItem $item): ?string
    {
        return self::isOwnedPath($tenant, $item->image_path) ? $item->image_path : null;
    }

    /**
     * The public URL for a section item's image.
     *
     * A ULID, never a path. The route takes the item's `public_id` and the
     * controller resolves it through the scoped model, so the URL reveals
     * nothing about where files live and cannot be edited into one that reads
     * somewhere else.
     */
    public static function sectionUrl(TenantPublicItem $item): string
    {
        return '/media/section/'.$item->public_id;
    }

    /**
     * The storage path backing a kind, or null when there is nothing to serve.
     *
     * The profile is nullable because the logo does not live on it — it is
     * `tenants.logo_path`, which a tenant can have set long before it ever
     * opens the public page settings. Requiring a profile row here reported
     * "no logo" for a tenant that had a perfectly good one.
     */
    public static function pathFor(Tenant $tenant, ?TenantPublicProfile $profile, string $kind): ?string
    {
        $path = match ($kind) {
            self::LOGO => $tenant->logo_path,
            self::HERO => $profile?->hero_image_path,
            default => null,
        };

        return self::isOwnedPath($tenant, $path) ? $path : null;
    }

    /**
     * Whether a stored value is a storage path this tenant owns.
     *
     * Two conditions, both required. It must live under the tenant's own
     * `tenants/{public_id}/` prefix — the one FileStorageService writes — which
     * rules out both external URLs and another tenant's files. And it must not
     * contain a parent-directory segment, so a path that satisfies the prefix
     * and then climbs out of it is refused rather than normalised.
     */
    private static function isOwnedPath(Tenant $tenant, mixed $path): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }

        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        // An absolute URL is never a storage path, however plausible it looks.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1 || str_starts_with($path, '//')) {
            return false;
        }

        return str_starts_with($path, "tenants/{$tenant->public_id}/");
    }

    /**
     * The `Content-Type` for a stored path, from a fixed map.
     *
     * Derived from the extension rather than sniffed, and unknown extensions
     * return null so the caller 404s instead of guessing. Uploads are already
     * magic-byte verified (VerifyUploadedFiles) and re-encoded, so a path in
     * the database with an extension outside this list is a sign something is
     * wrong, not a file to serve with a hopeful content type.
     */
    public static function contentTypeFor(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => null,
        };
    }
}
