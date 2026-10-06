<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePlanRequest;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Super-admin management of the plan catalog.
 *
 * `admin.manage` is held only by SUPER_ADMIN (User::hasPermission short-circuits
 * for that role and no other role is granted it in PermissionSeeder), and the
 * route group adds EnsurePlatformContext, RequirePlatformMfa and the
 * platform-admin throttle on top.
 *
 * WHY THIS IS SAFE TO SHIP NOW, AND WAS NOT BEFORE. Until this branch,
 * `BillingService::generateMonthlyInvoice()` read the price live from the plan,
 * so editing one here would have re-billed every existing subscriber at the
 * next monthly run — with no notice and no grandfathering — and
 * HandleOverdueInvoicesJob escalates the resulting non-payment to tenant
 * suspension at sixty days. `subscriptions.price_cents` now freezes what each
 * subscriber agreed to, so a price edit changes what NEW customers are quoted
 * and what the pricing page advertises, and changes nothing for anyone already
 * signed up. The subscriber count in the audit payload below is what makes that
 * visible at the moment of the edit rather than a fact to be rediscovered.
 */
class AdminPlanController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('admin.manage');

        // Every plan, not just the public ones — this is the screen where an
        // operator un-hides a plan, so filtering by `is_public` here would hide
        // the control from the only person allowed to use it.
        $plans = Plan::orderBy('sort_order')->get();

        return response()->json(['data' => $plans]);
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        Gate::authorize('admin.manage');

        $plan = Plan::create($request->validated());

        AuditLog::record('platform.plan.created', $plan, $request->validated());

        return response()->json(['data' => $plan], 201);
    }

    public function update(StorePlanRequest $request, string $publicId): JsonResponse
    {
        Gate::authorize('admin.manage');

        $plan = Plan::where('public_id', $publicId)->firstOrFail();

        $changes = $request->validated();

        // What the operator is about to affect, recorded at the moment they do
        // it. A price edit does not re-price these subscribers — that is the
        // whole point of subscriptions.price_cents — but "how many people are
        // on this plan" is the question anyone reviewing the audit trail will
        // ask, and it cannot be reconstructed afterwards.
        // withoutGlobalScopes is required and safe here: this is a
        // platform-admin surface running with NO tenant resolved
        // (EnsurePlatformContext), so BelongsToTenant's fail-closed scope would
        // return 0 for every plan. The query is not tenant-scoped by intent —
        // "how many tenants are on this plan" is a cross-tenant count, and it
        // states its own predicate (plan_id) rather than relying on one.
        $subscriberCount = Subscription::withoutGlobalScopes()
            ->where('plan_id', $plan->id)
            ->count();

        // Captured before the write. Eloquent re-syncs $original after save, so
        // reading it afterwards returns the new values and the audit entry
        // records the change as a no-op — the one thing it exists not to do.
        $before = array_intersect_key($plan->getOriginal(), $changes);

        $plan->update($changes);

        AuditLog::record('platform.plan.updated', $plan, [
            'changed' => array_keys($changes),
            'before' => $before,
            'after' => $changes,
            'subscribers_on_plan' => $subscriberCount,
        ]);

        return response()->json(['data' => $plan->fresh()]);
    }

    /**
     * Withdraw a plan from sale.
     */
    public function destroy(string $publicId): JsonResponse
    {
        // Deliberately not a delete, and the reasoning lives in a `//` comment
        // rather than the docblock above because Scramble publishes a routed
        // method's docblock as the public API description — this is an
        // implementation note, not documentation for a caller.
        //
        // `subscriptions.plan_id` is a foreign key with no cascade, so
        // destroying a plan someone is on either violates the constraint or
        // orphans their subscription, and the billing dashboard reads
        // `$subscription->plan->name`. Deactivating keeps every existing
        // subscriber billed at the price they agreed to while removing the plan
        // from the pricing page and from changePlan, which is what retiring a
        // plan actually means.
        Gate::authorize('admin.manage');

        $plan = Plan::where('public_id', $publicId)->firstOrFail();

        $subscriberCount = Subscription::withoutGlobalScopes()
            ->where('plan_id', $plan->id)
            ->count();

        $plan->update(['is_active' => false, 'is_public' => false]);

        AuditLog::record('platform.plan.retired', $plan, [
            'subscribers_on_plan' => $subscriberCount,
        ]);

        return response()->json(['data' => $plan->fresh()]);
    }
}
