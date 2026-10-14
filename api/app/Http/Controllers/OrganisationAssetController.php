<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\FileStorageService;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public copy of an organisation's other brand images, for its portal:
 * the dark-background logo, the square mark, the cover photo and the cover's
 * narrower variants (`cover-1280`, `cover-640`).
 *
 * Public by necessity and safe to be, on the same terms as
 * OrganisationLogoController: it serves only what
 * FileStorageService::uploadBrandImage() re-encoded, keyed by the tenant's
 * public id, the kind and a content hash. A new upload is a new URL, so a
 * response can be cached for a year; a stale or guessed version, an
 * organisation that is not in use, or a missing file is a 404.
 *
 * `Tenant` is a global model (no tenant scope to bypass): the lookup states
 * exactly which organisation it wants, and the version is checked against
 * the stored entry.
 */
class OrganisationAssetController extends Controller
{
    #[ExcludeRouteFromDocs]
    public function __invoke(FileStorageService $storage, string $publicId, string $kind, string $version): Response
    {
        [$base, $variant] = match ($kind) {
            'logo-dark' => ['logo_dark', null],
            'mark' => ['mark', null],
            'cover' => ['cover', null],
            'cover-1280' => ['cover', 1280],
            'cover-640' => ['cover', 640],
            default => [null, null],
        };

        $tenant = $base === null ? null : Tenant::query()->where('public_id', $publicId)->first();
        $asset = $tenant !== null && $tenant->isActive() ? $tenant->brandAsset($base) : null;
        $path = $asset !== null && $asset['version'] === $version
            ? ($variant === null ? $asset['path'] : (($asset['variants'] ?? [])[$variant] ?? null))
            : null;
        $bytes = is_string($path) ? $storage->read($path) : null;

        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => $base === 'cover' ? 'image/jpeg' : 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.$version.($variant === null ? '' : '-'.$variant).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cross-Origin-Resource-Policy' => 'same-site',
        ]);
    }
}
