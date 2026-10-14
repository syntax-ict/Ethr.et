<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\PortalPreviewImage;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Symfony\Component\HttpFoundation\Response;

/**
 * The picture a shared link to an organisation's portal shows, on the terms
 * of its other brand images: public by necessity (a chat or a crawler
 * fetches it with no session), drawn only from what the page shows anyone,
 * keyed by the tenant's public id and a hash of its inputs. A stale or
 * guessed version, or an organisation not in use, is a 404.
 *
 * `Tenant` is a global model (no tenant scope to bypass): the lookup states
 * exactly which organisation it wants.
 */
class OrganisationPreviewImageController extends Controller
{
    #[ExcludeRouteFromDocs]
    public function __invoke(PortalPreviewImage $preview, string $publicId, string $version): Response
    {
        $tenant = Tenant::query()->where('public_id', $publicId)->first();

        abort_if($tenant === null || ! $tenant->isActive() || PortalPreviewImage::version($tenant) !== $version, 404);

        return response($preview->bytes($tenant, $version), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.$version.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}
