<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\FileStorageService;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public copy of a news story's cover photo, and of its narrower copies
 * (`cover-1280`, `cover-640`), for the organisation's news pages.
 *
 * Served on the terms OrganisationAssetController serves the organisation's
 * own cover: only what FileStorageService::uploadNewsCover() re-encoded, at
 * the path that method wrote, named by the organisation's public id, the
 * story's public id and a content hash. A new upload is a new URL, so a
 * response can be cached for a year. Replacing or removing a cover, or
 * deleting its story, deletes the files, so an old address is a 404.
 *
 * The address is the only key: no story is looked up, so no tenant scope is
 * bypassed. That makes a draft's cover readable by whoever has its address,
 * which only the publishing desk shows, and which carries 64 bits of content
 * hash beside two ULIDs. `Tenant` is a global model, looked up by exactly the
 * organisation the address names.
 */
class NewsCoverController extends Controller
{
    #[ExcludeRouteFromDocs]
    public function __invoke(FileStorageService $storage, string $publicId, string $story, string $size, string $version): Response
    {
        $width = match ($size) {
            'cover-1280' => 1280,
            'cover-640' => 640,
            default => null,
        };

        $tenant = Tenant::query()->where('public_id', $publicId)->first();
        $bytes = $tenant !== null && $tenant->isActive()
            ? $storage->read(FileStorageService::newsCoverPath($tenant->public_id, $story, $version, $width))
            : null;

        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.$version.($width === null ? '' : '-'.$width).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cross-Origin-Resource-Policy' => 'same-site',
        ]);
    }
}
