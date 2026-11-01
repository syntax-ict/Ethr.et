<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Report;

use App\Exceptions\TenantRequired;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\GenerateReportRequest;
use App\Http\Requests\Report\SaveReportRequest;
use App\Http\Requests\Report\ScheduleReportRequest;
use App\Models\AuditLog;
use App\Models\SavedReport;
use App\Models\ScheduledReport;
use App\Services\CurrentTenant;
use App\Services\Report\ReportEngine;
use App\Services\Report\ReportPresets;
use App\Support\Csv;
use App\Support\ReaderDates;
use App\Support\ScheduleSlot;
use App\Support\StringList;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
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

    /**
     * Ready-made reports for this organisation: standard ones, and those
     * suited to its type and how its people work, recommended first.
     */
    public function presets(Request $request, ReportPresets $presets): JsonResponse
    {
        Gate::authorize('report.generate');

        $tenant = app(CurrentTenant::class)->get() ?? throw new TenantRequired;

        return response()->json($presets->for($tenant, $request->user()));
    }

    public function generate(GenerateReportRequest $request): JsonResponse
    {
        Gate::authorize('report.generate');
        $this->authorizeSource($request);

        $tenantId = app(CurrentTenant::class)->requireId();
        $result = $this->engine->generate($tenantId, $request->validated(), $request->user());

        return response()->json($result);
    }

    public function export(GenerateReportRequest $request): Response
    {
        Gate::authorize('report.generate');
        $this->authorizeSource($request);

        $tenantId = app(CurrentTenant::class)->requireId();
        $format = $request->validated('format', 'csv');
        $reader = ReaderDates::for($request->user());
        $result = $this->engine->generate($tenantId, $request->validated(), $request->user(), $reader);
        $filename = 'report-'.now()->format('Y-m-d-His');

        return $format === 'pdf'
            ? $this->exportPdf($result['data'], $filename, $reader)
            : $this->exportCsv($result['data'], $filename, $reader);
    }

    /**
     * The payroll source is every payslip figure, so it needs payroll.viewAll
     * as the payroll screens do. Refused here as well as filtered in the
     * engine so the caller is told, not handed an empty report.
     */
    private function authorizeSource(GenerateReportRequest $request): void
    {
        if ($request->validated('source') === 'payroll') {
            Gate::authorize('payroll.viewAll');
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function exportCsv(array $rows, string $filename, ReaderDates $reader): Response
    {
        return response(Csv::forSpreadsheet($this->engine->toCsv($rows, $reader)), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}.csv\"",
        ]);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function exportPdf(array $rows, string $filename, ReaderDates $reader): Response
    {
        $rows = $this->engine->presentable($rows, $reader);
        $columns = $rows === [] ? [] : array_keys($rows[0]);
        File::ensureDirectoryExists(storage_path('fonts'));
        $pdf = Pdf::loadView('reports.export', [
            'columns' => $columns,
            'rows' => $rows,
            'locale' => $reader->locale,
            'generated_at' => $reader->dateTime(now()),
        ]);
        $pdf->setOption('isFontSubsettingEnabled', true);
        $pdf->setPaper('a4', 'landscape');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}.pdf\"",
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

    public function schedule(ScheduleReportRequest $request): JsonResponse
    {
        Gate::authorize('report.generate');

        $tenant = app(CurrentTenant::class)->get();
        $report = SavedReport::where('public_id', $request->input('saved_report_public_id'))->firstOrFail();

        $scheduled = ScheduledReport::create([
            'tenant_id' => $tenant->id,
            'saved_report_id' => $report->id,
            'frequency' => $request->input('frequency'),
            'recipients' => $request->input('recipients'),
            'next_run_at' => ScheduleSlot::next($request->input('frequency'), $tenant),
        ]);

        AuditLog::record('report.scheduled', $scheduled);

        return response()->json([
            'public_id' => $scheduled->public_id,
            'frequency' => $scheduled->frequency,
            'recipients' => StringList::of($scheduled->recipients),
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
                'recipients' => StringList::of($s->recipients),
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
}
