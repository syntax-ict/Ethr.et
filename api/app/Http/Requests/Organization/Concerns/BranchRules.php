<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization\Concerns;

use App\Rules\Phone;
use App\Rules\ReferenceCode;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The branch fields, shared by `StoreBranchRequest` and `UpdateBranchRequest`.
 *
 * The two differ only in whether `name` must be present, so that is the one
 * argument. Scramble evaluates `rules()` at runtime, so returning this array
 * publishes the same contract as spelling it out — `api/openapi.json` and
 * `src/src/api/generated.ts` were regenerated and are byte-identical. Keep
 * explanatory comments off the array keys: Scramble publishes them as OpenAPI
 * descriptions.
 */
trait BranchRules
{
    /**
     * @param  'required'|'sometimes'  $presence
     * @return array<string, list<string|ValidationRule>>
     */
    protected function branchRules(string $presence): array
    {
        $branch = $this->route('branch');
        $stored = is_object($branch) && isset($branch->phone) ? (string) $branch->phone : null;

        return [
            'name' => [$presence, 'string', 'min:2', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'required_with:town_code', 'string', 'max:100'],
            'region_code' => ['nullable', 'string', 'max:60', new ReferenceCode(['region'])],
            'town_code' => ['nullable', 'string', 'max:60', new ReferenceCode(['town', 'sub_city'], 'region_code')],
            'phone' => ['nullable', 'string', 'max:20', Phone::any()->unlessUnchanged($stored)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'geofence_radius_meters' => ['nullable', 'integer', 'min:10', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
