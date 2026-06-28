<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\StoreLoanRequest;
use App\Http\Resources\EmployeeLoanResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class LoanController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('payroll.viewAll');

        $query = EmployeeLoan::query()->with('employee');

        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        if ($request->has('filter.employee_public_id')) {
            $employee = Employee::where('public_id', $request->input('filter.employee_public_id'))->first();
            if ($employee) {
                $query->where('employee_id', $employee->id);
            }
        }

        $query->orderByDesc('created_at');

        return EmployeeLoanResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreLoanRequest $request): JsonResponse
    {
        Gate::authorize('payroll.manageLoan');

        $employee = Employee::where('public_id', $request->validated('employee_public_id'))->firstOrFail();

        $loan = EmployeeLoan::create([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'amount_cents' => $request->validated('amount_cents'),
            'remaining_cents' => $request->validated('amount_cents'),
            'monthly_deduction_cents' => $request->validated('monthly_deduction_cents'),
            'start_date' => now(),
            'status' => 'active',
            'reason' => $request->validated('reason'),
        ]);

        AuditLog::record('loan.created', $loan, [
            'employee_public_id' => $employee->public_id,
            'amount_cents' => $loan->amount_cents,
        ]);

        $loan->load('employee');

        return (new EmployeeLoanResource($loan))
            ->response()
            ->setStatusCode(201);
    }

    public function show(EmployeeLoan $loan): EmployeeLoanResource
    {
        Gate::authorize('payroll.viewAll');

        $loan->load('employee');

        return new EmployeeLoanResource($loan);
    }
}
