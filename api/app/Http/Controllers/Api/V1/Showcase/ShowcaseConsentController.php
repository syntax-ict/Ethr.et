<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Showcase;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateShowcaseConsentRequest;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Whether the organisation agrees to be named on ETHR's public site. Off
 * until an administrator turns it on; the time and who agreed are kept, and
 * each change is audited.
 */
class ShowcaseConsentController extends Controller
{
    public function show(CurrentTenant $current): JsonResponse
    {
        Gate::authorize('settings.manage');

        return response()->json($this->state($current->get()));
    }

    public function update(UpdateShowcaseConsentRequest $request, CurrentTenant $current): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = $current->get();
        $listed = $request->boolean('listed');

        if ($listed && $tenant->showcase_consented_at === null) {
            $tenant->forceFill([
                'showcase_consented_at' => now(),
                'showcase_consented_by' => $request->user()?->id,
            ])->save();
            AuditLog::record('settings.showcase_consented', $tenant);
        } elseif (! $listed && $tenant->showcase_consented_at !== null) {
            $tenant->forceFill([
                'showcase_consented_at' => null,
                'showcase_consented_by' => null,
            ])->save();
            AuditLog::record('settings.showcase_withdrawn', $tenant);
        }

        Cache::forget(Tenant::SHOWCASE_CACHE_KEY);

        return response()->json($this->state($tenant->refresh()));
    }

    /**
     * @return array{listed: bool, consented_at: string|null, shown: bool, hidden_by_platform: bool, active: bool}
     */
    private function state(Tenant $tenant): array
    {
        $active = $tenant->status === TenantStatus::ACTIVE;
        $hidden = $tenant->showcase_hidden_at !== null;
        $listed = $tenant->showcase_consented_at !== null;

        return [
            'listed' => $listed,
            'consented_at' => $tenant->showcase_consented_at?->toIso8601String(),
            'shown' => $listed && $active && ! $hidden,
            'hidden_by_platform' => $hidden,
            'active' => $active,
        ];
    }
}
