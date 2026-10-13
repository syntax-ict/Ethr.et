<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\FileStorageService;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public copy of an organisation's uploaded logo, for its sign-in page.
 *
 * Public by necessity — the page is drawn before anyone signs in — and safe to
 * be: it serves only the re-encoded PNG FileStorageService::uploadLogo() wrote,
 * keyed by the tenant's public id and a content hash. A new upload is a new
 * URL, so a response can be cached for a year; a stale or guessed version, an
 * organisation that is not in use, or a missing file is a 404.
 *
 * `Tenant` is a global model (no tenant scope to bypass): the lookup states
 * exactly which organisation and which version it wants.
 */
class OrganisationLogoController extends Controller
{
    #[ExcludeRouteFromDocs]
    public function __invoke(FileStorageService $storage, string $publicId, string $version): Response
    {
        $tenant = Tenant::query()
            ->where('public_id', $publicId)
            ->where('logo_version', $version)
            ->first();

        $path = $tenant?->logo_storage_path;
        $bytes = $tenant !== null && $tenant->isActive() && is_string($path) ? $storage->read($path) : null;

        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.$version.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cross-Origin-Resource-Policy' => 'same-site',
        ]);
    }
}
