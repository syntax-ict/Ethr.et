<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Exceptions\ImpersonationRestricted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreBankDetailRequest;
use App\Http\Resources\BankDetailResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Services\EmployeeRelatedRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BankDetailController extends Controller
{
    public function index(Employee $employee): AnonymousResourceCollection
    {
        $this->authorize('viewFinancial', $employee);

        return BankDetailResource::collection(
            $employee->bankDetails()->get()
        );
    }

    public function store(StoreBankDetailRequest $request, Employee $employee, EmployeeRelatedRecords $records): JsonResponse
    {
        $this->authorize('updateFinancial', $employee);

        $detail = $records->addBankDetail($employee, $request->validated());

        return (new BankDetailResource($detail))
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreBankDetailRequest $request, Employee $employee, EmployeeBankDetail $bankDetail): BankDetailResource
    {
        $this->authorize('updateFinancial', $employee);
        ImpersonationRestricted::unlessOrganisation('BankDetailController::update');

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
        $this->authorize('updateFinancial', $employee);

        $bankDetail->delete();

        AuditLog::record('employee.bank_detail.deleted', $employee);

        return response()->json(null, 204);
    }
}
