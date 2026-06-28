<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmergencyContactRequest;
use App\Http\Resources\EmergencyContactResource;
use App\Models\Employee;
use App\Models\EmployeeEmergencyContact;
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

        $contact = $employee->emergencyContacts()->create($request->validated());

        return (new EmergencyContactResource($contact))
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreEmergencyContactRequest $request, Employee $employee, EmployeeEmergencyContact $contact): EmergencyContactResource
    {
        Gate::authorize('employee.update');

        $contact->update($request->validated());

        return new EmergencyContactResource($contact);
    }

    public function destroy(Employee $employee, EmployeeEmergencyContact $contact): JsonResponse
    {
        Gate::authorize('employee.update');

        $contact->delete();

        return response()->json(null, 204);
    }
}
