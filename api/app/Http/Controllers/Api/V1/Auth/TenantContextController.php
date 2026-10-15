<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\SsoSetting;
use App\Services\Auth\OtpService;
use App\Services\CurrentTenant;
use App\Support\TenantBrand;
use App\Support\TenantLanding;
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
    public function __invoke(CurrentTenant $currentTenant, OtpService $otp): JsonResponse
    {
        $tenant = $currentTenant->get();

        // A browser reloading the sign-in page reuses this for a minute
        // instead of spending the rate limit. `private`, not `public`: on a
        // stateful request Laravel may attach Set-Cookie (XSRF, session), and a
        // shared cache must never store and replay that.
        $cacheHeaders = ['Cache-Control' => 'private, max-age=60', 'Vary' => 'Host, X-Tenant'];

        return response()->json([
            'tenant' => $tenant ? [
                'name' => $tenant->name,
                'subdomain' => $tenant->subdomain,
                'logo_path' => $tenant->logoUrl(),
                'brand' => TenantBrand::present($tenant),
                'landing' => TenantLanding::present($tenant),
                'sign_in' => [
                    'sso' => SsoSetting::query()->where('is_enabled', true)->exists(),
                    'otp' => $otp->isAvailable(),
                    'email_code' => $otp->isAvailable(OtpService::CHANNEL_EMAIL),
                ],
                'default_locale' => $tenant->default_locale,
            ] : null,
        ])->withHeaders($cacheHeaders);
    }
}
