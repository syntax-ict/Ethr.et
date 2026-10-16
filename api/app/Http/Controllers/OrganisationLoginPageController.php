<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\OrganisationHost;
use App\Support\PortalPage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `/login` on an organisation's own host: acme.ethr.et, or its verified
 * custom domain. Apache serves the exported sign-in page for that path on
 * the shared host, where the organisation is in the path and
 * OrganisationEntryController renders its head; on an organisation's own
 * host the `.htaccess` (group 2a) sends the same path here, so the page
 * gets the same head there (2026-10-14, the one gap PR 4 of the portal
 * left open).
 *
 * The host is the only selector: no header, no query, no form field, so
 * nothing a visitor sends can make one organisation's page carry another's
 * words. A host that names no organisation in use, or a deployment with no
 * APP_DOMAIN, gets the page as exported, which draws itself from the API
 * exactly as before. Runs without the web group for the reason the entry
 * URL gives: no session row, no cookie.
 *
 * `Tenant` is a global model (no tenant scope to bypass); each lookup states
 * the host it is for, the same way ResolveTenant's do.
 */
final class OrganisationLoginPageController
{
    public function __invoke(Request $request): Response
    {
        $page = public_path('login.html');
        if (! is_file($page)) {
            return response('Not Found', 404);
        }

        $tenant = OrganisationHost::tenant($request->getHost());

        if ($tenant === null) {
            return PortalPage::plain($page);
        }

        return PortalPage::respond($tenant, $page);
    }
}
