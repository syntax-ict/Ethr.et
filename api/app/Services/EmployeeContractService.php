<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ContractStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contract records: create, renew (creates a new linked row rather than
 * mutating the old one, so the history of who was on what terms stays
 * intact), and end (expired or terminated early).
 */
class EmployeeContractService
{
    /** @param  array<string, mixed>  $data  Validated request data. */
    public function create(Employee $employee, array $data, ?int $createdBy): EmployeeContract
    {
        $contract = EmployeeContract::create([
            'employee_id' => $employee->id,
            'reference_number' => $data['reference_number'] ?? null,
            'contract_type' => $data['contract_type'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'salary_cents' => $data['salary_cents'] ?? null,
            'terms' => $data['terms'] ?? null,
            'status' => ContractStatus::ACTIVE->value,
            'created_by' => $createdBy,
        ]);

        AuditLog::record('employee.contract_created', $employee, [
            'contract_id' => $contract->public_id,
            'contract_type' => $contract->contract_type->value,
        ]);

        return $contract;
    }

    /** @param  array<string, mixed>  $data  Validated request data. */
    public function renew(EmployeeContract $contract, array $data, ?int $createdBy): EmployeeContract
    {
        $this->assertActive($contract);

        return DB::transaction(function () use ($contract, $data, $createdBy) {
            $successor = EmployeeContract::create([
                'employee_id' => $contract->employee_id,
                'reference_number' => $data['reference_number'] ?? null,
                'contract_type' => $data['contract_type'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'salary_cents' => $data['salary_cents'] ?? $contract->salary_cents,
                'terms' => $data['terms'] ?? null,
                'status' => ContractStatus::ACTIVE->value,
                'renewed_from_id' => $contract->id,
                'created_by' => $createdBy,
            ]);

            $contract->status = ContractStatus::RENEWED;
            $contract->ended_at = Carbon::parse($data['start_date'])->subDay();
            $contract->save();

            AuditLog::record('employee.contract_renewed', $contract->employee, [
                'previous_contract_id' => $contract->public_id,
                'new_contract_id' => $successor->public_id,
            ]);

            return $successor;
        });
    }

    /** @param  array<string, mixed>  $data  Validated request data. */
    public function end(EmployeeContract $contract, array $data): EmployeeContract
    {
        $this->assertActive($contract);

        $contract->status = ContractStatus::from($data['status']);
        $contract->ended_at = ! empty($data['ended_at']) ? Carbon::parse($data['ended_at']) : now();
        $contract->end_notes = $data['end_notes'] ?? null;
        $contract->save();

        AuditLog::record('employee.contract_ended', $contract->employee, [
            'contract_id' => $contract->public_id,
            'status' => $contract->status->value,
        ]);

        return $contract;
    }

    private function assertActive(EmployeeContract $contract): void
    {
        if ($contract->status !== ContractStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'status' => [__('contract.not_active')],
            ]);
        }
    }
}
