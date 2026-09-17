<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\GenerateScimTokenRequest;
use App\Http\Requests\Settings\UpdateBrandingRequest;
use App\Http\Requests\Settings\UpdateOrganizationRequest;
use App\Http\Requests\Settings\UpdatePublicPageRequest;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Http\Requests\Settings\UpdateSsoRequest;
use App\Http\Requests\Settings\UploadPublicImageRequest;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\SsoSetting;
use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use App\Services\CurrentTenant;
use App\Services\FileStorageService;
use App\Support\TenantPublicAsset;
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

    /**
     * The tenant's public landing page configuration.
     *
     * Returns the same shape whether or not a profile row exists yet, so the
     * settings screen renders an empty form rather than an error on a tenant
     * that has never touched this. Unpublished is the default everywhere.
     */
    public function showPublicPage(): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = app(CurrentTenant::class)->get();
        $profile = $tenant->publicProfile;

        return response()->json($this->publicPagePayload($tenant, $profile));
    }

    public function updatePublicPage(UpdatePublicPageRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = app(CurrentTenant::class)->get();

        // firstOrCreate, not create: the row is made lazily on first edit, and
        // the unique index on tenant_id means two concurrent saves cannot
        // produce two profiles.
        $profile = TenantPublicProfile::firstOrCreate(['tenant_id' => $tenant->id]);

        $attributes = $request->validated();

        // `published_at` records when the page first went live and is not
        // touched again by later edits — it is the publication date a visitor
        // or a crawler would mean, not a "last saved" timestamp.
        if ($request->changesPublication() && $request->wantsPublished() && $profile->published_at === null) {
            $attributes['published_at'] = now();
        }

        if (array_key_exists('social_links', $attributes)) {
            $attributes['social_links'] = array_filter(
                $attributes['social_links'] ?? [],
                static fn ($url) => is_string($url) && $url !== ''
            ) ?: null;
        }

        $wasPublished = (bool) $profile->is_published;

        $profile->fill($attributes)->save();

        // Publication gets its own audit entry. An organisation's page becoming
        // visible on the public internet is a different event from someone
        // fixing a typo in it, and a compliance reviewer asking "when did this
        // become public, and who decided that" should not have to infer it from
        // a diff of a generic update record.
        if ($request->changesPublication() && $wasPublished !== (bool) $profile->is_published) {
            AuditLog::record(
                $profile->is_published ? 'settings.public_page_published' : 'settings.public_page_unpublished',
                $tenant,
                ['subdomain' => $tenant->subdomain],
            );
        } else {
            AuditLog::record('settings.public_page_updated', $tenant);
        }

        return response()->json(array_merge(
            ['message' => 'Public page updated'],
            $this->publicPagePayload($tenant, $profile->refresh()),
        ));
    }

    /**
     * Replace the hero image on the public page.
     *
     * Stored under the tenant's existing `tenants/{public_id}/` prefix in a
     * `public/` subdirectory, so a glance at a storage path says whether the
     * object is meant to be reachable without authentication.
     */
    public function uploadPublicHero(UploadPublicImageRequest $request, FileStorageService $storage): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = app(CurrentTenant::class)->get();
        $profile = TenantPublicProfile::firstOrCreate(['tenant_id' => $tenant->id]);

        $previous = $profile->hero_image_path;
        $uploaded = $storage->upload($request->file('image'), 'public/hero');

        $profile->update(['hero_image_path' => $uploaded['path']]);

        $this->forgetPrevious($storage, $previous, $uploaded['path']);

        AuditLog::record('settings.public_page_hero_updated', $tenant, ['path' => $uploaded['path']]);

        return response()->json(['message' => 'Hero image updated'], 201);
    }

    /**
     * Replace the tenant logo with an uploaded file.
     *
     * `PUT /settings/branding` has always accepted `logo_url` as a bare string,
     * so `tenants.logo_path` may hold an arbitrary external URL. That was
     * tolerable while the logo appeared only inside the authenticated app; on a
     * public page it would mean every anonymous visitor issues a request to a
     * third-party host, which is a tracking vector nobody opted into.
     *
     * So the public page renders a logo only when it is a file this application
     * stored (see TenantPublicAsset), and this endpoint is how a tenant gets
     * one. The old string field still works for the in-app logo; it simply has
     * no effect on the public surface.
     */
    public function uploadBrandingLogo(UploadPublicImageRequest $request, FileStorageService $storage): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = app(CurrentTenant::class)->get();

        $previous = $tenant->logo_path;
        $uploaded = $storage->upload($request->file('image'), 'public/logo');

        $tenant->update(['logo_path' => $uploaded['path']]);

        $this->forgetPrevious($storage, $previous, $uploaded['path']);

        AuditLog::record('settings.branding_logo_updated', $tenant, ['path' => $uploaded['path']]);

        return response()->json(['message' => 'Logo updated', 'logo_path' => $uploaded['path']], 201);
    }

    /**
     * Best effort cleanup of a replaced image.
     *
     * Only deletes a value that is actually one of our storage paths — a legacy
     * external URL in `logo_path` is not ours to delete, and passing one to the
     * filesystem would at best fail and at worst address something unintended.
     * An orphaned object is storage waste; a failed delete is not a failed
     * upload, so nothing here is allowed to break the request.
     */
    private function forgetPrevious(FileStorageService $storage, mixed $previous, string $current): void
    {
        if (! is_string($previous) || $previous === '' || $previous === $current) {
            return;
        }

        if (! str_starts_with($previous, 'tenants/')) {
            return;
        }

        $storage->delete($previous);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicPagePayload(Tenant $tenant, ?TenantPublicProfile $profile): array
    {
        return [
            'public_page' => [
                'url' => 'https://'.$tenant->subdomain.'.'.(config('app.domain') ?: 'ethr.et'),
                'is_published' => (bool) $profile?->is_published,
                'is_indexable' => (bool) ($profile?->is_indexable ?? true),
                'headline' => $profile?->headline,
                'description' => $profile?->description,
                'contact_email' => $profile?->contact_email,
                'contact_phone' => $profile?->contact_phone,
                'address_line' => $profile?->address_line,
                'city' => $profile?->city,
                'region' => $profile?->region,
                'website_url' => $profile?->website_url,
                'social_links' => $profile?->social_links ?? [],
                'meta_description' => $profile?->meta_description,
                'has_hero_image' => TenantPublicAsset::pathFor($tenant, $profile, TenantPublicAsset::HERO) !== null,
                // Tells the settings screen whether the stored logo will
                // actually appear publicly, so it can prompt for a re-upload
                // instead of leaving an administrator wondering why it does not.
                // Independent of the profile row: the logo is a tenant column a
                // tenant may have set long before opening this screen.
                'has_public_logo' => TenantPublicAsset::pathFor($tenant, $profile, TenantPublicAsset::LOGO) !== null,
                'published_at' => $profile?->published_at?->toIso8601String(),
            ],
        ];
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
