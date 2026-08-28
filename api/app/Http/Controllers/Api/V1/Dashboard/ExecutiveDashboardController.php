<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Analytics\ExecutiveDashboardService;
use App\Services\CurrentTenant;
use App\Services\Dashboard\DashboardCacheVersion;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Persona-scoped executive dashboards.
 *
 * Two permissions gate this controller, and which one a caller holds decides
 * their scope — not their role name, so a custom role configured with either
 * ability works the same way a built-in role does:
 *
 *  - `dashboard.executive` — unrestricted. The CEO / HR Director / Finance
 *    Director personas: tenant-wide by default, with an optional `?branch=`
 *    filter to drill into any single branch.
 *  - `dashboard.regional` — the Regional Manager / Operations persona.
 *    Always forced to the caller's own `employees.branch_id`; any `?branch=`
 *    they send is ignored rather than honoured, since letting a regional
 *    holder request someone else's branch would defeat the scoping.
 */
class ExecutiveDashboardController extends Controller
{
    public function __construct(
        private readonly ExecutiveDashboardService $service,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);
        $tenantId = app(CurrentTenant::class)->get()->id;
        $branchId = $this->resolveBranchId($request);

        $data = Cache::remember(
            $this->cacheKey($tenantId, 'overview', $branchId, $from, $to),
            300,
            fn () => $this->service->overview($tenantId, $from, $to, $branchId),
        );

        return response()->json($data);
    }

    public function attendance(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);
        $tenantId = app(CurrentTenant::class)->get()->id;
        $branchId = $this->resolveBranchId($request);

        $data = Cache::remember(
            $this->cacheKey($tenantId, 'attendance', $branchId, $from, $to),
            300,
            fn () => $this->service->attendanceDeepDive($tenantId, $from, $to, $branchId),
        );

        return response()->json($data);
    }

    public function payroll(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);
        $tenantId = app(CurrentTenant::class)->get()->id;
        $branchId = $this->resolveBranchId($request);

        $data = Cache::remember(
            $this->cacheKey($tenantId, 'payroll', $branchId, $from, $to),
            300,
            fn () => $this->service->payrollDeepDive($tenantId, $from, $to, $branchId),
        );

        return response()->json($data);
    }

    public function workforce(Request $request): JsonResponse
    {
        $tenantId = app(CurrentTenant::class)->get()->id;
        $branchId = $this->resolveBranchId($request);

        $data = Cache::remember(
            $this->cacheKey($tenantId, 'workforce', $branchId),
            300,
            fn () => $this->service->workforceDeepDive($tenantId, $branchId),
        );

        return response()->json($data);
    }

    public function compliance(Request $request): JsonResponse
    {
        $tenantId = app(CurrentTenant::class)->get()->id;
        $branchId = $this->resolveBranchId($request);

        $data = Cache::remember(
            $this->cacheKey($tenantId, 'compliance', $branchId),
            300,
            fn () => $this->service->complianceSnapshot($tenantId, $branchId),
        );

        return response()->json($data);
    }

    public function forecast(Request $request): JsonResponse
    {
        $tenantId = app(CurrentTenant::class)->get()->id;
        $branchId = $this->resolveBranchId($request);

        $data = Cache::remember(
            $this->cacheKey($tenantId, 'forecast', $branchId),
            300,
            fn () => $this->service->forecast($tenantId, $branchId),
        );

        return response()->json($data);
    }

    /**
     * Resolves the branch scope for the current caller, or aborts 403 if they
     * hold neither dashboard permission. Null means "no restriction".
     */
    private function resolveBranchId(Request $request): ?int
    {
        $user = $request->user();
        $hasFull = $user?->hasPermission('dashboard.executive') ?? false;
        $hasRegional = $user?->hasPermission('dashboard.regional') ?? false;

        if (! $hasFull && ! $hasRegional) {
            throw new HttpException(403, 'This action is unauthorized.');
        }

        if ($hasFull) {
            if (! $request->filled('branch')) {
                return null;
            }

            $branch = Branch::where('public_id', $request->input('branch'))->first();

            if (! $branch) {
                throw new HttpException(404, 'Branch not found.');
            }

            return $branch->id;
        }

        // Regional: forced to the caller's own branch, never the caller's choice.
        $branchId = $user->employee?->branch_id;

        if ($branchId === null) {
            throw new HttpException(403, 'No branch is assigned to your account.');
        }

        return $branchId;
    }

    private function cacheKey(int $tenantId, string $section, ?int $branchId, ?Carbon $from = null, ?Carbon $to = null): string
    {
        $version = DashboardCacheVersion::current($tenantId);
        $range = $from && $to ? ':'.$from->toDateString().':'.$to->toDateString() : '';
        $branch = $branchId !== null ? ":b{$branchId}" : '';

        return "dashboard:executive:{$section}:{$tenantId}{$branch}:v{$version}{$range}";
    }

    /** @return array{Carbon, Carbon} */
    private function dateRange(Request $request): array
    {
        $from = $request->has('from')
            ? Carbon::parse($request->input('from'))
            : Carbon::now()->startOfMonth();

        $to = $request->has('to')
            ? Carbon::parse($request->input('to'))
            : Carbon::now()->endOfMonth();

        return [$from, $to];
    }
}
