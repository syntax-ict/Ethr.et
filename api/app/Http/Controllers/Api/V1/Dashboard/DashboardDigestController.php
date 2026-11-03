<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ScheduleDashboardDigestRequest;
use App\Models\AuditLog;
use App\Models\DashboardDigest;
use App\Services\CurrentTenant;
use App\Support\ExecutiveBranchScope;
use App\Support\ScheduleSlot;
use App\Support\StringList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 6.6 — recurring email summaries of the executive/regional dashboard
 * (headcount, attendance rate, payroll, turnover, compliance flags), delivered
 * by RunDashboardDigestsJob. Scoping mirrors ExecutiveDashboardController
 * exactly: `dashboard.executive` can target the whole tenant or any one
 * branch, `dashboard.regional` is always forced to the caller's own branch.
 */
class DashboardDigestController extends Controller
{
    public function store(ScheduleDashboardDigestRequest $request): JsonResponse
    {
        $tenant = app(CurrentTenant::class)->get();
        $branchId = $this->resolveBranchId($request);

        $digest = DashboardDigest::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchId,
            'created_by' => $request->user()?->id,
            'frequency' => $request->validated('frequency'),
            'recipients' => $request->validated('recipients'),
            'next_run_at' => ScheduleSlot::next($request->validated('frequency'), $tenant),
        ]);

        AuditLog::record('dashboard_digest.scheduled', $digest);

        return response()->json($this->payload($digest), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeDashboardAccess($request);

        $digests = $this->visibleTo($request, DashboardDigest::query())
            ->with('branch:id,public_id,name')
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (DashboardDigest $d) => $this->payload($d));

        return response()->json(['digests' => $digests]);
    }

    public function destroy(Request $request, DashboardDigest $dashboardDigest): JsonResponse
    {
        $this->authorizeDashboardAccess($request);

        // 404, not 403, for a digest outside the caller's branch: the same
        // answer as one that does not exist.
        abort_unless(
            $this->visibleTo($request, DashboardDigest::query())->whereKey($dashboardDigest->getKey())->exists(),
            404,
        );

        $dashboardDigest->update(['is_active' => false]);
        AuditLog::record('dashboard_digest.cancelled', $dashboardDigest);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function payload(DashboardDigest $digest): array
    {
        return [
            'public_id' => $digest->public_id,
            'branch_name' => $digest->branch?->name,
            'frequency' => $digest->frequency,
            'recipients' => StringList::of($digest->recipients),
            'next_run_at' => $digest->next_run_at,
            'last_run_at' => $digest->last_run_at,
        ];
    }

    /**
     * The digests a caller may see and cancel: all of them with tenant-wide
     * executive reach, otherwise only those on the caller's own branch.
     *
     * The same rule store() applies, and that ExecutiveDashboardController
     * applies to every read. index() and destroy() checked only that the
     * caller held either permission, so every supervisor (who holds
     * `dashboard.regional`) listed and could cancel every digest in the
     * tenant, executives' tenant-wide ones included (audit N59). A regional
     * caller with no branch sees none.
     *
     * @param  Builder<DashboardDigest>  $query
     * @return Builder<DashboardDigest>
     */
    private function visibleTo(Request $request, Builder $query): Builder
    {
        $user = $request->user();

        if (ExecutiveBranchScope::isFull($user)) {
            return $query;
        }

        $branchId = ExecutiveBranchScope::isRegional($user) ? $user->employee?->branch_id : null;

        return $branchId === null
            ? $query->whereRaw('0 = 1')
            : $query->where('branch_id', $branchId);
    }

    /** Read-only endpoints just need either dashboard permission; scope is decided per-write. */
    private function authorizeDashboardAccess(Request $request): void
    {
        $user = $request->user();

        abort_unless(ExecutiveBranchScope::isFull($user) || ExecutiveBranchScope::isRegional($user), 403);
    }

    /**
     * Same rule ExecutiveDashboardController::resolveBranchId() enforces for
     * every read endpoint: a regional holder's own branch always wins over
     * whatever `branch_public_id` they submit.
     */
    private function resolveBranchId(ScheduleDashboardDigestRequest $request): ?int
    {
        abort_unless(ExecutiveBranchScope::isFull($request->user()) || ExecutiveBranchScope::isRegional($request->user()), 403);

        return ExecutiveBranchScope::resolveFor($request->user(), $request->validated('branch_public_id'));
    }
}
