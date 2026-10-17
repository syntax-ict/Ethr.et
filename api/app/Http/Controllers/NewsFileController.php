<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\FileStorageService;
use App\Support\DocumentDownload;
use App\Support\TenantLanding;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public copy of a document attached to a news story, for the
 * organisation's article page.
 *
 * Unlike a cover photo, a document answers only while its story is public:
 * the organisation is in use, shows news, and the story is publicly listed
 * (`Announcement::publiclyListed()`, the one rule every public news surface
 * reads). Unpublishing, archiving or narrowing the story's audience takes the
 * document off the public address at once, which is why the response may be
 * cached for five minutes and no longer.
 *
 * A download link has no way to send `X-Tenant`, so the organisation is
 * named in the address and the story is looked up with the tenant scope
 * dropped and `tenant_id` stated on the same chain: the organisation the
 * address names, and only that one. A host that resolved a different
 * organisation is refused, as KioskSession refuses a token that names another.
 */
class NewsFileController extends Controller
{
    #[ExcludeRouteFromDocs]
    public function __invoke(FileStorageService $storage, CurrentTenant $currentTenant, string $publicId, string $story, string $file): Response
    {
        $tenant = Tenant::query()->where('public_id', $publicId)->first();
        $resolved = $currentTenant->get();

        $announcement = $tenant !== null
            && $tenant->isActive()
            && ($resolved === null || $resolved->id === $tenant->id)
            && TenantLanding::present($tenant)['sections']['announcements']
                ? Announcement::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('public_id', $story)
                    ->publiclyListed()
                    ->first()
                : null;

        $entry = $announcement?->attachedFile($file);
        abort_if($entry === null, 404);
        $bytes = $storage->read($entry['path']);
        abort_if($bytes === null, 404);

        return DocumentDownload::pdf($bytes, $entry['name'], 'public, max-age=300');
    }
}
