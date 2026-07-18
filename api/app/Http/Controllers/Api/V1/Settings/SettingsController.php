<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateBrandingRequest;
use App\Http\Requests\Settings\UpdateOrganizationRequest;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\AuditLog;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $tenant = app(CurrentTenant::class)->get();

        return response()->json([
            'organization' => [
                'name' => $tenant->name,
                'subdomain' => $tenant->subdomain,
                'type' => $tenant->type,
                'timezone' => $tenant->settings['timezone'] ?? 'Africa/Addis_Ababa',
                'locale' => $tenant->settings['locale'] ?? 'en',
            ],
            'branding' => [
                'logo_url' => $tenant->logo_path,
                'theme' => $tenant->theme ?? [],
            ],
            'attendance' => [
                'grace_period_minutes' => $tenant->settings['grace_period_minutes'] ?? 15,
                'ot_daily_cap_minutes' => $tenant->settings['ot_daily_cap_minutes'] ?? 120,
                'confidence_threshold' => $tenant->settings['confidence_threshold'] ?? 70,
            ],
            'leave' => [
                'working_days' => $tenant->settings['working_days'] ?? [1, 2, 3, 4, 5],
            ],
            'payroll' => [
                'pay_period' => $tenant->settings['pay_period'] ?? 'monthly',
                'run_day' => $tenant->settings['run_day'] ?? 25,
                'fiscal_year_start_month' => $tenant->settings['fiscal_year_start_month'] ?? 1,
                'pagumen_proration_strategy' => $tenant->settings['pagumen_proration_strategy'] ?? 'full_month',
            ],
            'security' => [
                'mfa_policy' => $tenant->settings['mfa_policy'] ?? 'optional',
                'session_timeout_minutes' => $tenant->settings['session_timeout_minutes'] ?? 480,
            ],
        ]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = app(CurrentTenant::class)->get();
        $currentSettings = $tenant->settings ?? [];
        $newSettings = array_merge($currentSettings, $request->input('settings'));
        $tenant->update(['settings' => $newSettings]);

        AuditLog::record('settings.updated', $tenant);

        return response()->json(['message' => 'Settings updated', 'settings' => $newSettings]);
    }

    public function updateOrganization(UpdateOrganizationRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $validated = $request->validated();

        $tenant = app(CurrentTenant::class)->get();

        $tenantFields = array_intersect_key($validated, ['name' => 1, 'type' => 1]);
        if (! empty($tenantFields)) {
            $tenant->update($tenantFields);
        }

        $settingsFields = array_intersect_key($validated, ['timezone' => 1, 'locale' => 1]);
        if (! empty($settingsFields)) {
            $tenant->update(['settings' => array_merge($tenant->settings ?? [], $settingsFields)]);
        }

        AuditLog::record('settings.organization_updated', $tenant);

        return response()->json(['message' => 'Organization updated']);
    }

    public function updateBranding(UpdateBrandingRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $validated = $request->validated();

        $tenant = app(CurrentTenant::class)->get();

        $updates = [];
        if (array_key_exists('logo_url', $validated)) {
            $updates['logo_path'] = $validated['logo_url'];
        }

        $themeFields = array_intersect_key($validated, ['primary_color' => 1, 'secondary_color' => 1, 'accent_color' => 1]);
        if (! empty($themeFields)) {
            $updates['theme'] = array_merge($tenant->theme ?? [], $themeFields);
        }

        if (! empty($updates)) {
            $tenant->update($updates);
        }

        AuditLog::record('settings.branding_updated', $tenant);

        return response()->json(['message' => 'Branding updated', 'logo_url' => $tenant->logo_path, 'theme' => $tenant->theme]);
    }
}
