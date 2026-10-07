<?php

use App\Http\Controllers\OrganisationEntryController;
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

// ethr.et/{slug} — an organisation's entry URL. Registered last and limited to
// one lower-case segment, so it can never shadow a real route. .htaccess sends
// only paths with no file behind them here, which includes every scanner probe
// for /wp-admin and the like — so it runs without the web group: no session
// row written per request, no cookie, nothing the redirect needs.
Route::get('/{slug}', OrganisationEntryController::class)
    ->where('slug', '[a-z0-9][a-z0-9-]{0,62}')
    ->withoutMiddleware('web');
