<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Showcase;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrustedOrganisationResource;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class TrustedByController extends Controller
{
    /**
     * The organisations ETHR may name on its public site.
     */
    public function index(): JsonResponse
    {
        // Public and unauthenticated, like /site-content, and for the same
        // reason not behind EnsurePlatformContext. Only operational
        // organisations (active, or in a free trial that has not ended) that
        // agreed to be named and that the platform has not hidden
        // (Tenant::scopeShowcased), in the order they agreed, at most 48.
        // The rows are cached rather than the array, so the generated contract
        // keeps the resource's types; every write that changes the list
        // clears the key.
        $tenants = Cache::remember(
            Tenant::SHOWCASE_CACHE_KEY,
            Tenant::SHOWCASE_CACHE_TTL,
            fn () => Tenant::query()->showcased()->orderBy('showcase_consented_at')->limit(48)->get(),
        );

        return response()->json(['data' => TrustedOrganisationResource::collection($tenants)]);
    }
}
