<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Events\PayrollApproved;
use App\Events\PayrollProcessed;
use App\Exceptions\PayrollRunRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\ApprovePayrollRunRequest;
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
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Payroll\BankExportService;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\PayslipPdfService;
use App\Services\Payroll\RunRegisterService;
use App\Services\Payroll\StatutorySchedules;
use App\Support\Csv;
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

            // Who started the run. `payroll.processed`, written by the job,
            // announces completion and has no request user to name; without
            // this the audit log could not say who ran payroll (production QA
            // 2026-10-11, P14).
            AuditLog::record('payroll.requested', $run, [
                'period_start' => $run->period_start?->toDateString(),
                'period_end' => $run->period_end?->toDateString(),
            ]);
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

    public function approve(ApprovePayrollRunRequest $request, PayrollRun $payrollRun, RunRegisterService $registerService, PayrollEngine $engine): JsonResponse
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

        // A run that exempts anyone from pension is approved only by someone
        // who says how many: an exemption wrongly applied leaves the employer
        // owing the contributions plus 5 % a month (research P12).
        $exempt = RunRegisterService::exemptions($registerService->register($payrollRun)['rows'])['count'];
        if ($exempt > 0 && (int) $request->validated('acknowledged_pension_exemptions') !== $exempt) {
            return response()->json([
                'type' => 'https://ethr.et/errors/pension-exemptions-unacknowledged',
                'title' => 'Pension exemptions not acknowledged',
                'status' => 422,
                'detail' => __('payroll.pension_exemptions_unacknowledged', ['count' => $exempt]),
                'pension_exemptions' => $exempt,
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        // Maker-checker: the person who processed a run does not also release
        // it while anyone else in the organisation can. An organisation whose
        // only approver is that person is not left unable to pay its staff;
        // the approval goes through and the audit row says it was their own.
        $approver = $request->user();
        $selfApproved = $payrollRun->processed_by !== null
            && (int) $payrollRun->processed_by === (int) $approver->id;
        if ($selfApproved && $this->anotherApproverExists($approver)) {
            throw PayrollRunRefused::selfApproval();
        }

        // Approval is where loan and cost-sharing balances move. The engine
        // re-checks the status under a lock and refuses, as a 422, a run whose
        // balances changed after it was calculated (PayrollRunRefused).
        $payrollRun = $engine->approve($payrollRun, $approver->id);

        AuditLog::record('payroll.approved', $payrollRun, $selfApproved ? ['self_approved' => true] : []);
        PayrollApproved::dispatch($payrollRun);
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

        // Approved runs only. A completed run is still finance's draft — a
        // void and reprocess can change every figure on it — and this listed
        // those payslips to employees before anyone had approved them.
        $entries = PayrollEntry::query()
            ->where('employee_id', $user->employee_id)
            ->whereHas('payrollRun', fn ($q) => $q->where('status', 'approved'))
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

    public function downloadPayslip(Request $request, PayrollEntry $payrollEntry, PayslipPdfService $pdfService): Response
    {
        // Payroll staff may download any payslip; an employee their own, once
        // the run is approved. This authorised `viewAny` alone, so the one
        // person a payslip is for could not download it.
        $user = $request->user();
        $ownReleased = $user->hasPermission('payroll.viewOwnPayslip')
            && $user->employee_id !== null
            && $payrollEntry->employee_id === $user->employee_id
            && $payrollEntry->payrollRun?->status === 'approved';

        if (! $ownReleased) {
            $this->authorize('viewAny', PayrollRun::class);
        }

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
        $this->ensureApprovedForPayment($payrollRun);

        $csv = $bankExportService->generateCsv($payrollRun);
        $filename = "bank-export-{$payrollRun->public_id}.csv";

        AuditLog::record('payroll.bank_export', $payrollRun);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * The payroll register: every figure behind each employee's pay, with
     * totals that tie to the run and the accounting journal
     * (RunRegisterService). What an accountant reconciles a run against.
     */
    public function register(PayrollRun $payrollRun, RunRegisterService $registerService): JsonResponse
    {
        $this->authorize('view', $payrollRun);

        $register = $registerService->register($payrollRun);

        return response()->json([
            'period' => $payrollRun->period_label,
            'status' => $payrollRun->status,
            'rows' => $register['rows'],
            'totals' => $register['totals'],
            'pension_rates' => $register['pension_rates'],
            'basis' => $register['basis'],
            'pension_exemptions' => $register['pension_exemptions'],
            'overtime_limits' => $register['overtime_limits'],
            'absences' => $register['absences'],
        ]);
    }

    public function downloadRegister(PayrollRun $payrollRun, RunRegisterService $registerService): Response
    {
        $this->authorize('view', $payrollRun);

        $csv = Csv::forSpreadsheet($registerService->csv($payrollRun));

        AuditLog::record('payroll.register_export', $payrollRun);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"payroll-register-{$payrollRun->public_id}.csv\"",
        ]);
    }

    /**
     * What a statutory schedule will contain, without the TINs: its header,
     * totals, row count and who is missing a TIN — the check before filing.
     * The TINs themselves are only in the downloads, which are audited.
     */
    public function statutory(PayrollRun $payrollRun, string $schedule, StatutorySchedules $schedules): JsonResponse
    {
        $this->authorize('view', $payrollRun);

        $data = $schedules->build($payrollRun, $schedule);

        return response()->json([
            'schedule' => $data['schedule'],
            'header' => $data['header'],
            'employees' => count($data['rows']),
            'columns' => $data['columns'],
            'totals' => $data['totals'],
            'missing_tin' => $data['missing_tin'],
            'basis' => $data['basis'],
        ]);
    }

    /**
     * The schedule as a file: `?format=csv` (default) or `pdf`. Carries every
     * employee's TIN, so each download is audited.
     */
    public function downloadStatutory(Request $request, PayrollRun $payrollRun, string $schedule, StatutorySchedules $schedules): Response
    {
        $this->authorize('view', $payrollRun);

        $pdf = $request->query('format') === 'pdf';
        AuditLog::record('payroll.statutory_export', $payrollRun, ['schedule' => $schedule, 'format' => $pdf ? 'pdf' : 'csv']);

        $name = "{$schedule}-schedule-{$payrollRun->public_id}";

        return $pdf
            ? response($schedules->pdf($payrollRun, $schedule), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$name}.pdf\"",
            ])
            : response($schedules->csv($payrollRun, $schedule), 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$name}.csv\"",
            ]);
    }

    public function bankExport(PayrollRun $payrollRun, BankExportService $bankExportService): JsonResponse
    {
        $this->authorize('view', $payrollRun);
        $this->ensureApprovedForPayment($payrollRun);

        $entries = PayrollEntry::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->with('employee.bankDetails')
            ->get();

        $rows = $entries->map(function (PayrollEntry $entry) use ($bankExportService) {
            $bank = $entry->employee ? $bankExportService->primaryBankDetail($entry->employee) : null;

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
            'total_amount_cents' => (int) $rows->sum('net_amount_cents'),
            'rows' => $rows,
        ]);
    }

    /**
     * A bank file is an instruction to pay. Until 2026-10-20 both exports
     * checked only `view`, so the file could be built for a draft or voided
     * run and uploaded before anyone approved it (blueprint assessment D2).
     * Thrown rather than returned so the response shapes Scramble publishes
     * stay as they are; bootstrap/app.php renders it as a 422.
     */
    private function ensureApprovedForPayment(PayrollRun $payrollRun): void
    {
        if ($payrollRun->status !== 'approved') {
            throw PayrollRunRefused::notApprovedForPayment();
        }
    }

    /**
     * Whether someone other than `$user` could approve this tenant's payroll:
     * an active user holding `payroll.approve`, resolved the way
     * hasPermission() resolves it, so a custom role counts.
     */
    private function anotherApproverExists(User $user): bool
    {
        return User::query()
            ->where('status', 'active')
            ->whereKeyNot($user->getKey())
            ->get()
            ->contains(fn (User $other) => $other->hasPermission('payroll.approve'));
    }
}
