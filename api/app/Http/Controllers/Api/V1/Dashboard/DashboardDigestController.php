<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ScheduleDashboardDigestRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DashboardDigest;
use App\Services\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
            'next_run_at' => $this->calculateNextRun($request->validated('frequency')),
        ]);

        AuditLog::record('dashboard_digest.scheduled', $digest);

        return response()->json($this->payload($digest), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeDashboardAccess($request);

        $digests = DashboardDigest::query()
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
            'recipients' => $digest->recipients,
            'next_run_at' => $digest->next_run_at,
            'last_run_at' => $digest->last_run_at,
        ];
    }

    /** Read-only endpoints just need either dashboard permission; scope is decided per-write. */
    private function authorizeDashboardAccess(Request $request): void
    {
        $user = $request->user();
        $allowed = ($user?->hasPermission('dashboard.executive') ?? false)
            || ($user?->hasPermission('dashboard.regional') ?? false);

        if (! $allowed) {
            throw new HttpException(403, 'This action is unauthorized.');
        }
    }

    /**
     * Same rule ExecutiveDashboardController::resolveBranchId() enforces for
     * every read endpoint: a regional holder's own branch always wins over
     * whatever `branch_public_id` they submit.
     */
    private function resolveBranchId(ScheduleDashboardDigestRequest $request): ?int
    {
        $user = $request->user();
        $hasFull = $user?->hasPermission('dashboard.executive') ?? false;
        $hasRegional = $user?->hasPermission('dashboard.regional') ?? false;

        if (! $hasFull && ! $hasRegional) {
            throw new HttpException(403, 'This action is unauthorized.');
        }

        if ($hasFull) {
            $branchPublicId = $request->validated('branch_public_id');

            if ($branchPublicId === null) {
                return null;
            }

            $branch = Branch::where('public_id', $branchPublicId)->first();

            if (! $branch) {
                throw new HttpException(404, 'Branch not found.');
            }

            return $branch->id;
        }

        $branchId = $user->employee?->branch_id;

        if ($branchId === null) {
            throw new HttpException(403, 'No branch is assigned to your account.');
        }

        return $branchId;
    }

    private function calculateNextRun(string $frequency): Carbon
    {
        return match ($frequency) {
            'daily' => now()->addDay()->startOfDay()->addHours(6),
            'weekly' => now()->next('Monday')->startOfDay()->addHours(6),
            default => now()->addMonth()->startOfMonth()->addHours(6), // monthly
        };
    }
}
