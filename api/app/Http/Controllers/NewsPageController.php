<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\OrganisationHost;
use App\Support\PortalPage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `/news` and `/news/{id}` on an organisation's own host: the exported news
 * pages, served with that organisation's head (title, description, canonical,
 * link preview, NewsArticle record) put in by PortalPage, exactly as `/login`
 * is (OrganisationLoginPageController). The `.htaccess` (group 1a) sends the
 * two paths here on every host that is not the apex, its www alias or the
 * console; on the shared host Apache serves the same files directly, and the
 * pages draw themselves from the API with `?org=` naming the organisation.
 *
 * The host is the only selector (OrganisationHost). A host that names no
 * organisation gets the file unchanged and out of search results; a page the
 * organisation does not have, or an article it has not published, is a 404
 * carrying the same file, so the visitor still sees a way back. Runs without
 * the web group, like the sign-in page: no session row, no cookie.
 */
final class NewsPageController
{
    public function index(Request $request): Response
    {
        $page = public_path('news.html');
        if (! is_file($page)) {
            return response('Not Found', 404);
        }

        $tenant = OrganisationHost::tenant($request->getHost());

        return $tenant === null ? PortalPage::plain($page) : PortalPage::news($tenant, $page);
    }

    public function show(Request $request, string $publicId): Response
    {
        // The one shell every article is served from; the id is in the URL,
        // where the page reads it back (useRouteId).
        $page = public_path('news/__id__.html');
        if (! is_file($page)) {
            return response('Not Found', 404);
        }

        $tenant = OrganisationHost::tenant($request->getHost());

        return $tenant === null ? PortalPage::plain($page) : PortalPage::article($tenant, $page, $publicId);
    }
}
