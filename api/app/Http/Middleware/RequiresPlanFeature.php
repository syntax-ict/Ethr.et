<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\PlanFeature;
use App\Services\CurrentTenant;
use App\Services\PlanFeatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request whose tenant's plan does not include the named feature.
 *
 *     Route::post(...)->middleware(RequiresPlanFeature::class.':payroll');
 *
 * Referenced by class name rather than through a short alias: `bootstrap/app.php`
 * registers no alias for it, and adding one would put the routing contract in a
 * second file from the routes that depend on it. The FQCN form needs no
 * registration and a rename cannot silently unhook the gate.
 *
 * The argument is resolved through {@see PlanFeature}, so a typo is a fatal
 * `ValueError` when the route is hit rather than a check that quietly passes —
 * which is the failure mode that matters for a gate nobody looks at until a
 * customer disputes a bill.
 *
 * Applied to write paths only. See {@see PlanFeatureService} for why reads stay
 * open and why trial/plan-less tenants are never gated.
 */
class RequiresPlanFeature
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly PlanFeatureService $features,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        // No tenant resolved means no plan to check against — the platform host
        // and the public endpoints. Authorisation for those is EnsurePlatformContext
        // and the route's own policy, not this.
        if (! $this->currentTenant->resolved()) {
            return $next($request);
        }

        $this->features->assertAllows(
            $this->currentTenant->get(),
            PlanFeature::from($feature),
        );

        return $next($request);
    }
}
