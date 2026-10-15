<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\InstallMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-service registration exists for the SaaS deployment. A single-tenant
 * install (ETHR_MODE=single) serves exactly one organisation, so the register
 * endpoints close once that organisation exists.
 *
 * They stay open while the install has no organisation at all — the setup
 * window: a fresh on-prem install registers its organisation through the
 * normal flow, and the window closes by itself with the first row. There is
 * no race worth guarding here beyond what registration already enforces:
 * two concurrent first registrations contend on the unique subdomain index,
 * and a second organisation slipping in merely fails to resolve (the
 * single-install fallback refuses to guess between two).
 *
 * Multi-tenant installs are untouched. Tenant is on the Global Model List —
 * no tenant scope — so the existence check bypasses nothing.
 */
class EnsureRegistrationOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        if (InstallMode::single() && Tenant::query()->exists()) {
            return response()->json([
                'type' => 'https://ethr.et/errors/registration-closed',
                'title' => 'Registration Closed',
                'status' => 403,
                'detail' => 'This installation serves a single organisation and does not accept self-service registration.',
            ], 403)->withHeaders(['Content-Type' => 'application/problem+json']);
        }

        return $next($request);
    }
}
