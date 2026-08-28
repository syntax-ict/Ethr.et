<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DisciplinaryAppealStatus;
use App\Enums\DisciplinaryCaseStatus;
use App\Enums\DisciplinaryDecision;
use App\Enums\DisciplinarySanctionType;
use App\Enums\EmployeeStatus;
use App\Events\EmployeeTransitioned;
use App\Models\AuditLog;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\EmployeeTransition;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens and progresses a disciplinary case: offence → investigation →
 * decision → sanction → optional appeal → closed. Mirrors
 * `PersonnelActionService`'s shape (a thin transactional wrapper per step,
 * `AuditLog::record` on every write) rather than a generic state-machine
 * framework, since the five steps here are fixed and don't need one.
 */
class DisciplinaryCaseService
{
    /** @param  array<string, mixed>  $data  Validated request data. */
    public function open(Employee $employee, array $data, ?int $reportedBy): DisciplinaryCase
    {
        return DB::transaction(function () use ($employee, $data, $reportedBy) {
            $case = DisciplinaryCase::create([
                'employee_id' => $employee->id,
                'reference_number' => $data['reference_number'] ?? null,
                'category' => $data['category'],
                'description' => $data['description'],
                'incident_date' => $data['incident_date'],
                'status' => DisciplinaryCaseStatus::REPORTED->value,
                'reported_by' => $reportedBy,
                'investigation_notes' => [],
            ]);

            AuditLog::record('employee.disciplinary_case_opened', $employee, [
                'case_id' => $case->public_id,
                'category' => $case->category->value,
            ]);

            return $case;
        });
    }

    public function addNote(DisciplinaryCase $case, string $note, ?User $author): DisciplinaryCase
    {
        $this->assertNotClosed($case);

        return DB::transaction(function () use ($case, $note, $author) {
            $notes = $case->investigation_notes ?? [];
            $notes[] = [
                'note' => $note,
                'by' => $author?->id,
                'by_name' => $author?->getAttribute('name'),
                'at' => now()->toIso8601String(),
            ];
            $case->investigation_notes = $notes;

            // First note moves REPORTED -> INVESTIGATING. A later note on a
            // case already past that stage (e.g. while preparing an appeal
            // response) doesn't move the status backwards or sideways.
            if ($case->status === DisciplinaryCaseStatus::REPORTED) {
                $case->status = DisciplinaryCaseStatus::INVESTIGATING;
            }

            $case->save();

            AuditLog::record('employee.disciplinary_note_added', $case->employee, [
                'case_id' => $case->public_id,
            ]);

            return $case;
        });
    }

    /** @param  array<string, mixed>  $data  Validated request data. */
    public function decide(DisciplinaryCase $case, array $data, ?int $decidedBy): DisciplinaryCase
    {
        $this->assertTransition($case, DisciplinaryCaseStatus::DECIDED);

        return DB::transaction(function () use ($case, $data, $decidedBy) {
            $case->decision = DisciplinaryDecision::from($data['decision']);
            $case->decision_notes = $data['decision_notes'] ?? null;
            $case->decided_at = now();
            $case->decided_by = $decidedBy;

            if (! empty($data['sanction_type'])) {
                $case->sanction_type = DisciplinarySanctionType::from($data['sanction_type']);
                $case->sanction_details = $data['sanction_details'] ?? null;
                $case->sanction_effective_date = ! empty($data['sanction_effective_date'])
                    ? Carbon::parse($data['sanction_effective_date'])
                    : null;
            }

            $case->status = DisciplinaryCaseStatus::DECIDED;
            $case->save();

            AuditLog::record('employee.disciplinary_decision_recorded', $case->employee, [
                'case_id' => $case->public_id,
                'decision' => $case->decision->value,
                'sanction_type' => $case->sanction_type?->value,
            ]);

            return $case;
        });
    }

    public function fileAppeal(DisciplinaryCase $case, string $grounds): DisciplinaryCase
    {
        $this->assertTransition($case, DisciplinaryCaseStatus::APPEALED);

        return DB::transaction(function () use ($case, $grounds) {
            $case->appeal_status = DisciplinaryAppealStatus::PENDING;
            $case->appeal_grounds = $grounds;
            $case->appeal_filed_at = now();
            $case->status = DisciplinaryCaseStatus::APPEALED;
            $case->save();

            AuditLog::record('employee.disciplinary_appeal_filed', $case->employee, [
                'case_id' => $case->public_id,
            ]);

            return $case;
        });
    }

    /** @param  array<string, mixed>  $data  Validated request data. */
    public function resolveAppeal(DisciplinaryCase $case, array $data, ?int $decidedBy): DisciplinaryCase
    {
        if ($case->status !== DisciplinaryCaseStatus::APPEALED || $case->appeal_status !== DisciplinaryAppealStatus::PENDING) {
            throw ValidationException::withMessages([
                'outcome' => [__('disciplinary.no_pending_appeal')],
            ]);
        }

        return DB::transaction(function () use ($case, $data, $decidedBy) {
            $outcome = DisciplinaryAppealStatus::from($data['outcome']);
            $case->appeal_status = $outcome;
            $case->appeal_decision_notes = $data['decision_notes'] ?? null;
            $case->appeal_decided_at = now();
            $case->appeal_decided_by = $decidedBy;

            // Upholding the appeal reverses the sanction — the finding stays
            // on the record, but nothing further is enforced against it.
            if ($outcome === DisciplinaryAppealStatus::UPHELD) {
                $case->sanction_type = null;
                $case->sanction_details = null;
                $case->sanction_effective_date = null;
            }

            $this->closeCase($case, null);

            AuditLog::record('employee.disciplinary_appeal_resolved', $case->employee, [
                'case_id' => $case->public_id,
                'outcome' => $outcome->value,
            ]);

            return $case;
        });
    }

    public function close(DisciplinaryCase $case, ?string $notes): DisciplinaryCase
    {
        // A pending appeal must be resolved through resolveAppeal() — that is
        // the path that decides whether the sanction stands, so a direct
        // close() here would silently skip that decision.
        if ($case->status === DisciplinaryCaseStatus::APPEALED) {
            throw ValidationException::withMessages([
                'status' => [__('disciplinary.resolve_appeal_first')],
            ]);
        }

        $this->assertTransition($case, DisciplinaryCaseStatus::CLOSED);

        return DB::transaction(function () use ($case, $notes) {
            $this->closeCase($case, $notes);

            AuditLog::record('employee.disciplinary_case_closed', $case->employee, [
                'case_id' => $case->public_id,
            ]);

            return $case;
        });
    }

    /**
     * Shared terminal step: mark the case closed and, if a termination
     * sanction still stands, apply it through the same status-transition
     * machinery `EmployeeTransitionController` uses. A sanction that never
     * actually changes anything is exactly the "reports success while doing
     * nothing" shape this codebase keeps finding and fixing elsewhere.
     */
    private function closeCase(DisciplinaryCase $case, ?string $notes): void
    {
        if ($notes) {
            $case->decision_notes = trim(($case->decision_notes ?? '')."\n\n".$notes);
        }

        $case->status = DisciplinaryCaseStatus::CLOSED;
        $case->closed_at = now();
        $case->save();

        if ($case->sanction_type === DisciplinarySanctionType::TERMINATION) {
            $this->applyTermination($case);
        }
    }

    private function applyTermination(DisciplinaryCase $case): void
    {
        $employee = Employee::findOrFail($case->employee_id);
        $target = EmployeeStatus::TERMINATED;

        // Best-effort: an employee who already left (resigned, retired,
        // already terminated) simply keeps that status — closing the case
        // must never fail because the employee record moved on already.
        if (! $employee->status->canTransitionTo($target)) {
            return;
        }

        $effectiveDate = $case->sanction_effective_date?->toDateString() ?? now()->toDateString();

        $transition = EmployeeTransition::create([
            'employee_id' => $employee->id,
            'from_status' => $employee->status->value,
            'to_status' => $target->value,
            'reason' => __('disciplinary.termination_reason', ['case' => $case->public_id]),
            'effective_date' => $effectiveDate,
            'approved_by' => $case->decided_by,
        ]);

        $employee->status = $target;
        $employee->termination_date = $effectiveDate;
        $employee->save();

        AuditLog::record('employee.transitioned', $employee, [
            'from' => $transition->from_status->value,
            'to' => $transition->to_status->value,
            'reason' => $transition->reason,
        ]);

        EmployeeTransitioned::dispatch($employee, $transition);
    }

    private function assertNotClosed(DisciplinaryCase $case): void
    {
        if ($case->status === DisciplinaryCaseStatus::CLOSED) {
            throw ValidationException::withMessages([
                'status' => [__('disciplinary.invalid_transition', [
                    'from' => $case->status->value,
                    'to' => 'investigating',
                ])],
            ]);
        }
    }

    private function assertTransition(DisciplinaryCase $case, DisciplinaryCaseStatus $target): void
    {
        if (! $case->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => [__('disciplinary.invalid_transition', [
                    'from' => $case->status->value,
                    'to' => $target->value,
                ])],
            ]);
        }
    }
}
