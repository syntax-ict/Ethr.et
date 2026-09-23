<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Events\PayrollProcessed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\ProcessPayrollRequest;
use App\Http\Requests\Payroll\ReprocessPayrollRunRequest;
use App\Http\Requests\Payroll\VoidPayrollRunRequest;
use App\Http\Resources\PayrollEntryResource;
use App\Http\Resources\PayrollRunResource;
use App\Jobs\ProcessPayrollJob;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Services\CurrentTenant;
use App\Services\Payroll\BankExportService;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\PayslipPdfService;
use App\Traits\DispatchesWebhooks;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PayrollController extends Controller
{
    use DispatchesWebhooks;

    /**
     * Reserve a payroll run and queue the computation.
     *
     * Returns 202 with the run, at status `processing`. Poll GET
     * /payroll/{public_id} until it reaches `completed` or `failed`.
     *
     * This used to compute everything inline. PayrollEngine chunks over every
     * active employee, and docs/CLAUDE.md:858 budgets "payroll calculation (500
     * employees) < 30s" on dedicated hardware — at or past a typical shared
     * host's max_execution_time before any contention. A 504 mid-run left the
     * row at `processing` with no way to tell what had been written. See
     * docs/audit/BASELINE.md §13a and master plan §20.
     *
     * Idempotency is unchanged and still enforced before anything is queued: a
     * replayed key returns the existing run with 200 and was_duplicate, per
     * convention 10.
     */
    public function process(ProcessPayrollRequest $request, PayrollEngine $engine): JsonResponse
    {
        $this->authorize('process', PayrollRun::class);

        $tenant = app(CurrentTenant::class)->get();
        $user = $request->user();

        $result = $engine->begin(
            $tenant->id,
            Carbon::parse($request->validated('period_start')),
            Carbon::parse($request->validated('period_end')),
            $user->id,
            $request->validated('idempotency_key'),
        );

        $run = $result->run;

        if (! $result->wasDuplicate) {
            // The audit entry, webhook and PayrollProcessed event moved into the
            // job: they announce a *completed* run, and at this point nothing
            // has been computed. Firing them here would have told every
            // subscriber payroll was done before a single entry existed.
            ProcessPayrollJob::dispatch($run->id, $run->tenant_id);
        }

        // Re-read: under QUEUE_CONNECTION=sync the job has already run to
        // completion by now, so the response should say `completed` rather than
        // the `processing` that was true a moment ago.
        $run->refresh()->load('entries.employee');

        $data = (new PayrollRunResource($run))->resolve();
        $data['was_duplicate'] = $result->wasDuplicate;

        return response()->json($data, $result->wasDuplicate ? 200 : 202);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PayrollRun::class);

        $query = PayrollRun::query()
            ->orderByDesc('period_start');

        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        return PayrollRunResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function show(PayrollRun $payrollRun): PayrollRunResource
    {
        $this->authorize('view', $payrollRun);

        $payrollRun->load('entries.employee', 'reprocessedFrom');

        return new PayrollRunResource($payrollRun);
    }

    public function approve(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->authorize('approve', $payrollRun);

        if ($payrollRun->status !== 'completed') {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-state',
                'title' => 'Invalid State',
                'status' => 422,
                'detail' => __('payroll.not_completed'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $user = $request->user();
        $payrollRun->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        AuditLog::record('payroll.approved', $payrollRun);
        $this->webhook($payrollRun->tenant_id, 'payroll.approved', [
            'public_id' => $payrollRun->public_id,
            'period' => $payrollRun->period_label,
        ]);

        return response()->json(new PayrollRunResource($payrollRun));
    }

    public function void(VoidPayrollRunRequest $request, PayrollRun $payrollRun, PayrollEngine $engine): JsonResponse
    {
        $this->authorize('void', $payrollRun);

        if (! in_array($payrollRun->status, ['completed', 'approved'], true)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-state',
                'title' => 'Invalid State',
                'status' => 422,
                'detail' => __('payroll.cannot_void'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $payrollRun = $engine->void($payrollRun, $request->user()->id, $request->validated('reason'));

        AuditLog::record('payroll.voided', $payrollRun, [
            'period' => $payrollRun->period_label,
            'reason' => $payrollRun->void_reason,
        ]);
        $this->webhook($payrollRun->tenant_id, 'payroll.voided', [
            'public_id' => $payrollRun->public_id,
            'period' => $payrollRun->period_label,
        ]);

        return response()->json(new PayrollRunResource($payrollRun));
    }

    public function reprocess(ReprocessPayrollRunRequest $request, PayrollRun $payrollRun, PayrollEngine $engine): JsonResponse
    {
        $this->authorize('reprocess', $payrollRun);

        if ($payrollRun->status !== 'voided') {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-state',
                'title' => 'Invalid State',
                'status' => 422,
                'detail' => __('payroll.cannot_reprocess'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $result = $engine->reprocess(
            $payrollRun,
            $request->user()->id,
            $request->validated('idempotency_key'),
        );

        $run = $result->run;

        if (! $result->wasDuplicate) {
            AuditLog::record('payroll.reprocessed', $run, [
                'period' => $run->period_label,
                'reprocessed_from' => $payrollRun->public_id,
            ]);
            $this->webhook($run->tenant_id, 'payroll.reprocessed', [
                'public_id' => $run->public_id,
                'period' => $run->period_label,
                'reprocessed_from' => $payrollRun->public_id,
            ]);

            PayrollProcessed::dispatch($run);
        }

        $run->load('entries.employee', 'reprocessedFrom');

        $data = (new PayrollRunResource($run))->resolve();
        $data['was_duplicate'] = $result->wasDuplicate;

        return response()->json($data, $result->wasDuplicate ? 200 : 201);
    }

    public function myPayslips(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewOwnPayslip', PayrollRun::class);

        $user = $request->user();

        $entries = PayrollEntry::query()
            ->where('employee_id', $user->employee_id)
            ->with('payrollRun', 'employee')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return PayrollEntryResource::collection($entries);
    }

    public function employeePayslips(Request $request, string $employeePublicId): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PayrollRun::class);

        $employee = Employee::where('public_id', $employeePublicId)->firstOrFail();

        $entries = PayrollEntry::query()
            ->where('employee_id', $employee->id)
            ->with('payrollRun', 'employee')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return PayrollEntryResource::collection($entries);
    }

    public function downloadPayslip(PayrollEntry $payrollEntry, PayslipPdfService $pdfService): Response
    {
        $this->authorize('viewAny', PayrollRun::class);

        $pdf = $pdfService->generate($payrollEntry);

        $filename = "payslip-{$payrollEntry->public_id}.pdf";

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function downloadBankExport(PayrollRun $payrollRun, BankExportService $bankExportService): Response
    {
        $this->authorize('view', $payrollRun);

        $csv = $bankExportService->generateCsv($payrollRun);
        $filename = "bank-export-{$payrollRun->public_id}.csv";

        AuditLog::record('payroll.bank_export', $payrollRun);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function bankExport(PayrollRun $payrollRun): JsonResponse
    {
        $this->authorize('view', $payrollRun);

        $entries = PayrollEntry::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->with('employee.bankDetails')
            ->get();

        $rows = $entries->map(function (PayrollEntry $entry) {
            $bank = $entry->employee?->bankDetails?->first();

            return [
                'employee_name' => $entry->employee?->name,
                'employee_code' => $entry->employee?->employee_code,
                'bank_name' => $bank?->bank_name ?? '',
                'branch_name' => $bank?->branch_name ?? '',
                'account_number' => $bank?->account_number ?? '',
                'net_amount_cents' => $entry->net_cents,
            ];
        });

        AuditLog::record('payroll.bank_export', $payrollRun);

        return response()->json([
            'period' => $payrollRun->period_label,
            'total_entries' => $rows->count(),
            'total_amount_cents' => $rows->sum('net_amount_cents'),
            'rows' => $rows,
        ]);
    }
}
