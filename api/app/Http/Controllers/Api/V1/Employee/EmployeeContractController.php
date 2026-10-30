<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Enums\ContractStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\EndEmployeeContractRequest;
use App\Http\Requests\Employee\RenewEmployeeContractRequest;
use App\Http\Requests\Employee\StoreEmployeeContractRequest;
use App\Http\Resources\EmployeeContractResource;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Services\EmployeeContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EmployeeContractController extends Controller
{
    public function __construct(private readonly EmployeeContractService $contracts) {}

    public function index(Employee $employee): AnonymousResourceCollection
    {
        $this->authorize('view', $employee);

        $contracts = $employee->contracts()
            ->with('renewedFrom')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        return EmployeeContractResource::collection($contracts);
    }

    public function store(StoreEmployeeContractRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);
        $this->authorizePay($employee, $request->validated('salary_cents'));

        $contract = $this->contracts->create($employee, $request->validated(), $request->user()?->id);

        return (new EmployeeContractResource($contract))
            ->response()
            ->setStatusCode(201);
    }

    public function renew(RenewEmployeeContractRequest $request, Employee $employee, EmployeeContract $contract): JsonResponse
    {
        $this->authorize('update', $employee);
        // A renewal without a salary carries the old contract's over, which
        // can still move the employee's pay.
        $this->authorizePay($employee, $request->validated('salary_cents') ?? $contract->salary_cents);

        $successor = $this->contracts->renew($contract, $request->validated(), $request->user()?->id);

        return (new EmployeeContractResource($successor))
            ->response()
            ->setStatusCode(201);
    }

    public function end(EndEmployeeContractRequest $request, Employee $employee, EmployeeContract $contract): EmployeeContractResource
    {
        $this->authorize('update', $employee);

        $updated = $this->contracts->end($contract, $request->validated());

        return new EmployeeContractResource($updated);
    }

    /**
     * Watchlist of active contracts ending within N days, limited to the
     * employees the requester's org scope reaches — mirrors
     * EmployeeDocumentController::expiring().
     */
    public function expiring(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('employee.viewAny');

        $days = min(max((int) $request->query('days', 30), 1), 365);

        $contracts = EmployeeContract::query()
            ->where('status', ContractStatus::ACTIVE)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', now()->addDays($days))
            ->whereHas('employee', fn ($q) => $request->user()->scopeAccessibleEmployees($q))
            ->with('employee:id,public_id,name')
            ->orderBy('end_date')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return EmployeeContractResource::collection($contracts);
    }

    /**
     * A contract whose salary differs from the employee's sets their pay
     * (EmployeeContractService::applyContractSalary), so it needs the pay
     * permission, as editing the salary directly does. Without this a role
     * refused `salary_cents` on the employee could set it through a contract.
     */
    private function authorizePay(Employee $employee, mixed $salaryCents): void
    {
        if ($salaryCents !== null && (int) $salaryCents > 0 && (int) $salaryCents !== (int) $employee->salary_cents) {
            $this->authorize('updateFinancial', $employee);
        }
    }
}
