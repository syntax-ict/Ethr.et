<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

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
        Gate::authorize('employee.view');

        $contracts = $employee->contracts()
            ->with('renewedFrom')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        return EmployeeContractResource::collection($contracts);
    }

    public function store(StoreEmployeeContractRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('employee.update');

        $contract = $this->contracts->create($employee, $request->validated(), $request->user()?->id);

        return (new EmployeeContractResource($contract))
            ->response()
            ->setStatusCode(201);
    }

    public function renew(RenewEmployeeContractRequest $request, Employee $employee, EmployeeContract $contract): JsonResponse
    {
        Gate::authorize('employee.update');

        $successor = $this->contracts->renew($contract, $request->validated(), $request->user()?->id);

        return (new EmployeeContractResource($successor))
            ->response()
            ->setStatusCode(201);
    }

    public function end(EndEmployeeContractRequest $request, Employee $employee, EmployeeContract $contract): EmployeeContractResource
    {
        Gate::authorize('employee.update');

        $updated = $this->contracts->end($contract, $request->validated());

        return new EmployeeContractResource($updated);
    }

    /**
     * Tenant-wide watchlist of active contracts ending within N days —
     * mirrors EmployeeDocumentController::expiring().
     */
    public function expiring(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('employee.viewAny');

        $days = min(max((int) $request->query('days', 30), 1), 365);

        $contracts = EmployeeContract::query()
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', now()->addDays($days))
            ->with('employee:id,public_id,name')
            ->orderBy('end_date')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return EmployeeContractResource::collection($contracts);
    }
}
