<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreAlertThresholdRequest;
use App\Models\AlertThreshold;
use App\Models\AuditLog;
use App\Services\Analytics\AlertEvaluator;
use App\Services\Analytics\ExecutiveDashboardService;
use App\Services\CurrentTenant;
use App\Support\ExecutiveBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 6.7 — configurable alert thresholds over the same metrics the
 * dashboard/digest already surface. Configuring thresholds (store/index/
 * destroy) is a tenant-wide policy decision, so it requires the unrestricted
 * `dashboard.executive` permission — a regional holder can *see* which
 * thresholds are currently breached for their own branch (`triggered`), the
 * same read-only relationship they have to every other dashboard figure, but
 * can't change what counts as an alert tenant-wide.
 */
class AlertThresholdController extends Controller
{
    public function __construct(
        private readonly ExecutiveDashboardService $dashboard,
        private readonly AlertEvaluator $evaluator,
    ) {}

    public function store(StoreAlertThresholdRequest $request): JsonResponse
    {
        $this->authorizeConfiguration($request);

        $tenant = app(CurrentTenant::class)->get();

        $threshold = AlertThreshold::create([
            'tenant_id' => $tenant->id,
            'created_by' => $request->user()?->id,
            'metric' => $request->validated('metric'),
            'operator' => $request->validated('operator'),
            'threshold_value' => $request->validated('threshold_value'),
            'severity' => $request->validated('severity'),
        ]);

        AuditLog::record('alert_threshold.created', $threshold);

        return response()->json($this->payload($threshold), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeConfiguration($request);

        $thresholds = AlertThreshold::query()
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AlertThreshold $t) => $this->payload($t));

        return response()->json(['thresholds' => $thresholds]);
    }

    public function destroy(Request $request, AlertThreshold $alertThreshold): JsonResponse
    {
        $this->authorizeConfiguration($request);

        $alertThreshold->update(['is_active' => false]);
        AuditLog::record('alert_threshold.cancelled', $alertThreshold);

        return response()->json(null, 204);
    }

    /**
     * Live evaluation against the caller's own scope — the same
     * dashboard.executive/regional branch rule ExecutiveDashboardController
     * enforces for every other read.
     */
    public function triggered(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request);
        $tenantId = app(CurrentTenant::class)->requireId();
        $to = now();
        $from = $to->copy()->subDays(30);

        $overview = $this->dashboard->overview($tenantId, $from, $to, $branchId);
        $compliance = $this->dashboard->complianceSnapshot($tenantId, $branchId);
        $thresholds = AlertThreshold::query()->where('is_active', true)->get();

        return response()->json([
            'alerts' => $this->evaluator->evaluate($overview, $compliance, $thresholds),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(AlertThreshold $threshold): array
    {
        return [
            'public_id' => $threshold->public_id,
            'metric' => $threshold->metric,
            'operator' => $threshold->operator,
            'threshold_value' => $threshold->threshold_value,
            'severity' => $threshold->severity,
        ];
    }

    private function authorizeConfiguration(Request $request): void
    {
        abort_unless(ExecutiveBranchScope::isFull($request->user()), 403);
    }

    /** Same rule ExecutiveDashboardController::resolveBranchId() enforces. */
    private function resolveBranchId(Request $request): ?int
    {
        abort_unless(ExecutiveBranchScope::isFull($request->user()) || ExecutiveBranchScope::isRegional($request->user()), 403);

        return ExecutiveBranchScope::resolve($request);
    }
}
