<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreBankDetailRequest;
use App\Http\Resources\BankDetailResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class BankDetailController extends Controller
{
    public function index(Employee $employee): AnonymousResourceCollection
    {
        Gate::authorize('employee.viewFinancial');

        return BankDetailResource::collection(
            $employee->bankDetails()->get()
        );
    }

    public function store(StoreBankDetailRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('employee.updateFinancial');

        $data = $request->validated();

        if (! empty($data['is_primary'])) {
            $employee->bankDetails()->update(['is_primary' => false]);
        }

        $detail = $employee->bankDetails()->create($data);

        AuditLog::record('employee.bank_detail.created', $employee);

        return (new BankDetailResource($detail))
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreBankDetailRequest $request, Employee $employee, EmployeeBankDetail $bankDetail): BankDetailResource
    {
        Gate::authorize('employee.updateFinancial');

        $data = $request->validated();

        if (! empty($data['is_primary'])) {
            $employee->bankDetails()->where('id', '!=', $bankDetail->id)->update(['is_primary' => false]);
        }

        $bankDetail->update($data);

        AuditLog::record('employee.bank_detail.updated', $employee);

        return new BankDetailResource($bankDetail);
    }

    public function destroy(Employee $employee, EmployeeBankDetail $bankDetail): JsonResponse
    {
        Gate::authorize('employee.updateFinancial');

        $bankDetail->delete();

        AuditLog::record('employee.bank_detail.deleted', $employee);

        return response()->json(null, 204);
    }
}
