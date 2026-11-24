<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Report;

use App\Exceptions\TenantRequired;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\GenerateReportRequest;
use App\Http\Requests\Report\SaveReportRequest;
use App\Http\Requests\Report\ScheduleReportRequest;
use App\Http\Requests\Report\TimesheetRequest;
use App\Jobs\RunScheduledReportsJob;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\SavedReport;
use App\Models\ScheduledReport;
use App\Models\Shift;
use App\Services\CurrentTenant;
use App\Services\Report\ReportEngine;
use App\Services\Report\ReportPresets;
use App\Services\Report\TimesheetReport;
use App\Support\Csv;
use App\Support\Letterhead;
use App\Support\PdfPages;
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

    /**
     * The monthly timesheet as a grid: a page of the people the viewer may
     * see, each with one cell a day and the figures payroll reads, the totals
     * over everyone matched, department subtotals, the overtime rate columns,
     * the rules applied and the period's status.
     *
     * @scramble-return array{period: array{from: string, to: string}|null, days: list<array{date: string, iso_weekday: int}>, data: list<array{public_id: string, employee_code: string|null, name: string, name_am: string|null, badge_number: string|null, position: string|null, department: array{public_id: string, name: string, name_am: string|null}|null, shift: array{public_id: string, name: string, name_am: string|null}|null, cells: list<array{date: string, code: string|null, short: string|null, kind: string, worked: bool, late: bool, early: bool, worked_minutes: int, scheduled_minutes: int, late_minutes: int, early_minutes: int, overtime: array<string, int>, records: list<string>, locked: bool, exception: array{type: string, state: string}|null}>, totals: array<string, mixed>, check_ok: bool}>, meta: array{current_page: int, last_page: int, per_page: int, total: int}, links: array{next: string|null, prev: string|null}, complete: bool, max_people: int, totals: array<string, mixed>|null, department_subtotals: list<array{department: array{public_id: string, name: string, name_am: string|null}|null, employees: int, totals: array<string, mixed>}>, rate_columns: list<array{rate: float, types: list<string>}>, rules_applied: array<string, mixed>, status: array{code: string, blocking: int, locked_days: int, employed_days: int}|null, with_pay: bool}
     */
    public function timesheet(TimesheetRequest $request, TimesheetReport $timesheet): JsonResponse
    {
        Gate::authorize('report.generate');

        $tenant = app(CurrentTenant::class)->get() ?? throw new TenantRequired;
        $page = (int) $request->validated('page', 1);
        $perPage = (int) $request->validated('per_page', 25);

        $grid = $timesheet->grid($tenant, $request->user(), $request->validated(), $page, $perPage, Gate::allows('payroll.viewAll'));

        $total = (int) $grid['total'];
        $lastPage = max(1, (int) ceil($total / $perPage));

        return response()->json([
            'period' => $grid['period'],
            'days' => $grid['days'],
            'data' => array_map(fn (array $row): array => $this->timesheetRow($row), $grid['data']),
            'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total],
            'links' => [
                'next' => $page < $lastPage ? $request->fullUrlWithQuery(['page' => $page + 1]) : null,
                'prev' => $page > 1 ? $request->fullUrlWithQuery(['page' => $page - 1]) : null,
            ],
            'complete' => $grid['complete'] ?? true,
            'max_people' => $grid['max_people'] ?? TimesheetReport::MAX_PEOPLE,
            'totals' => $grid['totals'] ?? null,
            'department_subtotals' => array_map(fn (array $group): array => [
                'department' => $this->unit($group['department']),
                'employees' => $group['employees'],
                'totals' => $group['totals'],
            ], $grid['department_subtotals'] ?? []),
            'rate_columns' => $grid['rate_columns'] ?? [],
            'rules_applied' => $grid['rules_applied'] ?? [],
            'status' => $grid['status'] ?? null,
            'with_pay' => $grid['with_pay'] ?? false,
        ]);
    }

    public function export(GenerateReportRequest $request): Response
    {
        Gate::authorize('report.generate');
        $this->authorizeSource($request);

        $tenantId = app(CurrentTenant::class)->requireId();
        $format = $request->validated('format', 'csv');
        $reader = ReaderDates::for($request->user());
        $result = $this->engine->generate($tenantId, $request->validated(), $request->user(), $reader, withSummary: false);
        $filename = 'report-'.now()->format('Y-m-d-His');

        $note = $this->engine->truncationNote($result, $reader);

        return $format === 'pdf'
            ? $this->exportPdf($result, $filename, $reader, $note)
            : $this->exportCsv($result['data'], $filename, $reader, $note);
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
    private function exportCsv(array $rows, string $filename, ReaderDates $reader, ?string $note): Response
    {
        return response(Csv::forSpreadsheet($this->engine->toCsv($rows, $reader, $note)), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}.csv\"",
        ]);
    }

    /** @param array<string, mixed> $result */
    private function exportPdf(array $result, string $filename, ReaderDates $reader, ?string $note): Response
    {
        $rows = $this->engine->presentable($result['data'], $reader);
        $columns = $rows === [] ? [] : array_keys($rows[0]);
        File::ensureDirectoryExists(storage_path('fonts'));
        $pdf = Pdf::loadView('reports.export', [
            'letterhead' => Letterhead::for(app(CurrentTenant::class)->get() ?? throw new TenantRequired, $reader->locale),
            'columns' => $columns,
            'rows' => $rows,
            'locale' => $reader->locale,
            'generated_at' => $reader->dateTime(now()),
            'source_name' => (string) __('report.sources.'.$result['source'], [], $reader->locale),
            'period_text' => $this->engine->periodText($result, $reader),
            'note' => $note,
        ]);
        $pdf->setOption('isFontSubsettingEnabled', true);
        $pdf->setPaper('a4', 'landscape');

        return response(PdfPages::render($pdf), 200, [
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

        // `mine`: only the person who saved a report may schedule it, so the
        // list says which ones the caller saved and the screen offers
        // scheduling on those alone.
        $userId = (int) $request->user()->id;
        $reports = SavedReport::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => [
                'public_id' => $r->public_id,
                'name' => $r->name,
                'config' => $r->config,
                'created_at' => $r->created_at,
                'mine' => (int) $r->created_by === $userId,
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

        // A schedule runs with the reach of the person who saved the report
        // (RunScheduledReportsJob), so only that person may schedule it:
        // anyone else would be sending a colleague's wider view to addresses
        // of their own choosing.
        abort_unless((int) $report->created_by === (int) $request->user()->id, 403);

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
                'last_error' => $this->errorCode($s->last_error),
            ]);

        return response()->json(['schedules' => $schedules]);
    }

    /**
     * What the schedule list says about the last run: a short code, never the
     * stored text, which can carry SQL and values and is English on every
     * screen.
     */
    private function errorCode(?string $stored): ?string
    {
        return match (true) {
            $stored === null || $stored === '' => null,
            $stored === RunScheduledReportsJob::CREATOR_CANNOT_RUN => 'creator_cannot_run',
            default => 'error',
        };
    }

    public function deleteScheduled(ScheduledReport $scheduledReport): JsonResponse
    {
        Gate::authorize('report.generate');

        $scheduledReport->update(['is_active' => false]);
        AuditLog::record('report.unscheduled', $scheduledReport);

        return response()->json(null, 204);
    }

    /**
     * One person's row for the response: public ids only, and the ledger's
     * cells turned into plain arrays.
     *
     * @param  array{employee: Employee, shift: Shift|null, cells: array<string, array<string, mixed>>, totals: array<string, mixed>, check_ok: bool}  $row
     * @return array<string, mixed>
     */
    private function timesheetRow(array $row): array
    {
        $employee = $row['employee'];

        return [
            'public_id' => $employee->public_id,
            'employee_code' => $employee->employee_code,
            'name' => $employee->name,
            'name_am' => $employee->name_am,
            'badge_number' => $employee->badge_number,
            'position' => $employee->position === null ? null : $employee->position->title,
            'department' => $this->unit($employee->department),
            'shift' => $this->unit($row['shift']),
            'cells' => array_map(fn (array $cell, string $date): array => [
                'date' => $date,
                'code' => $cell['code']?->value,
                'short' => $cell['code']?->short(),
                'kind' => $cell['kind']->value,
                'worked' => $cell['worked'],
                'late' => $cell['late'],
                'early' => $cell['early'],
                'worked_minutes' => $cell['worked_minutes'],
                'scheduled_minutes' => $cell['scheduled_minutes'],
                'late_minutes' => $cell['late_minutes'],
                'early_minutes' => $cell['early_minutes'],
                'overtime' => $cell['overtime'],
                'records' => $cell['records'],
                'locked' => $cell['locked'],
                'exception' => $cell['exception'],
            ], $row['cells'], array_keys($row['cells'])),
            'totals' => $row['totals'],
            'check_ok' => $row['check_ok'],
        ];
    }

    /**
     * A unit (department, shift) as the response names it.
     *
     * @return array{public_id: string, name: string, name_am: string|null}|null
     */
    private function unit(Department|Shift|null $unit): ?array
    {
        return $unit === null ? null : [
            'public_id' => $unit->public_id,
            'name' => $unit->name,
            'name_am' => $unit->name_am,
        ];
    }
}
