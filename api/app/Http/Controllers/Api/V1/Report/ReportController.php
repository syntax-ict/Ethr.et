<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\GenerateReportRequest;
use App\Http\Requests\Report\SaveReportRequest;
use App\Models\AuditLog;
use App\Models\SavedReport;
use App\Models\ScheduledReport;
use App\Services\CurrentTenant;
use App\Services\Report\ReportEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportEngine $engine,
    ) {}

    public function sources(): JsonResponse
    {
        Gate::authorize('report.generate');

        return response()->json(['sources' => $this->engine->sources()]);
    }

    public function generate(GenerateReportRequest $request): JsonResponse
    {
        Gate::authorize('report.generate');

        $tenantId = app(CurrentTenant::class)->get()->id;
        $result = $this->engine->generate($tenantId, $request->validated());

        return response()->json($result);
    }

    public function export(GenerateReportRequest $request): JsonResponse
    {
        Gate::authorize('report.generate');

        $tenantId = app(CurrentTenant::class)->get()->id;
        $format = $request->input('format', 'csv');
        $result = $this->engine->generate($tenantId, $request->validated());

        return response()->json([
            'format' => $format,
            'total' => $result['total'],
            'data' => $result['data'],
        ]);
    }

    public function save(SaveReportRequest $request): JsonResponse
    {
        Gate::authorize('report.generate');

        $tenant = app(CurrentTenant::class)->get();
        $user = $request->user();

        $report = SavedReport::create([
            'tenant_id' => $tenant->id,
            'name' => $request->validated('name'),
            'config' => $request->validated('config'),
            'created_by' => $user->id,
        ]);

        AuditLog::record('report.saved', $report);

        return response()->json([
            'public_id' => $report->public_id,
            'name' => $report->name,
            'config' => $report->config,
            'created_at' => $report->created_at,
        ], 201);
    }

    public function savedList(Request $request): JsonResponse
    {
        Gate::authorize('report.generate');

        $reports = SavedReport::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => [
                'public_id' => $r->public_id,
                'name' => $r->name,
                'config' => $r->config,
                'created_at' => $r->created_at,
            ]);

        return response()->json(['reports' => $reports]);
    }

    public function deleteSaved(SavedReport $savedReport): JsonResponse
    {
        Gate::authorize('report.generate');

        AuditLog::record('report.deleted', $savedReport);
        $savedReport->delete();

        return response()->json(null, 204);
    }

    public function schedule(Request $request): JsonResponse
    {
        Gate::authorize('report.generate');

        $request->validate([
            'saved_report_public_id' => ['required', 'string'],
            'frequency' => ['required', 'string', 'in:daily,weekly,monthly'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['email'],
        ]);

        $tenant = app(CurrentTenant::class)->get();
        $report = SavedReport::where('public_id', $request->input('saved_report_public_id'))->firstOrFail();

        $scheduled = ScheduledReport::create([
            'tenant_id' => $tenant->id,
            'saved_report_id' => $report->id,
            'frequency' => $request->input('frequency'),
            'recipients' => $request->input('recipients'),
            'next_run_at' => $this->calculateNextRun($request->input('frequency')),
        ]);

        AuditLog::record('report.scheduled', $scheduled);

        return response()->json([
            'public_id' => $scheduled->public_id,
            'frequency' => $scheduled->frequency,
            'recipients' => $scheduled->recipients,
            'next_run_at' => $scheduled->next_run_at,
        ], 201);
    }

    public function scheduledList(): JsonResponse
    {
        Gate::authorize('report.generate');

        $schedules = ScheduledReport::query()
            ->with('savedReport:id,name')
            ->where('is_active', true)
            ->get()
            ->map(fn ($s) => [
                'public_id' => $s->public_id,
                'report_name' => $s->savedReport?->name,
                'frequency' => $s->frequency,
                'recipients' => $s->recipients,
                'next_run_at' => $s->next_run_at,
                'last_run_at' => $s->last_run_at,
            ]);

        return response()->json(['schedules' => $schedules]);
    }

    public function deleteScheduled(ScheduledReport $scheduledReport): JsonResponse
    {
        Gate::authorize('report.generate');

        $scheduledReport->update(['is_active' => false]);

        return response()->json(null, 204);
    }

    private function calculateNextRun(string $frequency): \Carbon\Carbon
    {
        return match ($frequency) {
            'daily' => now()->addDay()->startOfDay()->addHours(6),
            'weekly' => now()->next('Monday')->startOfDay()->addHours(6),
            'monthly' => now()->addMonth()->startOfMonth()->addHours(6),
        };
    }
}
