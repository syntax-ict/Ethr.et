<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OrganizationType;
use App\Services\Onboarding\PolicyProfileCatalog;
use App\Support\OrganisationSectors;
use Illuminate\Http\JsonResponse;

/**
 * The kinds of organisation the platform recognises, for the registration
 * form and the organisation's settings. Public, like the plan and template
 * catalogues: it names categories, not anyone's data.
 */
class OrganizationTypeController extends Controller
{
    public function __construct(
        private readonly OrganisationSectors $sectors,
        private readonly PolicyProfileCatalog $policies,
    ) {}

    public function index(): JsonResponse
    {
        // `suggested_for` names the industry bases a kind is a likely answer
        // for, so a form can offer a sector its own kinds first. It narrows
        // nothing server-side: every kind stays valid for every sector.
        // `policy_profile` is the policy the server starts that kind from, so
        // the client need not repeat the list of civil-service kinds.
        $types = OrganizationType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (OrganizationType $type): array => [
                'code' => $type->code,
                'name' => $type->name,
                'name_am' => $type->name_am,
                'description' => $type->description,
                'group' => $type->group_code,
                'parent_code' => $type->parent_code,
                'suggested_for' => $this->sectors->basesSuggesting($type->group_code),
                'policy_profile' => $this->policies->forLineage(
                    array_values(array_filter([$type->code, $type->parent_code])),
                ),
            ])
            ->values()
            ->all();

        return response()->json(['data' => $types]);
    }
}
