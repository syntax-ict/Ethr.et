<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\TenantPublicItem;
use App\Services\FileStorageService;
use App\Services\Public\PublishedTenantLocator;
use App\Support\TenantPublicAsset;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves a published tenant's logo and hero image to anyone.
 *
 * The route takes a *kind* — `logo` or `hero` — and never a path, an id or a
 * filename. That is the whole of the traversal and IDOR defence on this
 * endpoint: there is no attacker-controlled component to sanitise, because the
 * only thing the URL carries is a word from a two-item list the router itself
 * enforces. The path comes from the database, scoped to the tenant the
 * hostname named.
 *
 * Every authenticated file in ETHR is served by a 15-minute signed URL. This
 * one cannot be: an `og:image` is fetched by a crawler days after the page was
 * rendered, and a signed URL would be dead by then. So the file is streamed
 * through PHP instead, and the public/private line is drawn by the visibility
 * check below rather than by a signature.
 */
class TenantPublicAssetController extends Controller
{
    /** A week. Re-uploading changes the ETag, so a stale cache corrects itself. */
    private const MAX_AGE = 604800;

    public function __construct(
        private readonly PublishedTenantLocator $locator,
        private readonly FileStorageService $storage,
    ) {}

    public function __invoke(Request $request, string $kind): Response
    {
        if (! in_array($kind, TenantPublicAsset::KINDS, true)) {
            throw new NotFoundHttpException;
        }

        // Same visibility rule as the page itself. An unpublished tenant's logo
        // must not be fetchable just because someone guessed the asset URL —
        // otherwise unpublishing would hide the page and leave the branding.
        [$tenant, $profile] = $this->locator->locate();

        $path = TenantPublicAsset::pathFor($tenant, $profile, $kind);

        if ($path === null) {
            throw new NotFoundHttpException;
        }

        return $this->stream($request, $path);
    }

    /**
     * One section entry's image, addressed by the entry's ULID.
     *
     * The URL carries an identifier, never a path — the same rule the fixed
     * `kind` route follows, for the same reason. The ULID is resolved through
     * the tenant-scoped model, so an identifier belonging to another tenant
     * simply is not found: isolation comes from the query failing closed, not
     * from a comparison someone has to remember to write.
     */
    public function section(Request $request, string $item): Response
    {
        [$tenant] = $this->locator->locate();

        $model = TenantPublicItem::query()->where('public_id', $item)->first();

        if ($model === null) {
            throw new NotFoundHttpException;
        }

        // An image inside a hidden section is not public. Hiding a block has to
        // hide what is in it, or unpublishing a notice would leave its
        // photograph fetchable by anyone holding the URL.
        if ($model->section === null || ! $model->section->is_visible) {
            throw new NotFoundHttpException;
        }

        $path = TenantPublicAsset::sectionImagePath($tenant, $model);

        if ($path === null) {
            throw new NotFoundHttpException;
        }

        return $this->stream($request, $path);
    }

    /**
     * Stream a storage path that has already been proven to belong here.
     *
     * Shared by both routes so the cache, ETag and content-type rules cannot
     * drift apart — a section image served without `nosniff` would be a hole
     * the logo route does not have.
     */
    private function stream(Request $request, string $path): Response
    {
        $contentType = TenantPublicAsset::contentTypeFor($path);
        $signature = $this->storage->cacheSignature($path);

        // A null content type means the extension is outside the fixed map, and
        // a null signature means the object is gone. Either is a 404 rather
        // than a guess: the type is never sniffed from the bytes, so a file
        // that should not be here cannot be served as something it is not.
        if ($contentType === null || $signature === null) {
            throw new NotFoundHttpException;
        }

        $etag = '"'.$signature.'"';

        if (trim((string) $request->headers->get('If-None-Match'), 'W/') === $etag) {
            return response()->noContent(304)->setEtag($signature);
        }

        $stream = $this->storage->readStream($path);

        if ($stream === null) {
            throw new NotFoundHttpException;
        }

        $response = new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200);

        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Content-Disposition', 'inline');
        $response->headers->set('ETag', $etag);

        // `public` here, unlike the HTML page, and the difference is deliberate.
        // An image is keyed by its own URL, which carries the tenant hostname,
        // so a shared cache cannot confuse two tenants' logos the way it could
        // confuse two tenants' pages at the same `/` path.
        $response->headers->set('Cache-Control', 'public, max-age='.self::MAX_AGE);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if (($size = $this->storage->size($path)) !== null) {
            $response->headers->set('Content-Length', (string) $size);
        }

        return $response;
    }
}
