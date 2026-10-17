<?php

declare(strict_types=1);

use App\Enums\ContractStatus;
use App\Enums\OrgScope;
use App\Enums\UserRole;
use App\Models\CustomRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEducation;
use App\Models\EmployeeEmergencyContact;
use App\Models\Permission;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * The records under `/employees/{employee}` — bank details, education,
 * emergency contacts, contracts and documents — authorised with
 * `Gate::authorize('employee.view')`, `('employee.update')`,
 * `('employee.viewFinancial')` and `('employee.updateFinancial')`.
 * `AppServiceProvider`'s `Gate::before` answers a known Permission ability
 * from `hasPermission()` alone, so `EmployeePolicy` never ran, and neither did
 * its `canAccessEmployee()` check: a role scoped to one department could read
 * and change these records for any employee in the organisation, bank account
 * numbers included. All of them now authorise against the policy with the
 * model. The same defect as `EmployeeTransitionScopeTest`, one class wider.
 */

/**
 * A custom role scoped to one department, holding the given abilities, and a
 * login on it whose own employee sits in that department. Returns an employee
 * inside that department and one outside it.
 *
 * @param  list<string>  $abilities
 * @return array{0: Employee, 1: Employee}
 */
function recordScopedManager(Tenant $tenant, array $abilities): array
{
    $mine = Department::factory()->create(['tenant_id' => $tenant->id]);
    $theirs = Department::factory()->create(['tenant_id' => $tenant->id]);
    $head = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id]);

    $role = CustomRole::create([
        'tenant_id' => $tenant->id,
        'name' => 'Department manager',
        'org_scope' => OrgScope::DEPARTMENT->value,
    ]);
    $role->permissions()->sync(Permission::whereIn('name', $abilities)->pluck('id'));

    actingAsUser([
        'role' => UserRole::EMPLOYEE,
        'custom_role_id' => $role->id,
        'employee_id' => $head->id,
    ], $tenant);

    return [
        Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id]),
        Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $theirs->id]),
    ];
}

function scopedBankDetail(Employee $employee): EmployeeBankDetail
{
    return EmployeeBankDetail::create([
        'tenant_id' => $employee->tenant_id,
        'employee_id' => $employee->id,
        'bank_name' => 'Commercial Bank of Ethiopia',
        'account_number' => '1000200030004000',
        'is_primary' => true,
    ]);
}

function scopedEducation(Employee $employee): EmployeeEducation
{
    return EmployeeEducation::create([
        'tenant_id' => $employee->tenant_id,
        'employee_id' => $employee->id,
        'institution' => 'Addis Ababa University',
        'degree' => 'BSc',
    ]);
}

function scopedEmergencyContact(Employee $employee): EmployeeEmergencyContact
{
    return EmployeeEmergencyContact::create([
        'tenant_id' => $employee->tenant_id,
        'employee_id' => $employee->id,
        'name' => 'Original Contact',
        'relationship' => 'Spouse',
        'phone' => '0911000000',
    ]);
}

function scopedDocument(Employee $employee): EmployeeDocument
{
    return EmployeeDocument::create([
        'tenant_id' => $employee->tenant_id,
        'employee_id' => $employee->id,
        'type' => 'contract',
        'title' => 'Employment Contract',
        'file_path' => "tenants/x/employees/{$employee->public_id}/documents/contract.pdf",
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
    ]);
}

/**
 * A plain-text upload, because the document endpoint checks the content against
 * the declared type and a fake PDF carries no PDF header.
 */
function scopedUpload(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent("{$name}.txt", "Scope test document {$name}
");
}

/** @return array<string, string> */
function scopedContractTerms(): array
{
    return [
        'contract_type' => 'fixed_term',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ];
}

describe('bank details', function () {
    test('a department-scoped role cannot read or change them outside its department', function () {
        $tenant = createTenant();
        [, $outside] = recordScopedManager($tenant, ['employee.viewFinancial', 'employee.updateFinancial']);
        $detail = scopedBankDetail($outside);
        $base = "/api/v1/employees/{$outside->public_id}/bank-details";

        $this->getJson($base)->assertForbidden();
        $this->postJson($base, ['bank_name' => 'Attacker Bank', 'account_number' => '9999999999999999'])
            ->assertForbidden();
        $this->putJson("{$base}/{$detail->public_id}", ['bank_name' => 'Attacker Bank', 'account_number' => '9999999999999999'])
            ->assertForbidden();
        $this->deleteJson("{$base}/{$detail->public_id}")->assertForbidden();

        expect(EmployeeBankDetail::where('employee_id', $outside->id)->count())->toBe(1)
            ->and($detail->fresh()->account_number)->toBe('1000200030004000')
            ->and($detail->fresh()->bank_name)->toBe('Commercial Bank of Ethiopia');
    });

    test('a department-scoped role still reads and changes them inside its department', function () {
        $tenant = createTenant();
        [$inside] = recordScopedManager($tenant, ['employee.viewFinancial', 'employee.updateFinancial']);
        $detail = scopedBankDetail($inside);
        $base = "/api/v1/employees/{$inside->public_id}/bank-details";

        $this->getJson($base)->assertOk()->assertJsonCount(1);
        $this->postJson($base, ['bank_name' => 'Awash Bank', 'account_number' => '5000600070008000'])
            ->assertCreated();
        $this->putJson("{$base}/{$detail->public_id}", ['bank_name' => 'Dashen Bank', 'account_number' => '1000200030005000'])
            ->assertOk();

        expect($detail->fresh()->account_number)->toBe('1000200030005000');

        $this->deleteJson("{$base}/{$detail->public_id}")->assertNoContent();

        expect(EmployeeBankDetail::where('employee_id', $inside->id)->count())->toBe(1);
    });

    test('the financial permission is still required inside the scope', function () {
        $tenant = createTenant();
        [$inside] = recordScopedManager($tenant, ['employee.view', 'employee.update']);
        $base = "/api/v1/employees/{$inside->public_id}/bank-details";

        $this->getJson($base)->assertForbidden();
        $this->postJson($base, ['bank_name' => 'Awash Bank', 'account_number' => '5000600070008000'])
            ->assertForbidden();

        expect(EmployeeBankDetail::where('employee_id', $inside->id)->exists())->toBeFalse();
    });
});

describe('education', function () {
    test('a department-scoped role cannot read or change it outside its department', function () {
        $tenant = createTenant();
        [, $outside] = recordScopedManager($tenant, ['employee.view', 'employee.update']);
        $education = scopedEducation($outside);
        $base = "/api/v1/employees/{$outside->public_id}/education";

        $this->getJson($base)->assertForbidden();
        $this->postJson($base, ['institution' => 'Forged Institution', 'degree' => 'PhD'])->assertForbidden();
        $this->putJson("{$base}/{$education->public_id}", ['institution' => 'Forged Institution', 'degree' => 'PhD'])
            ->assertForbidden();
        $this->deleteJson("{$base}/{$education->public_id}")->assertForbidden();

        expect(EmployeeEducation::where('employee_id', $outside->id)->count())->toBe(1)
            ->and($education->fresh()->institution)->toBe('Addis Ababa University');
    });

    test('a department-scoped role still reads and changes it inside its department', function () {
        $tenant = createTenant();
        [$inside] = recordScopedManager($tenant, ['employee.view', 'employee.update']);
        $education = scopedEducation($inside);
        $base = "/api/v1/employees/{$inside->public_id}/education";

        $this->getJson($base)->assertOk()->assertJsonCount(1);
        $this->postJson($base, ['institution' => 'Bahir Dar University', 'degree' => 'MSc'])->assertCreated();
        $this->putJson("{$base}/{$education->public_id}", ['institution' => 'Mekelle University', 'degree' => 'BSc'])
            ->assertOk();

        expect($education->fresh()->institution)->toBe('Mekelle University');

        $this->deleteJson("{$base}/{$education->public_id}")->assertNoContent();

        expect(EmployeeEducation::where('employee_id', $inside->id)->count())->toBe(1);
    });
});

describe('emergency contacts', function () {
    test('a department-scoped role cannot read or change them outside its department', function () {
        $tenant = createTenant();
        [, $outside] = recordScopedManager($tenant, ['employee.view', 'employee.update']);
        $contact = scopedEmergencyContact($outside);
        $base = "/api/v1/employees/{$outside->public_id}/emergency-contacts";
        $forged = ['name' => 'Forged Contact', 'relationship' => 'Other', 'phone' => '0911999999'];

        $this->getJson($base)->assertForbidden();
        $this->postJson($base, $forged)->assertForbidden();
        $this->putJson("{$base}/{$contact->public_id}", $forged)->assertForbidden();
        $this->deleteJson("{$base}/{$contact->public_id}")->assertForbidden();

        expect(EmployeeEmergencyContact::where('employee_id', $outside->id)->count())->toBe(1)
            ->and($contact->fresh()->name)->toBe('Original Contact');
    });

    test('a department-scoped role still reads and changes them inside its department', function () {
        $tenant = createTenant();
        [$inside] = recordScopedManager($tenant, ['employee.view', 'employee.update']);
        $contact = scopedEmergencyContact($inside);
        $base = "/api/v1/employees/{$inside->public_id}/emergency-contacts";

        $this->getJson($base)->assertOk()->assertJsonCount(1);
        $this->postJson($base, ['name' => 'Second Contact', 'relationship' => 'Sibling', 'phone' => '0911222333'])
            ->assertCreated();
        $this->putJson("{$base}/{$contact->public_id}", ['name' => 'Renamed Contact', 'relationship' => 'Spouse', 'phone' => '0911000000'])
            ->assertOk();

        expect($contact->fresh()->name)->toBe('Renamed Contact');

        $this->deleteJson("{$base}/{$contact->public_id}")->assertNoContent();

        expect(EmployeeEmergencyContact::where('employee_id', $inside->id)->count())->toBe(1);
    });
});

describe('contracts', function () {
    test('a department-scoped role cannot read or change them outside its department', function () {
        $tenant = createTenant();
        [, $outside] = recordScopedManager($tenant, ['employee.view', 'employee.update']);
        $contract = EmployeeContract::factory()->for($outside)->create(['tenant_id' => $tenant->id]);
        $base = "/api/v1/employees/{$outside->public_id}/contracts";

        $this->getJson($base)->assertForbidden();
        $this->postJson($base, scopedContractTerms())->assertForbidden();
        $this->postJson("{$base}/{$contract->public_id}/renew", [...scopedContractTerms(), 'start_date' => '2027-01-01', 'end_date' => '2027-12-31'])
            ->assertForbidden();
        $this->postJson("{$base}/{$contract->public_id}/end", ['status' => ContractStatus::TERMINATED_EARLY->value])
            ->assertForbidden();

        expect(EmployeeContract::where('employee_id', $outside->id)->count())->toBe(1)
            ->and($contract->fresh()->status)->toBe(ContractStatus::ACTIVE)
            ->and($contract->fresh()->ended_at)->toBeNull();
    });

    test('a department-scoped role still reads and changes them inside its department', function () {
        $tenant = createTenant();
        [$inside] = recordScopedManager($tenant, ['employee.view', 'employee.update']);
        $contract = EmployeeContract::factory()->for($inside)->create(['tenant_id' => $tenant->id]);
        $ending = EmployeeContract::factory()->for($inside)->create(['tenant_id' => $tenant->id]);
        $base = "/api/v1/employees/{$inside->public_id}/contracts";

        $this->getJson($base)->assertOk()->assertJsonCount(2);
        $this->postJson("{$base}/{$contract->public_id}/renew", [...scopedContractTerms(), 'start_date' => '2027-01-01', 'end_date' => '2027-12-31'])
            ->assertCreated();
        $this->postJson("{$base}/{$ending->public_id}/end", ['status' => ContractStatus::TERMINATED_EARLY->value])
            ->assertOk();

        expect($contract->fresh()->status)->toBe(ContractStatus::RENEWED)
            ->and($ending->fresh()->status)->toBe(ContractStatus::TERMINATED_EARLY)
            ->and(EmployeeContract::where('employee_id', $inside->id)->count())->toBe(3);
    });
});

describe('documents', function () {
    beforeEach(function () {
        Storage::fake((string) config('filesystems.default'));
    });

    test('a department-scoped role cannot read or change them outside its department', function () {
        $tenant = createTenant();
        [, $outside] = recordScopedManager($tenant, ['employee.view', 'employee.update']);
        $document = scopedDocument($outside);
        $base = "/api/v1/employees/{$outside->public_id}/documents";

        $this->getJson($base)->assertForbidden();
        $this->getJson("{$base}/{$document->public_id}")->assertForbidden();
        $this->post($base, [
            'file' => scopedUpload('forged'),
            'title' => 'Forged',
            'type' => 'other',
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson("{$base}/{$document->public_id}")->assertForbidden();

        expect(EmployeeDocument::where('employee_id', $outside->id)->count())->toBe(1)
            ->and($document->fresh())->not->toBeNull();
        expect(Storage::disk((string) config('filesystems.default'))->allFiles())->toBe([]);
    });

    test('a department-scoped role still reads and changes them inside its department', function () {
        $tenant = createTenant();
        [$inside] = recordScopedManager($tenant, ['employee.view', 'employee.update']);
        $document = scopedDocument($inside);
        $base = "/api/v1/employees/{$inside->public_id}/documents";

        $this->getJson($base)->assertOk()->assertJsonCount(1);
        $this->getJson("{$base}/{$document->public_id}")->assertOk();
        $this->post($base, [
            'file' => scopedUpload('permit'),
            'title' => 'Work permit',
            'type' => 'other',
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->deleteJson("{$base}/{$document->public_id}")->assertNoContent();

        expect(EmployeeDocument::where('employee_id', $inside->id)->count())->toBe(1);
    });
});

test('an HR admin reads and changes these records for any employee in the organisation', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Storage::fake((string) config('filesystems.default'));

    $anyone = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => Department::factory()->create(['tenant_id' => $tenant->id])->id,
    ]);
    $base = "/api/v1/employees/{$anyone->public_id}";

    $this->postJson("{$base}/bank-details", ['bank_name' => 'Awash Bank', 'account_number' => '5000600070008000'])
        ->assertCreated();
    $this->getJson("{$base}/bank-details")->assertOk()->assertJsonCount(1);

    $this->postJson("{$base}/education", ['institution' => 'Bahir Dar University', 'degree' => 'MSc'])->assertCreated();
    $this->getJson("{$base}/education")->assertOk()->assertJsonCount(1);

    $this->postJson("{$base}/emergency-contacts", ['name' => 'Next of Kin', 'relationship' => 'Parent', 'phone' => '0911222333'])
        ->assertCreated();
    $this->getJson("{$base}/emergency-contacts")->assertOk()->assertJsonCount(1);

    $this->postJson("{$base}/contracts", scopedContractTerms())->assertCreated();
    $this->getJson("{$base}/contracts")->assertOk()->assertJsonCount(1);

    $this->post("{$base}/documents", [
        'file' => scopedUpload('permit'),
        'title' => 'Work permit',
        'type' => 'other',
    ], ['Accept' => 'application/json'])->assertCreated();
    $this->getJson("{$base}/documents")->assertOk()->assertJsonCount(1);
});
