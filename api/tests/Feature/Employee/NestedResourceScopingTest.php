<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEducation;
use App\Models\EmployeeEmergencyContact;

/**
 * Every child of /employees/{employee} must belong to that employee.
 *
 * Before `scopeBindings()` these routes resolved the child by its own key alone, so a
 * child id belonging to employee A resolved happily under employee B's URL. The write
 * succeeded and `AuditLog::record(..., $employee)` named B — the employee who was not
 * touched. For bank details that is a salary-redirection path with a false audit trail.
 */
beforeEach(function () {
    $this->tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $this->tenant);

    $this->victim = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->decoy = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('refuses to update a bank detail through a mismatched employee', function () {
    $bank = EmployeeBankDetail::create([
        'tenant_id' => $this->tenant->id,
        'employee_id' => $this->victim->id,
        'bank_name' => 'Victim Bank',
        'account_number' => '1000200030004000',
        'is_primary' => true,
    ]);

    $this->putJson("/api/v1/employees/{$this->decoy->public_id}/bank-details/{$bank->public_id}", [
        'bank_name' => 'Attacker Bank',
        'account_number' => '9999999999999999',
    ])->assertNotFound();

    expect($bank->fresh()->account_number)->toBe('1000200030004000');
});

it('refuses to delete a bank detail through a mismatched employee', function () {
    $bank = EmployeeBankDetail::create([
        'tenant_id' => $this->tenant->id,
        'employee_id' => $this->victim->id,
        'bank_name' => 'Victim Bank',
        'account_number' => '1000200030004000',
    ]);

    $this->deleteJson("/api/v1/employees/{$this->decoy->public_id}/bank-details/{$bank->public_id}")
        ->assertNotFound();

    expect(EmployeeBankDetail::find($bank->id))->not->toBeNull();
});

it('refuses to update an emergency contact through a mismatched employee', function () {
    $contact = EmployeeEmergencyContact::create([
        'tenant_id' => $this->tenant->id,
        'employee_id' => $this->victim->id,
        'name' => 'Victim Contact',
        'relationship' => 'Spouse',
        'phone' => '0911000000',
    ]);

    $this->putJson("/api/v1/employees/{$this->decoy->public_id}/emergency-contacts/{$contact->public_id}", [
        'name' => 'Attacker Contact',
        'relationship' => 'Other',
        'phone' => '0911999999',
    ])->assertNotFound();

    expect($contact->fresh()->name)->toBe('Victim Contact');
});

it('refuses to update an education record through a mismatched employee', function () {
    $education = EmployeeEducation::create([
        'tenant_id' => $this->tenant->id,
        'employee_id' => $this->victim->id,
        'institution' => 'Addis Ababa University',
        'degree' => 'BSc',
    ]);

    $this->putJson("/api/v1/employees/{$this->decoy->public_id}/education/{$education->public_id}", [
        'institution' => 'Forged Institution',
        'degree' => 'PhD',
    ])->assertNotFound();

    expect($education->fresh()->institution)->toBe('Addis Ababa University');
});

it('refuses to read a document through a mismatched employee', function () {
    $document = EmployeeDocument::create([
        'tenant_id' => $this->tenant->id,
        'employee_id' => $this->victim->id,
        'type' => 'contract',
        'title' => 'Employment Contract',
        'file_path' => 'documents/victim-contract.pdf',
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
    ]);

    $this->getJson("/api/v1/employees/{$this->decoy->public_id}/documents/{$document->public_id}")
        ->assertNotFound();
});

it('still allows the rightful employee to update their own bank detail', function () {
    $bank = EmployeeBankDetail::create([
        'tenant_id' => $this->tenant->id,
        'employee_id' => $this->victim->id,
        'bank_name' => 'Victim Bank',
        'account_number' => '1000200030004000',
    ]);

    $this->putJson("/api/v1/employees/{$this->victim->public_id}/bank-details/{$bank->public_id}", [
        'bank_name' => 'Commercial Bank of Ethiopia',
        'account_number' => '1000200030005000',
    ])->assertOk();

    expect($bank->fresh()->account_number)->toBe('1000200030005000');
});

it('never exposes the numeric primary key of a sub-resource', function () {
    EmployeeBankDetail::create([
        'tenant_id' => $this->tenant->id,
        'employee_id' => $this->victim->id,
        'bank_name' => 'Victim Bank',
        'account_number' => '1000200030004000',
    ]);
    EmployeeEducation::create([
        'tenant_id' => $this->tenant->id,
        'employee_id' => $this->victim->id,
        'institution' => 'Addis Ababa University',
        'degree' => 'BSc',
    ]);
    EmployeeEmergencyContact::create([
        'tenant_id' => $this->tenant->id,
        'employee_id' => $this->victim->id,
        'name' => 'Victim Contact',
        'relationship' => 'Spouse',
        'phone' => '0911000000',
    ]);

    foreach (['bank-details', 'education', 'emergency-contacts'] as $segment) {
        $this->getJson("/api/v1/employees/{$this->victim->public_id}/{$segment}")
            ->assertOk()
            ->assertJsonMissingPath('0.id')
            ->assertJsonPath('0.public_id', fn ($id) => is_string($id) && strlen($id) === 26);
    }
});

it('still allows the rightful employee to read their own document', function () {
    $document = EmployeeDocument::create([
        'tenant_id' => $this->tenant->id,
        'employee_id' => $this->victim->id,
        'type' => 'contract',
        'title' => 'Employment Contract',
        'file_path' => 'documents/victim-contract.pdf',
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
    ]);

    $this->getJson("/api/v1/employees/{$this->victim->public_id}/documents/{$document->public_id}")
        ->assertOk();
});
