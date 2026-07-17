<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Analytics\ExecutiveDashboardService;
use App\Services\CurrentTenant;
use App\Services\Dashboard\DashboardCacheVersion;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        $data = Cache::remember(
            $this->cacheKey($tenantId, 'overview', $from, $to),
            300,
            fn () => $this->service->overview($tenantId, $from, $to),
        );

        return response()->json($data);
    }

    public function attendance(Request $request): JsonResponse
    {
        Gate::authorize('dashboard.executive');

        [$from, $to] = $this->dateRange($request);
        $tenantId = app(CurrentTenant::class)->get()->id;

        $data = Cache::remember(
            $this->cacheKey($tenantId, 'attendance', $from, $to),
            300,
            fn () => $this->service->attendanceDeepDive($tenantId, $from, $to),
        );

        return response()->json($data);
    }

    public function payroll(Request $request): JsonResponse
    {
        Gate::authorize('dashboard.executive');

        [$from, $to] = $this->dateRange($request);
        $tenantId = app(CurrentTenant::class)->get()->id;

        $data = Cache::remember(
            $this->cacheKey($tenantId, 'payroll', $from, $to),
            300,
            fn () => $this->service->payrollDeepDive($tenantId, $from, $to),
        );

        return response()->json($data);
    }

    public function workforce(Request $request): JsonResponse
    {
        Gate::authorize('dashboard.executive');

        $tenantId = app(CurrentTenant::class)->get()->id;

        $data = Cache::remember(
            $this->cacheKey($tenantId, 'workforce'),
            300,
            fn () => $this->service->workforceDeepDive($tenantId),
        );

        return response()->json($data);
    }

    private function cacheKey(int $tenantId, string $section, ?Carbon $from = null, ?Carbon $to = null): string
    {
        $version = DashboardCacheVersion::current($tenantId);
        $range = $from && $to ? ':'.$from->toDateString().':'.$to->toDateString() : '';

        return "dashboard:executive:{$section}:{$tenantId}:v{$version}{$range}";
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
