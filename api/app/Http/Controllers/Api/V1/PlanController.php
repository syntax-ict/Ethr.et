<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    /**
     * The public plan catalog, as the pricing page renders it.
     */
    public function index(): JsonResponse
    {
        // Notes kept out of the docblock above: Scramble publishes that as the
        // endpoint's public description, and these are reasons for maintainers.
        //
        // Unauthenticated and on the shared 60/min-per-IP `api` limiter, as it
        // has always been. What changed is that the marketing copy comes from
        // here too — the currency, the billing period, the sentence under each
        // plan name, the highlighted card and the sales bullets were hardcoded
        // in pricing-content.tsx, where a wrong figure was nobody's job to fix.
        //
        // Filtered on is_public as well as is_active, which are not the same
        // question: active means "may be subscribed to", public means
        // "advertised". A negotiated or grandfathered plan is the first and not
        // the second, and without the distinction an operator would have to
        // choose between advertising a bespoke price and cancelling the
        // customer on it.
        //
        // The rows go out through PlanResource so the published shape is the
        // one this endpoint actually sends. The `data` envelope is explicit
        // because AppServiceProvider calls JsonResource::withoutWrapping(), so
        // returning the collection directly would emit a bare array and break
        // every existing caller.
        //
        // marketing_features, not features. The latter holds PlanFeature keys
        // that RequiresPlanFeature gates real routes on; publishing them here
        // would leak the capability vocabulary and invite the pricing page to
        // render enforcement flags as sales copy.
        $plans = Plan::where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get([
                'public_id',
                'name',
                'slug',
                'description',
                'description_am',
                'price_cents',
                'currency',
                'billing_interval',
                'max_employees',
                'max_branches',
                'max_devices',
                'features',
                'marketing_features',
                'marketing_features_am',
                'is_popular',
                'sort_order',
            ]);

        return response()->json(['data' => PlanResource::collection($plans)]);
    }
}
