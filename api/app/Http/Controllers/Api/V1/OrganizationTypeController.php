<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OrganizationType;
use Illuminate\Http\JsonResponse;

/**
 * The kinds of organisation the platform recognises, for the registration
 * form and the organisation's settings. Public, like the plan and template
 * catalogues: it names categories, not anyone's data.
 */
class OrganizationTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $types = OrganizationType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (OrganizationType $type): array => [
                'code' => $type->code,
                'name' => $type->name,
                'name_am' => $type->name_am,
                'description' => $type->description,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $types]);
    }
}
