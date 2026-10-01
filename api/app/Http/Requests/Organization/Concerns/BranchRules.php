<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization\Concerns;

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
     * @return array<string, list<string>>
     */
    protected function branchRules(string $presence): array
    {
        return [
            'name' => [$presence, 'string', 'min:2', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'geofence_radius_meters' => ['nullable', 'integer', 'min:10', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
