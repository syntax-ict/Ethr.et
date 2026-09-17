<?php

declare(strict_types=1);

use App\Http\Controllers\Public\TenantLandingController;
use App\Http\Controllers\Public\TenantPublicAssetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public tenant surface
|--------------------------------------------------------------------------
|
| Served from `{tenant}.ethr.et/` to anyone, with no session and no token.
|
| A separate file from `api.php` and `web.php` on purpose. The middleware
| stack is registered in bootstrap/app.php and is deliberately short: security
| headers, tenant resolution, locale, rate limit. It carries no `auth:sanctum`,
| no `EnsureUserBelongsToTenant`, no `statefulApi()` and no cookie
| authentication — so there is no route by which an anonymous request can
| arrive at authenticated tenant logic. Keeping that true is the reason this
| file exists rather than a route group inside one of the others, where a later
| edit to the enclosing group would quietly widen it.
|
| Every route here answers 404 unless the hostname is authoritative, names an
| active tenant, and that tenant has published a profile.
|
*/

Route::get('/', TenantLandingController::class)->name('public.tenant.landing');

// A fixed kind, never a path — see App\Support\TenantPublicAsset.
//
// `/media/`, not `/assets/`: the page's own stylesheet and fonts are static
// files under `/assets/`, served by the web server before Laravel is reached.
// Keeping the dynamic route on a different prefix means the two can never
// shadow each other, whichever server is in front.
Route::get('/media/{kind}', TenantPublicAssetController::class)
    ->whereIn('kind', \App\Support\TenantPublicAsset::KINDS)
    ->name('public.tenant.asset');
