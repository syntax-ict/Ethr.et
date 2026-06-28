<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Analytics\ExecutiveDashboardService;
use App\Services\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ExecutiveDashboardController extends Controller
{
    public function __construct(
        private readonly ExecutiveDashboardService $service,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        Gate::authorize('dashboard.executive');

        [$from, $to] = $this->dateRange($request);
        $tenantId = app(CurrentTenant::class)->get()->id;

        return response()->json($this->service->overview($tenantId, $from, $to));
    }

    public function attendance(Request $request): JsonResponse
    {
        Gate::authorize('dashboard.executive');

        [$from, $to] = $this->dateRange($request);
        $tenantId = app(CurrentTenant::class)->get()->id;

        return response()->json($this->service->attendanceDeepDive($tenantId, $from, $to));
    }

    public function payroll(Request $request): JsonResponse
    {
        Gate::authorize('dashboard.executive');

        [$from, $to] = $this->dateRange($request);
        $tenantId = app(CurrentTenant::class)->get()->id;

        return response()->json($this->service->payrollDeepDive($tenantId, $from, $to));
    }

    public function workforce(Request $request): JsonResponse
    {
        Gate::authorize('dashboard.executive');

        $tenantId = app(CurrentTenant::class)->get()->id;

        return response()->json($this->service->workforceDeepDive($tenantId));
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
