<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UploadTenantLogoRequest;
use App\Models\AuditLog;
use App\Services\CurrentTenant;
use App\Services\FileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Settings → Branding: the organisation's own uploaded logo.
 *
 * An uploaded logo is shown in preference to a linked one; removing it falls
 * back to the link, if any. The public copy is served by
 * OrganisationLogoController at a URL that changes with every upload.
 */
class TenantLogoController extends Controller
{
    public function __construct(
        private readonly FileStorageService $storage,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function store(UploadTenantLogoRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = $this->currentTenant->get();
        $previous = $tenant->logo_storage_path;

        $stored = $this->storage->uploadLogo($request->file('logo'));
        $tenant->update([
            'logo_storage_path' => $stored['path'],
            'logo_version' => $stored['version'],
        ]);

        // Best effort: an orphaned old logo is storage waste, not a failed upload.
        if (is_string($previous) && $previous !== $stored['path']) {
            $this->storage->delete($previous);
        }

        AuditLog::record('settings.logo_updated', $tenant, ['version' => $stored['version']]);

        return response()->json([
            'uploaded_logo_url' => $tenant->uploadedLogoUrl(),
            'logo_url' => $tenant->logoUrl(),
        ], 201);
    }

    public function destroy(): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = $this->currentTenant->get();
        $path = $tenant->logo_storage_path;

        if (is_string($path)) {
            $this->storage->delete($path);
            $tenant->update(['logo_storage_path' => null, 'logo_version' => null]);
            AuditLog::record('settings.logo_removed', $tenant);
        }

        return response()->json([
            'uploaded_logo_url' => null,
            'logo_url' => $tenant->logoUrl(),
        ]);
    }
}
