<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * Pre-authentication tenant display info for the login page.
 *
 * Adds no tenant-resolution logic of its own: ResolveTenant (bootstrap/app.php)
 * already runs on every API request and resolves the tenant from the subdomain
 * before this controller executes. An unresolved or nonexistent subdomain never
 * reaches here — ResolveTenant answers "tenant-not-found" / "tenant-inactive"
 * itself. This endpoint only exposes the safe, non-sensitive subset of whatever
 * CurrentTenant already holds, so the login heading can say "Sign in to
 * {Organization}" instead of a static "Sign in to ETHR" on every host.
 */
class TenantContextController extends Controller
{
    public function __invoke(CurrentTenant $currentTenant): JsonResponse
    {
        $tenant = $currentTenant->get();

        return response()->json([
            'tenant' => $tenant ? [
                'name' => $tenant->name,
                'subdomain' => $tenant->subdomain,
                'logo_path' => $tenant->logo_path,
            ] : null,
        ]);
    }
}
