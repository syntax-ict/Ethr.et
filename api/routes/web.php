<?php

use Dedoc\Scramble\Scramble;

/*
 * `/` is registered by routes/public.php, not here.
 *
 * It used to be this file's `view('welcome')` closure. The tenant landing page
 * needs the same URI on tenant hostnames, and Laravel's route collection is
 * keyed by method and URI — the last registration wins — so leaving a route
 * here would be a declaration that silently loses. TenantLandingController
 * answers for every host instead: the tenant page on a tenant hostname, and
 * the same `welcome` view everywhere else, so nothing about the apex changed.
 *
 * In production nothing reaches either: nginx proxies `/` on the apex and the
 * platform host to Next.js, and only sends `/` on a tenant host to Laravel.
 */

// API documentation (served by Scramble when installed)
if (class_exists(Scramble::class)) {
    Scramble::registerUiRoute(path: 'api/docs');
    Scramble::registerJsonSpecificationRoute(path: 'api/docs');
}
