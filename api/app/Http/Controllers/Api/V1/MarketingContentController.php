<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarketingSectionResource;
use App\Models\MarketingSection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class MarketingContentController extends Controller
{
    /**
     * The sections and rows the public landing page renders, in order.
     */
    public function index(): JsonResponse
    {
        // Notes kept out of the docblock: Scramble publishes it.
        //
        // NOT behind EnsurePlatformContext, like /site-content: that
        // middleware 404s whenever a tenant is resolved, and the marketing
        // pages are served from tenant hosts as well as the apex. There is
        // nothing to gate; the resources publish a whitelist of published
        // rows.
        //
        // The Eloquent collection is cached, not the resolved array: Scramble
        // reads the Resource class for the response type, and a plain array
        // would type it as unknown. The key is forgotten from both models'
        // saved and deleted hooks, so any writer invalidates it.
        /** @var Collection<int, MarketingSection> $sections */
        $sections = Cache::remember(
            MarketingSection::PUBLIC_CACHE_KEY,
            MarketingSection::PUBLIC_CACHE_TTL,
            fn (): Collection => MarketingSection::query()
                ->where('is_published', true)
                ->with(['items' => fn ($items) => $items->where('is_published', true)->orderBy('sort_order')])
                ->orderBy('sort_order')
                ->get(),
        );

        // The `data` envelope is spelled out because JsonResource::withoutWrapping()
        // is on for the whole application.
        return response()->json(['data' => ['sections' => MarketingSectionResource::collection($sections)]]);
    }
}
