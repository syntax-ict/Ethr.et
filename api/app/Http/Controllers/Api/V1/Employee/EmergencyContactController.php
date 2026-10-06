<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmergencyContactRequest;
use App\Http\Resources\EmergencyContactResource;
use App\Models\Employee;
use App\Models\EmployeeEmergencyContact;
use App\Support\EthiopianPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EmergencyContactController extends Controller
{
    public function index(Employee $employee): AnonymousResourceCollection
    {
        Gate::authorize('employee.view');

        return EmergencyContactResource::collection(
            $employee->emergencyContacts()->orderBy('priority')->get()
        );
    }

    public function store(StoreEmergencyContactRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('employee.update');

        $data = $request->validated();
        $data['phone'] = EthiopianPhone::canonicalOrRaw($data['phone']);
        $contact = $employee->emergencyContacts()->create($data);

        return (new EmergencyContactResource($contact))
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreEmergencyContactRequest $request, Employee $employee, EmployeeEmergencyContact $emergencyContact): EmergencyContactResource
    {
        Gate::authorize('employee.update');

        $data = $request->validated();
        $data['phone'] = EthiopianPhone::canonicalOrRaw($data['phone']);
        $emergencyContact->update($data);

        return new EmergencyContactResource($emergencyContact);
    }

    public function destroy(Employee $employee, EmployeeEmergencyContact $emergencyContact): JsonResponse
    {
        Gate::authorize('employee.update');

        $emergencyContact->delete();

        return response()->json(null, 204);
    }
}
