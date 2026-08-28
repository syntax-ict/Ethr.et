<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\GenerateScimTokenRequest;
use App\Http\Requests\Settings\UpdateBrandingRequest;
use App\Http\Requests\Settings\UpdateOrganizationRequest;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Http\Requests\Settings\UpdateSsoRequest;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\SsoSetting;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = app(CurrentTenant::class)->get();

        return response()->json([
            'organization' => [
                'name' => $tenant->name,
                'subdomain' => $tenant->subdomain,
                'type' => $tenant->type,
                // `tenants.timezone` / `tenants.default_locale` are the real
                // columns the rest of the app reads (TenantResource,
                // UserProvisioningService). This endpoint used to read and write
                // only the settings JSON, so changing the language here changed
                // nothing anyone consumed. Both columns are NOT NULL with
                // defaults, so there is no fallback to make — values saved
                // through the old JSON path were moved across in
                // 2026_08_22_000001_backfill_tenant_timezone_and_locale.
                'timezone' => $tenant->timezone,
                'locale' => $tenant->default_locale,
            ],
            'branding' => [
                'logo_url' => $tenant->logo_path,
                'theme' => $tenant->theme ?? [],
            ],
            // Attendance rules (grace period, OT cap, confidence threshold) live
            // on the AttendanceSetting model and are served by GET/PUT
            // /attendance/settings so all attendance configuration stays in one
            // place.
            'leave' => [
                'working_days' => $tenant->settings['working_days'] ?? [1, 2, 3, 4, 5],
            ],
            'payroll' => [
                'pay_period' => $tenant->settings['pay_period'] ?? 'monthly',
                'run_day' => $tenant->settings['run_day'] ?? 25,
                'fiscal_year_start_month' => $tenant->settings['fiscal_year_start_month'] ?? 1,
                'pagumen_proration_strategy' => $tenant->settings['pagumen_proration_strategy'] ?? 'full_month',
                // Retirement-case eligibility dates are computed against this.
                // No single figure is authoritative across every Ethiopian
                // sector, so it defaults to 60 but stays tenant-overridable
                // rather than hard-coded, the same treatment as the tax
                // brackets and Pagumen strategy above.
                'retirement_age' => $tenant->settings['retirement_age'] ?? 60,
            ],
            'security' => [
                'mfa_policy' => $tenant->settings['mfa_policy'] ?? 'optional',
                'session_timeout_minutes' => $tenant->settings['session_timeout_minutes'] ?? 480,
            ],
            'sso' => $this->ssoConfig($tenant),
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

        $tenantFields = array_intersect_key($validated, ['name' => 1, 'type' => 1, 'timezone' => 1]);

        // `locale` is the API-facing name; the column is `default_locale`, which
        // is what UserProvisioningService gives every newly provisioned user.
        if (array_key_exists('locale', $validated)) {
            $tenantFields['default_locale'] = $validated['locale'];
        }

        if (! empty($tenantFields)) {
            $tenant->update($tenantFields);
        }

        // Mirror into the settings JSON as well: it was the only store before
        // this endpoint wrote the real columns, so keeping both in step means a
        // tenant is never left with two disagreeing values.
        $settingsFields = array_intersect_key($validated, ['timezone' => 1, 'locale' => 1]);
        if (! empty($settingsFields)) {
            $tenant->update(['settings' => array_merge($tenant->settings ?? [], $settingsFields)]);
        }

        AuditLog::record('settings.organization_updated', $tenant);

        $tenant->refresh();

        return response()->json([
            'message' => 'Organization updated',
            'organization' => [
                'name' => $tenant->name,
                'subdomain' => $tenant->subdomain,
                'type' => $tenant->type,
                'timezone' => $tenant->timezone,
                'locale' => $tenant->default_locale,
            ],
        ]);
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

    public function updateSso(UpdateSsoRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = app(CurrentTenant::class)->get();

        $sso = SsoSetting::updateOrCreate(
            ['tenant_id' => $tenant->id],
            $request->validated(),
        );

        AuditLog::record('settings.sso_updated', $sso);

        return response()->json([
            'message' => 'SSO settings updated',
            'sso' => $this->ssoConfig($tenant->fresh()),
        ]);
    }

    public function generateScimToken(GenerateScimTokenRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = app(CurrentTenant::class)->get();

        $token = Str::random(64);

        $apiKey = ApiKey::create([
            'tenant_id' => $tenant->id,
            'name' => $request->input('name', 'SCIM Provisioning'),
            'key_hash' => hash('sha256', $token),
            'key_prefix' => substr($token, 0, 8),
            'abilities' => ['scim'],
            'created_by' => $request->user()->id,
            'expires_at' => now()->addYear(),
        ]);

        AuditLog::record('settings.scim_token_generated', $apiKey);

        return response()->json([
            'token' => $token,
            'prefix' => $apiKey->key_prefix,
            'expires_at' => $apiKey->fresh()->expires_at->toIso8601String(),
            'message' => 'Store this token securely — it will not be shown again.',
        ], 201);
    }

    private function ssoConfig(Tenant $tenant): array
    {
        $sso = $tenant->ssoSetting;

        if (! $sso) {
            return [
                'is_enabled' => false,
                'provider' => 'saml',
                'idp_entity_id' => null,
                'idp_sso_url' => null,
                'default_role' => 'employee',
                'auto_provision' => false,
                'metadata_url' => null,
            ];
        }

        return [
            'is_enabled' => $sso->is_enabled,
            'provider' => $sso->provider ?? 'saml',
            'idp_entity_id' => $sso->idp_entity_id,
            'idp_sso_url' => $sso->idp_sso_url,
            'default_role' => $sso->default_role ?? 'employee',
            'auto_provision' => $sso->auto_provision,
            'metadata_url' => config('app.url').'/api/v1/sso/saml/'.$tenant->subdomain.'/metadata',
        ];
    }
}
