<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreProfileEmergencyContactRequest;
use App\Http\Resources\EmergencyContactResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeEmergencyContact;
use App\Support\EthiopianPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Emergency contacts, managed by the employee themselves.
 *
 * The employee-facing twin of Employee\EmergencyContactController: same table,
 * but scoped to the caller's own record and authorised by ownership rather than
 * by the `employee.update` permission, which an ordinary employee does not hold.
 *
 * Never resolved through route-model binding — every lookup goes through the
 * caller's own `emergencyContacts()` relation, so another employee's contact id
 * cannot be reached from here.
 */
class ProfileEmergencyContactController extends Controller
{
    /** More than this is a directory, not an emergency contact list. */
    private const MAX_CONTACTS = 5;

    public function index(Request $request): JsonResponse
    {
        $employee = $this->employeeOrNull($request);

        if (! $employee instanceof Employee) {
            return $this->noEmployeeRecord();
        }

        return response()->json([
            'data' => EmergencyContactResource::collection(
                $employee->emergencyContacts()->orderBy('priority')->get()
            )->resolve($request),
        ]);
    }

    public function store(StoreProfileEmergencyContactRequest $request): JsonResponse
    {
        $employee = $this->employeeOrNull($request);

        if (! $employee instanceof Employee) {
            return $this->noEmployeeRecord();
        }

        if ($employee->emergencyContacts()->count() >= self::MAX_CONTACTS) {
            return response()->json([
                'type' => 'https://ethr.et/errors/too-many-contacts',
                'title' => 'Too Many Contacts',
                'status' => 422,
                'detail' => 'You can keep at most '.self::MAX_CONTACTS.' emergency contacts.',
            ], 422);
        }

        $data = $request->validated();
        $data['phone'] = EthiopianPhone::canonicalOrRaw($data['phone']);
        $contact = $employee->emergencyContacts()->create(
            $data + ['tenant_id' => $employee->tenant_id]
        );

        AuditLog::record('profile.emergency_contact_added', $employee, ['name' => $contact->name]);

        return response()->json(
            (new EmergencyContactResource($contact))->resolve($request),
            201
        );
    }

    public function update(StoreProfileEmergencyContactRequest $request, string $publicId): JsonResponse
    {
        $employee = $this->employeeOrNull($request);

        if (! $employee instanceof Employee) {
            return $this->noEmployeeRecord();
        }

        $contact = $this->ownedContact($employee, $publicId);

        if (! $contact instanceof EmployeeEmergencyContact) {
            return $this->notFound();
        }

        $data = $request->validated();
        $data['phone'] = EthiopianPhone::canonicalOrRaw($data['phone']);
        $contact->update($data);

        AuditLog::record('profile.emergency_contact_updated', $employee, ['name' => $contact->name]);

        return response()->json((new EmergencyContactResource($contact))->resolve($request));
    }

    public function destroy(Request $request, string $publicId): JsonResponse
    {
        $employee = $this->employeeOrNull($request);

        if (! $employee instanceof Employee) {
            return $this->noEmployeeRecord();
        }

        $contact = $this->ownedContact($employee, $publicId);

        if (! $contact instanceof EmployeeEmergencyContact) {
            return $this->notFound();
        }

        $name = $contact->name;
        $contact->delete();

        AuditLog::record('profile.emergency_contact_removed', $employee, ['name' => $name]);

        return response()->json(null, 204);
    }

    private function ownedContact(Employee $employee, string $publicId): ?EmployeeEmergencyContact
    {
        $contact = $employee->emergencyContacts()->where('public_id', $publicId)->first();

        return $contact instanceof EmployeeEmergencyContact ? $contact : null;
    }

    private function employeeOrNull(Request $request): ?Employee
    {
        $employee = $request->user()?->employee;

        return $employee instanceof Employee ? $employee : null;
    }

    private function noEmployeeRecord(): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/no-employee-record',
            'title' => 'No Employee Record',
            'status' => 422,
            'detail' => 'No employee record linked to your account.',
        ], 422);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/not-found',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'Emergency contact not found.',
        ], 404);
    }
}
