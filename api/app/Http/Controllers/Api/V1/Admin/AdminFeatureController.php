<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Feature;
use App\Enums\FeatureState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateFeatureStateRequest;
use App\Models\PlatformFeatureState;
use App\Services\Features\FeatureControls;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The platform feature switches (docs/FEATURE-CONTROL-CENTER-DESIGN.md §5):
 * every catalogued feature with its platform state, the reason for the last
 * change, and how many organisations hold a grant or revoke. Behind
 * `admin.manage` on the platform host with MFA, like every console endpoint
 * (owner decision D6).
 *
 * Who changed a switch is in the audit log, which names actors; it is not
 * repeated here.
 */
class AdminFeatureController extends Controller
{
    public function __construct(private readonly FeatureControls $controls) {}

    public function index(): JsonResponse
    {
        Gate::authorize('admin.manage');

        $states = PlatformFeatureState::query()->get()->keyBy('key');
        $counts = $this->controls->overrideCounts();

        return response()->json([
            'data' => array_map(
                fn (Feature $feature): array => $this->present($feature, $states->get($feature->value), $counts[$feature->value] ?? null),
                Feature::cases(),
            ),
        ]);
    }

    public function update(UpdateFeatureStateRequest $request, string $key): JsonResponse
    {
        Gate::authorize('admin.manage');

        $feature = Feature::from($key);
        $row = $this->controls->setPlatformState(
            $feature,
            FeatureState::from($request->string('state')->toString()),
            $request->string('reason')->toString(),
            $request->user(),
        );

        return response()->json($this->present($feature, $row, $this->controls->overrideCounts()[$feature->value] ?? null));
    }

    /**
     * @param  array{grants: int, revokes: int}|null  $counts
     * @return array{key: string, kind: string, overridable: bool, state: string, default_state: string, reason: string|null, changed_at: string|null, grants: int, revokes: int}
     */
    private function present(Feature $feature, ?PlatformFeatureState $row, ?array $counts): array
    {
        return [
            'key' => $feature->value,
            'kind' => $feature->kind()->value,
            'overridable' => $feature->overridable(),
            'state' => ($row !== null ? $row->state : $feature->defaultState())->value,
            'default_state' => $feature->defaultState()->value,
            'reason' => $row?->reason,
            'changed_at' => $row?->updated_at?->toIso8601String(),
            'grants' => (int) ($counts['grants'] ?? 0),
            'revokes' => (int) ($counts['revokes'] ?? 0),
        ];
    }
}
