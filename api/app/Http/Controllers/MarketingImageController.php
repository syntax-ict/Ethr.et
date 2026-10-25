<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MarketingItem;
use App\Models\MarketingSection;
use App\Services\FileStorageService;
use App\Support\MarketingImage;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public copy of a landing-page picture: the full image or one of its
 * narrower copies (`image-1280`, `image-640`), for a row or for the hero.
 *
 * Public by necessity and safe to be, on the same terms as
 * OrganisationAssetController: it serves only what
 * FileStorageService::uploadMarketingImage() re-encoded, keyed by the
 * owner's public id and a content hash. A new upload is a new URL, so a
 * response is cached for a year; a stale or guessed version is a 404.
 *
 * It does not check `is_published`: the console previews a draft row's
 * picture before publishing it, and the version is a 16-hex content hash
 * nobody guesses. Both models are global, so there is no tenant scope here.
 */
class MarketingImageController extends Controller
{
    #[ExcludeRouteFromDocs]
    public function __invoke(FileStorageService $storage, string $owner, string $variant, string $version): Response
    {
        $model = MarketingItem::query()->where('public_id', $owner)->first()
            ?? MarketingSection::query()->where('public_id', $owner)->first();

        $path = $model !== null && $model->image_version === $version
            ? MarketingImage::pathFor($model, $variant)
            : null;
        $bytes = is_string($path) ? $storage->read($path) : null;

        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.$version.'-'.$variant.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cross-Origin-Resource-Policy' => 'same-site',
        ]);
    }
}
