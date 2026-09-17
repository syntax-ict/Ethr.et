<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteContentResource;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class SiteContentController extends Controller
{
    /**
     * The contact details, brand and figures the public site renders.
     */
    public function index(): JsonResponse
    {
        // Notes kept out of the docblock: Scramble publishes that as the
        // endpoint's public description.
        //
        // NOT behind EnsurePlatformContext, deliberately. That middleware 404s
        // whenever a tenant IS resolved, and the marketing site is served from
        // every tenant subdomain as well as the apex — gating it would break
        // the header and footer on exactly the hosts most visitors arrive at.
        // There is nothing to gate: SiteContentResource publishes a whitelist,
        // and the bank account this table also holds is not on it.
        //
        // First cache on this model. Fixed key, integer TTL, invalidated from
        // the model's saved hook rather than here, so any writer clears it —
        // see PlatformSetting::PUBLIC_CACHE_KEY. Never Cache::tags(): Redis was
        // removed for the shared-hosting target and the file and database
        // stores cannot tag.
        // The `data` envelope below is spelled out because AppServiceProvider
        // calls JsonResource::withoutWrapping(), so returning the resource
        // directly would emit a bare object and break the shape every other
        // endpoint uses. (Kept here rather than beside the return: Scramble
        // publishes a comment adjacent to the returned expression as the
        // endpoint's public description.)
        //
        // The model is cached, not the resolved array. Resolving inside the
        // closure types the response as `unknown` in the generated contract —
        // Scramble reads the Resource class, and a plain array tells it
        // nothing. Caching the row keeps both the query saving and the type.
        $settings = Cache::remember(
            PlatformSetting::PUBLIC_CACHE_KEY,
            PlatformSetting::PUBLIC_CACHE_TTL,
            fn (): PlatformSetting => PlatformSetting::current(),
        );

        return response()->json(['data' => new SiteContentResource($settings)]);
    }
}
