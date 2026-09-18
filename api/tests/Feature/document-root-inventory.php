<?php

declare(strict_types=1);

/**
 * Every file in `api/public/`, and the one fact that matters about each:
 * does it belong in the shared-hosting document root?
 *
 * On the VPS these three files are the API vhost's — `infrastructure/nginx.conf`
 * roots three server blocks at `api/public`, on an origin of its own. The Plesk
 * target has no separate API origin: `docs/deployment/shared-hosting/DEPLOYMENT.md`
 * step 4a merges the API and the public marketing site into ONE document root,
 * `~/httpdocs/`. That merge is what turns an innocuous file into a shadow.
 *
 * `serve_from_shared_document_root` is therefore not a property of the file. It
 * is a decision about what happens to it under the merge, and every file needs
 * one before it ships.
 *
 * @return array<string, array{serve_from_shared_document_root: bool, why: string}>
 */
return [
    '.htaccess' => [
        'serve_from_shared_document_root' => false,
        'why' => 'Laravel\'s stock front-controller rules, and the most dangerous file here to '
            .'copy. Its catch-all — RewriteCond !-d, !-f, RewriteRule ^ index.php [L] — sends '
            .'EVERY unmatched path to Laravel. The shared document root also serves the '
            .'frontend, so under B5=no that swallows /pricing, /dashboard and every other '
            .'client-routed path. docs/deployment/shared-hosting/.htaccess is the purpose-built '
            .'replacement: it restricts the front-controller rule to ^/(api|sanctum) for exactly '
            .'this reason, and adds the security headers and deny rules this file has no reason '
            .'to carry. Loud rather than silent if confused — but confusing them is precisely '
            .'the mistake this inventory exists to prevent.',
    ],

    'favicon.ico' => [
        'serve_from_shared_document_root' => false,
        'why' => 'Laravel\'s default icon. The frontend ships its own (src/public/favicon.ico, '
            .'verified present), which reaches the document root via the Node app under B5=yes '
            .'or the exported out/ under B5=no. Copying this one shadows it. Cosmetic severity, '
            .'identical mechanism to robots.txt.',
    ],

    'index.php' => [
        'serve_from_shared_document_root' => true,
        'why' => 'THE front controller, and the only file here that is copied. Three require '
            .'paths must be repointed at ~/ethr/api — lines 9 (maintenance), 14 (autoload) and '
            .'18 (bootstrap). See DEPLOYMENT.md step 4a; line 9 is the one earlier versions of '
            .'that runbook missed, which leaves `php artisan down` inert.',
    ],

    'robots.txt' => [
        'serve_from_shared_document_root' => false,
        'why' => 'Reads "User-agent: * / Disallow:" — allow everything, no sitemap. Correct for '
            .'an API-only origin; wrong for the origin that also serves the public site. Copied '
            .'into ~/httpdocs/ it is served at https://www.ethr.et/robots.txt and silently '
            .'replaces the frontend\'s generated robots.txt (src/src/app/robots.ts), dropping '
            .'the Disallow list for /admin, /dashboard, /login, /register and the reset routes, '
            .'and dropping the Sitemap: pointer that is how a crawler reaches /sitemap.xml at '
            .'all. The site works perfectly and nothing logs it.',
    ],
];
