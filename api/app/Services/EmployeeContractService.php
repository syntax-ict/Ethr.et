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
        return DB::transaction(function () use ($employee, $data, $createdBy) {
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

            $salaryChange = $this->applyContractSalary($employee, $contract);

            AuditLog::record('employee.contract_created', $employee, array_merge([
                'contract_id' => $contract->public_id,
                'contract_type' => $contract->contract_type->value,
            ], $salaryChange));

            return $contract;
        });
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

            $salaryChange = $this->applyContractSalary($contract->employee, $successor);

            AuditLog::record('employee.contract_renewed', $contract->employee, array_merge([
                'previous_contract_id' => $contract->public_id,
                'new_contract_id' => $successor->public_id,
            ], $salaryChange));

            return $successor;
        });
    }

    /**
     * A contract that states a salary and is already in force sets the
     * employee's salary, the field payroll reads. Until 2026-10-15 the two
     * never met: the salary typed on the contract form stayed on the contract
     * row, PayrollEngine read employees.salary_cents, and a salaried employee
     * was skipped from every run in silence (production QA, F1). A
     * future-dated contract changes nothing here — the engine falls back to
     * the contract in force during the run's own period, so the change takes
     * effect when its start date does, not before.
     *
     * @return array<string, int> Audit payload fragment, empty when nothing changed.
     */
    private function applyContractSalary(Employee $employee, EmployeeContract $contract): array
    {
        $salary = (int) ($contract->salary_cents ?? 0);

        if ($salary <= 0 || $contract->start_date->isFuture() || $salary === (int) $employee->salary_cents) {
            return [];
        }

        $previous = (int) $employee->salary_cents;
        $employee->forceFill(['salary_cents' => $salary])->save();

        return ['salary_cents_from' => $previous, 'salary_cents_to' => $salary];
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
