<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['public_id', 'name', 'slug', 'price_cents', 'max_employees', 'max_branches', 'max_devices', 'features', 'sort_order']);

        return response()->json(['data' => $plans]);
    }
}
