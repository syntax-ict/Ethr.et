<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\ProcessPayrollRequest;
use App\Http\Resources\PayrollEntryResource;
use App\Http\Resources\PayrollRunResource;
use App\Models\AuditLog;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Services\CurrentTenant;
use App\Services\Payroll\PayrollEngine;
use App\Traits\DispatchesWebhooks;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PayrollController extends Controller
{
    use DispatchesWebhooks;
    public function process(ProcessPayrollRequest $request, PayrollEngine $engine): JsonResponse
    {
        Gate::authorize('payroll.process');

        $tenant = app(CurrentTenant::class)->get();
        $user = $request->user();

        $run = $engine->process(
            $tenant->id,
            Carbon::parse($request->validated('period_start')),
            Carbon::parse($request->validated('period_end')),
            $user->id,
        );

        AuditLog::record('payroll.processed', $run, [
            'period' => $run->period_label,
            'employees' => $run->employee_count,
        ]);
        $this->webhook($run->tenant_id, 'payroll.processed', [
            'public_id' => $run->public_id,
            'period' => $run->period_label,
            'employee_count' => $run->employee_count,
        ]);

        $run->load('entries.employee');

        return (new PayrollRunResource($run))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('payroll.viewAll');

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
        Gate::authorize('payroll.viewAll');

        $payrollRun->load('entries.employee');

        return new PayrollRunResource($payrollRun);
    }

    public function approve(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        Gate::authorize('payroll.approve');

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

    public function myPayslips(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('payroll.viewOwnPayslip');

        $user = $request->user();

        $entries = PayrollEntry::query()
            ->where('employee_id', $user->employee_id)
            ->with('payrollRun')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return PayrollEntryResource::collection($entries);
    }

    public function employeePayslips(Request $request, string $employeePublicId): AnonymousResourceCollection
    {
        Gate::authorize('payroll.viewAll');

        $employee = \App\Models\Employee::where('public_id', $employeePublicId)->firstOrFail();

        $entries = PayrollEntry::query()
            ->where('employee_id', $employee->id)
            ->with('payrollRun')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return PayrollEntryResource::collection($entries);
    }

    public function bankExport(PayrollRun $payrollRun): JsonResponse
    {
        Gate::authorize('payroll.viewAll');

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

        return response()->json([
            'period' => $payrollRun->period_label,
            'total_entries' => $rows->count(),
            'total_amount_cents' => $rows->sum('net_amount_cents'),
            'rows' => $rows,
        ]);
    }
}
