<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Offboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Offboarding\UpdateExitClearanceTemplateRequest;
use App\Models\AuditLog;
use App\Services\CurrentTenant;
use App\Support\ExitClearanceTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The lines an organisation's exit clearances start with. A change applies
 * to clearances started after it; one already open keeps its lines.
 */
class ExitClearanceTemplateController extends Controller
{
    public function show(): JsonResponse
    {
        Gate::authorize('settings.manage');

        return $this->present();
    }

    public function update(UpdateExitClearanceTemplateRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = app(CurrentTenant::class)->get();
        $settings = $tenant->settings ?? [];
        $settings['exit_clearance']['items'] = ExitClearanceTemplate::normalise($request->validated('items'));
        $tenant->update(['settings' => $settings]);

        AuditLog::record('settings.exit_clearance_updated', $tenant, [
            'items' => count($settings['exit_clearance']['items']),
        ]);

        return $this->present();
    }

    private function present(): JsonResponse
    {
        $tenant = app(CurrentTenant::class)->get();

        $items = array_map(fn (array $line) => [
            'key' => $line['key'],
            'label' => ExitClearanceTemplate::label($line['key'], null, $line['label']),
            'owner' => $line['owner'],
            'kind' => $line['kind'],
        ], ExitClearanceTemplate::for($tenant));

        return response()->json([
            'items' => $items,
            'is_customized' => ExitClearanceTemplate::isCustomised($tenant),
        ]);
    }
}
