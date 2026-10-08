<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Services\Analytics\BranchAnalyticsService;
use App\Services\Analytics\DepartmentAnalyticsService;
use App\Services\CurrentTenant;
use App\Support\ExecutiveBranchScope;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AnalyticsController extends Controller
{
    public function departments(Request $request, DepartmentAnalyticsService $service): JsonResponse
    {
        Gate::authorize('dashboard.executive');

        [$from, $to] = $this->dateRange($request);
        $tenantId = app(CurrentTenant::class)->get()->id;

        $sort = $request->input('sort', 'headcount');
        $data = $service->compare($tenantId, $from, $to);

        usort($data, fn ($a, $b) => ($b[$sort] ?? 0) <=> ($a[$sort] ?? 0));

        return response()->json(['departments' => $data]);
    }

    public function departmentDetail(Request $request, Department $department, DepartmentAnalyticsService $service): JsonResponse
    {
        // The drill-down on the executive dashboard, so the dashboard's scope:
        // a regional holder sees their own branch's part of the department,
        // an executive the branch they filtered to. It was executive-only and
        // branch-blind, so a regional user's click was a 403 and a filtered
        // executive saw every branch (audit N85).
        $branchId = ExecutiveBranchScope::resolve($request);

        [$from, $to] = $this->dateRange($request);
        $tenantId = app(CurrentTenant::class)->get()->id;

        return response()->json($service->detail($tenantId, $department, $from, $to, $branchId));
    }

    public function branches(Request $request, BranchAnalyticsService $service): JsonResponse
    {
        Gate::authorize('dashboard.executive');

        [$from, $to] = $this->dateRange($request);
        $tenantId = app(CurrentTenant::class)->get()->id;

        return response()->json(['branches' => $service->compare($tenantId, $from, $to)]);
    }

    public function branchDetail(Request $request, Branch $branch, BranchAnalyticsService $service): JsonResponse
    {
        Gate::authorize('dashboard.executive');

        $tenantId = app(CurrentTenant::class)->get()->id;

        return response()->json($service->detail($tenantId, $branch));
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
