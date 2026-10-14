<?php

use App\Http\Controllers\OrganisationEntryController;
use App\Http\Controllers\OrganisationLoginPageController;
use App\Http\Controllers\OrganisationPreviewImageController;
use App\Http\Controllers\OrganisationSitemapController;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// API documentation (served by Scramble when installed)
if (class_exists(Scramble::class)) {
    Scramble::registerUiRoute(path: 'api/docs');
    Scramble::registerJsonSpecificationRoute(path: 'api/docs');
}

// The portals' sitemap, named beside the site's own in robots.txt, and the
// picture a shared link to a portal shows. Both public, both without the web
// group for the reason the entry URL below gives; both before it, so neither
// name can be taken for an organisation's.
Route::get('/sitemap-organisations.xml', OrganisationSitemapController::class)
    ->withoutMiddleware('web');
Route::get('/organisation-previews/{publicId}/{version}', OrganisationPreviewImageController::class)
    ->where(['publicId' => '[0-9A-HJKMNP-TV-Z]{26}', 'version' => '[0-9a-f]{16}'])
    ->withoutMiddleware('web')
    ->name('organisation.preview');

// /login on an organisation's own host (acme.ethr.et, a verified custom
// domain): .htaccess group 2a sends it here instead of serving the exported
// page as a file, so that host gets the organisation's head too. On the
// shared host the file is served by Apache and this route is never reached.
Route::get('/login', OrganisationLoginPageController::class)
    ->withoutMiddleware('web');

// ethr.et/{slug} — an organisation's entry URL. Registered last and limited to
// one lower-case segment, so it can never shadow a real route. .htaccess sends
// only paths with no file behind them here, which includes every scanner probe
// for /wp-admin and the like — so it runs without the web group: no session
// row written per request, no cookie, nothing the redirect needs.
// Either case ((?i); the controller lower-cases), so ethr.et/Habru reaches
// habru instead of a 404. .htaccess group 2b matches [NC] to agree, and
// SharedHostingHtaccessTest pins the pattern itself to the rule's.
Route::get('/{slug}', OrganisationEntryController::class)
    ->where('slug', '(?i)[a-z0-9][a-z0-9-]{0,62}')
    ->withoutMiddleware('web');
