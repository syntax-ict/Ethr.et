<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Feature;
use App\Enums\FeatureReason;
use App\Services\CurrentTenant;
use App\Services\Features\FeatureGate;
use App\Services\PlanFeatureService;
use App\Support\FeatureUnavailable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request when the named feature is off for the tenant: switched
 * off by the platform, revoked for this organisation, outside a rollout, or
 * not in its plan. {@see FeatureGate} decides which.
 *
 *     Route::post(...)->middleware(RequiresFeature::class.':payroll');
 *
 * Referenced by class name rather than through a short alias: `bootstrap/app.php`
 * registers no alias for it, and adding one would put the routing contract in a
 * second file from the routes that depend on it. The FQCN form needs no
 * registration and a rename cannot silently unhook the gate.
 *
 * The argument is resolved through {@see Feature}, so a typo is a fatal
 * `ValueError` when the route is hit rather than a check that quietly passes —
 * which is the failure mode that matters for a gate nobody looks at until a
 * customer disputes a bill.
 *
 * Applied to write paths only. See {@see PlanFeatureService} for why reads stay
 * open and why trial/plan-less tenants are never gated by plan.
 */
class RequiresFeature
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly FeatureGate $features,
        private readonly PlanFeatureService $plans,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        // No tenant resolved means no plan to check against — the platform host
        // and the public endpoints. Authorisation for those is EnsurePlatformContext
        // and the route's own policy, not this.
        if (! $this->currentTenant->resolved()) {
            return $next($request);
        }

        $tenant = $this->currentTenant->get();
        $resolved = Feature::from($feature);
        $decision = $this->features->decide($tenant, $resolved);

        if ($decision->on) {
            return $next($request);
        }

        // A plan refusal keeps the plan gate's own answer, which names the
        // upgrade; every other reason is the platform's, and says so.
        $plan = $resolved->planFeature();
        if ($decision->reason === FeatureReason::Plan && $plan !== null) {
            $this->plans->assertAllows($tenant, $plan);
        }

        return FeatureUnavailable::response($resolved, $decision->reason);
    }
}
