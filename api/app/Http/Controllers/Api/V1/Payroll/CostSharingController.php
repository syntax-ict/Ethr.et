<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Enums\CostSharingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\StoreCostSharingRequest;
use App\Http\Requests\Payroll\UpdateCostSharingRequest;
use App\Http\Resources\EmployeeCostSharingResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeCostSharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Ethiopian higher-education cost-sharing obligations.
 *
 * There is no destroy(): an obligation that has been deducted against is
 * referenced by approved payroll runs, so it is cancelled (status), never
 * removed — the soft-delete policy's reasoning applied to a financial record.
 */
class CostSharingController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('payroll.viewAll');

        $query = EmployeeCostSharing::query()->with('employee');

        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        if ($request->has('filter.employee_public_id')) {
            $employee = Employee::where('public_id', $request->input('filter.employee_public_id'))->first();

            // A public_id that matches nothing must return nothing, not
            // everything — dropping the filter would leak the whole tenant's
            // obligations on a typo'd id.
            $query->where('employee_id', $employee->id ?? 0);
        }

        $query->orderByDesc('created_at');

        return EmployeeCostSharingResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreCostSharingRequest $request): JsonResponse
    {
        Gate::authorize('payroll.manageCostSharing');

        $employee = Employee::where('public_id', $request->validated('employee_public_id'))->firstOrFail();

        // One active obligation per employee, enforced here rather than by a
        // unique index: the constraint is "at most one *active*", which a plain
        // unique index cannot express, and completed obligations must be able
        // to coexist with a new one (a second degree is a second agreement).
        $existing = EmployeeCostSharing::query()
            ->where('employee_id', $employee->id)
            ->where('status', CostSharingStatus::ACTIVE)
            ->exists();

        if ($existing) {
            return $this->problem(
                'https://ethr.et/errors/duplicate-obligation',
                'Duplicate Obligation',
                __('payroll.cost_sharing_already_active'),
            );
        }

        $obligation = EmployeeCostSharing::create([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'total_obligation_cents' => $request->validated('total_obligation_cents'),
            'outstanding_cents' => $request->validated('total_obligation_cents'),
            'deduction_rate_percent' => $request->validated('deduction_rate_percent'),
            'status' => CostSharingStatus::ACTIVE,
            'started_on' => $request->validated('started_on'),
            'notes' => $request->validated('notes'),
        ]);

        AuditLog::record('cost_sharing.created', $obligation, [
            'employee_public_id' => $employee->public_id,
            'total_obligation_cents' => $obligation->total_obligation_cents,
            'deduction_rate_percent' => (float) $obligation->deduction_rate_percent,
        ]);

        $obligation->load('employee');

        return (new EmployeeCostSharingResource($obligation))
            ->response()
            ->setStatusCode(201);
    }

    public function show(EmployeeCostSharing $costSharing): EmployeeCostSharingResource
    {
        Gate::authorize('payroll.viewAll');

        $costSharing->load('employee');

        return new EmployeeCostSharingResource($costSharing);
    }

    public function update(
        UpdateCostSharingRequest $request,
        EmployeeCostSharing $costSharing,
    ): JsonResponse|EmployeeCostSharingResource {
        Gate::authorize('payroll.manageCostSharing');

        $validated = $request->validated();

        // A status change has to be a legal transition. Without this an operator
        // could reopen a COMPLETED obligation whose balance is zero, and payroll
        // would then deduct 0 forever against an obligation that reads active.
        if (isset($validated['status'])) {
            $target = CostSharingStatus::from($validated['status']);

            if ($target !== $costSharing->status && ! $costSharing->status->canTransitionTo($target)) {
                return $this->problem(
                    'https://ethr.et/errors/invalid-state',
                    'Invalid State',
                    __('payroll.cost_sharing_invalid_transition'),
                );
            }
        }

        $before = [
            'deduction_rate_percent' => (float) $costSharing->deduction_rate_percent,
            'status' => $costSharing->status->value,
            'notes' => $costSharing->notes,
        ];

        $costSharing->update($validated);
        $costSharing->refresh();

        AuditLog::record('cost_sharing.updated', $costSharing, [
            'before' => $before,
            'after' => [
                'deduction_rate_percent' => (float) $costSharing->deduction_rate_percent,
                'status' => $costSharing->status->value,
                'notes' => $costSharing->notes,
            ],
        ]);

        $costSharing->load('employee');

        return new EmployeeCostSharingResource($costSharing);
    }

    private function problem(string $type, string $title, string $detail): JsonResponse
    {
        return response()->json([
            'type' => $type,
            'title' => $title,
            'status' => 422,
            'detail' => $detail,
        ], 422)->header('Content-Type', 'application/problem+json');
    }
}
