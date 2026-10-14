<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateCoverFocalRequest;
use App\Http\Requests\Settings\UploadBrandAssetRequest;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\FileStorageService;
use App\Support\TenantBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Settings → Branding: the organisation's other brand images, beside the
 * logo TenantLogoController keeps. The kind is in the route: a logo for
 * dark backgrounds, a square mark, a cover photo with its focal point.
 *
 * Every upload replaces the one before it and is served publicly by
 * OrganisationAssetController at a URL that changes with every upload.
 */
class TenantBrandAssetController extends Controller
{
    public function __construct(
        private readonly FileStorageService $storage,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function store(UploadBrandAssetRequest $request, string $kind): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = $this->currentTenant->get();
        $previous = $tenant->brandAsset($kind);

        $stored = $this->storage->uploadBrandImage($request->file('image'), $kind);
        $entry = [
            'path' => $stored['path'],
            'version' => $stored['version'],
            'width' => $stored['width'],
            'height' => $stored['height'],
        ];
        if ($kind === 'cover') {
            $entry['variants'] = $stored['variants'];
            $entry['focal'] = $previous['focal'] ?? ['x' => 0.5, 'y' => 0.5];
        }

        $tenant->update(['brand_assets' => [...$this->stored($tenant), $kind => $entry]]);

        // Best effort: an orphaned old image is storage waste, not a failed upload.
        if ($previous !== null && $previous['path'] !== $stored['path']) {
            $this->deleteFiles($previous);
        }

        AuditLog::record('settings.brand_asset_updated', $tenant, ['kind' => $kind, 'version' => $stored['version']]);

        return response()->json(['assets' => TenantBrand::assets($tenant)], 201);
    }

    public function destroy(string $kind): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = $this->currentTenant->get();
        $asset = $tenant->brandAsset($kind);

        if ($asset !== null) {
            $this->deleteFiles($asset);
            $assets = $this->stored($tenant);
            unset($assets[$kind]);
            $tenant->update(['brand_assets' => $assets === [] ? null : $assets]);
            AuditLog::record('settings.brand_asset_removed', $tenant, ['kind' => $kind]);
        }

        return response()->json(['assets' => TenantBrand::assets($tenant)]);
    }

    public function focal(UpdateCoverFocalRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = $this->currentTenant->get();
        $cover = $tenant->brandAsset('cover');
        abort_if($cover === null, 404);

        $cover['focal'] = [
            'x' => round((float) $request->validated('x'), 3),
            'y' => round((float) $request->validated('y'), 3),
        ];
        $tenant->update(['brand_assets' => [...$this->stored($tenant), 'cover' => $cover]]);

        AuditLog::record('settings.brand_asset_updated', $tenant, ['kind' => 'cover', 'focal' => $cover['focal']]);

        return response()->json(['assets' => TenantBrand::assets($tenant)]);
    }

    /** @return array<string, mixed> */
    private function stored(Tenant $tenant): array
    {
        $raw = $tenant->getAttribute('brand_assets');

        return is_array($raw) ? $raw : [];
    }

    /** @param  array{path: string, variants?: array<int, string>}  $asset */
    private function deleteFiles(array $asset): void
    {
        $this->storage->delete($asset['path']);
        foreach ($asset['variants'] ?? [] as $path) {
            $this->storage->delete($path);
        }
    }
}
