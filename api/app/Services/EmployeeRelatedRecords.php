<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ImpersonationRestricted;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\EmployeeEmergencyContact;
use App\Support\EthiopianPhone;

/**
 * The records that hang off an employee and are written from more than one
 * place: the profile screen's bank and emergency contact tabs, and a staged
 * list that brings a new person in with them.
 *
 * Written here so the two write them the same way: the organisation is the
 * employee's own, a phone is stored in its canonical form, a new primary
 * account demotes the old one, and each record leaves an audit entry.
 *
 * The audit entries carry no values. A bank account number and a relative's
 * phone are personal data, and the audit log is append-only.
 *
 * The caller owns the transaction and the permission: the controllers
 * authorize the request, and the staged commit writes inside the chunk's
 * transaction and under the pay permission its stage required.
 */
class EmployeeRelatedRecords
{
    /**
     * @param  array{bank_name: string, branch_name?: string|null, account_number: string, is_primary?: bool|null}  $data  as StoreBankDetailRequest validates it
     */
    public function addBankDetail(Employee $employee, array $data): EmployeeBankDetail
    {
        ImpersonationRestricted::unlessOrganisation('EmployeeRelatedRecords::addBankDetail');

        if (! empty($data['is_primary'])) {
            $employee->bankDetails()->update(['is_primary' => false]);
        }

        $detail = $employee->bankDetails()->create([...$data, 'tenant_id' => $employee->tenant_id]);

        AuditLog::record('employee.bank_detail.created', $employee);

        return $detail;
    }

    /**
     * @param  array{name: string, relationship: string, phone: string, email?: string|null, priority?: int|null}  $data  as StoreEmergencyContactRequest validates it
     */
    public function addEmergencyContact(Employee $employee, array $data): EmployeeEmergencyContact
    {
        $data['phone'] = EthiopianPhone::canonicalOrRaw($data['phone']);

        $contact = $employee->emergencyContacts()->create([...$data, 'tenant_id' => $employee->tenant_id]);

        AuditLog::record('employee.emergency_contact.created', $employee);

        return $contact;
    }
}
