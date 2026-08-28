<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\RetirementCaseStatus;
use App\Enums\RetirementDecision;
use App\Events\EmployeeTransitioned;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeTransition;
use App\Models\RetirementCase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Initiates and progresses a retirement case: initiated → (review) →
 * approved/rejected → finalized. Mirrors `DisciplinaryCaseService`'s shape —
 * a thin transactional wrapper per step, `AuditLog::record` on every write.
 *
 * Deliberately does not compute a pension *benefit amount* — that is a
 * statutory calculation this codebase has no verified source for (same
 * caution the tax-bracket currency question elsewhere in this project takes).
 * What it does compute are objective facts: service years from `hire_date`,
 * and an eligible-retirement date from `date_of_birth` plus the tenant's
 * configurable `retirement_age` setting (default 60, overridable per tenant
 * exactly like the payroll settings already are — a country-wide age has no
 * single authoritative figure this codebase can hard-code with confidence).
 */
class RetirementCaseService
{
    /** @param  array<string, mixed>  $data  Validated request data. */
    public function initiate(Employee $employee, array $data, ?int $initiatedBy): RetirementCase
    {
        $hasOpenCase = RetirementCase::where('employee_id', $employee->id)
            ->whereNotIn('status', [
                RetirementCaseStatus::REJECTED->value,
                RetirementCaseStatus::FINALIZED->value,
                RetirementCaseStatus::CANCELLED->value,
            ])
            ->exists();

        if ($hasOpenCase) {
            throw ValidationException::withMessages([
                'employee_id' => [__('retirement.already_has_open_case')],
            ]);
        }

        return DB::transaction(function () use ($employee, $data, $initiatedBy) {
            $case = RetirementCase::create([
                'employee_id' => $employee->id,
                'retirement_type' => $data['retirement_type'],
                'status' => RetirementCaseStatus::INITIATED->value,
                'service_years' => $this->serviceYears($employee),
                'eligible_retirement_date' => $this->eligibleRetirementDate($employee),
                'reason' => $data['reason'] ?? null,
                'initiated_by' => $initiatedBy,
            ]);

            AuditLog::record('employee.retirement_case_initiated', $employee, [
                'case_id' => $case->public_id,
                'retirement_type' => $case->retirement_type->value,
            ]);

            return $case;
        });
    }

    public function addNote(RetirementCase $case, string $note, ?User $author): RetirementCase
    {
        $this->assertNotTerminal($case);

        return DB::transaction(function () use ($case, $note, $author) {
            $byLine = $author ? ' — '.$author->getAttribute('name') : '';
            $case->notes = trim(($case->notes ?? '')."\n\n[".now()->toIso8601String()."{$byLine}] {$note}");

            // First note moves INITIATED -> UNDER_REVIEW, same as the
            // disciplinary case's first-note transition. A later note on a
            // case already past that stage doesn't move it backwards.
            if ($case->status === RetirementCaseStatus::INITIATED) {
                $case->status = RetirementCaseStatus::UNDER_REVIEW;
            }

            $case->save();

            AuditLog::record('employee.retirement_note_added', $case->employee, [
                'case_id' => $case->public_id,
            ]);

            return $case;
        });
    }

    /** @param  array<string, mixed>  $data  Validated request data. */
    public function decide(RetirementCase $case, array $data, ?int $decidedBy): RetirementCase
    {
        $decision = RetirementDecision::from($data['decision']);
        $target = $decision === RetirementDecision::APPROVED
            ? RetirementCaseStatus::APPROVED
            : RetirementCaseStatus::REJECTED;

        $this->assertTransition($case, $target);

        return DB::transaction(function () use ($case, $data, $decidedBy, $decision, $target) {
            $case->decision = $decision;
            $case->decision_notes = $data['decision_notes'] ?? null;
            $case->decided_at = now();
            $case->decided_by = $decidedBy;
            $case->status = $target;
            $case->save();

            AuditLog::record('employee.retirement_decision_recorded', $case->employee, [
                'case_id' => $case->public_id,
                'decision' => $case->decision->value,
            ]);

            return $case;
        });
    }

    /**
     * The actual status change, through the same machinery
     * `EmployeeTransitionController` uses — an `EmployeeTransition` row, the
     * `EmployeeTransitioned` event, an audit log entry. Unlike disciplinary
     * termination (which can be reached indirectly via appeal resolution and
     * so no-ops if the employee already left some other way), finalize() is
     * the only path into this side effect and an explicit action HR is
     * choosing to take, so an employee who can't transition to `retired`
     * fails loudly rather than silently doing nothing.
     */
    public function finalize(RetirementCase $case, string $effectiveDate, ?int $finalizedBy): RetirementCase
    {
        $this->assertTransition($case, RetirementCaseStatus::FINALIZED);

        return DB::transaction(function () use ($case, $effectiveDate, $finalizedBy) {
            $employee = Employee::findOrFail($case->employee_id);
            $target = EmployeeStatus::RETIRED;

            if (! $employee->status->canTransitionTo($target)) {
                throw ValidationException::withMessages([
                    'employee_id' => [__('retirement.invalid_transition', [
                        'from' => $employee->status->value,
                        'to' => $target->value,
                    ])],
                ]);
            }

            $transition = EmployeeTransition::create([
                'employee_id' => $employee->id,
                'from_status' => $employee->status->value,
                'to_status' => $target->value,
                'reason' => __('retirement.retirement_reason', ['case' => $case->public_id]),
                'effective_date' => $effectiveDate,
                'approved_by' => $finalizedBy,
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

            $case->status = RetirementCaseStatus::FINALIZED;
            $case->finalized_at = now();
            $case->finalized_transition_id = $transition->id;
            $case->save();

            AuditLog::record('employee.retirement_case_finalized', $employee, [
                'case_id' => $case->public_id,
            ]);

            return $case;
        });
    }

    public function cancel(RetirementCase $case, ?string $notes): RetirementCase
    {
        $this->assertTransition($case, RetirementCaseStatus::CANCELLED);

        return DB::transaction(function () use ($case, $notes) {
            if ($notes) {
                $case->notes = trim(($case->notes ?? '')."\n\n{$notes}");
            }

            $case->status = RetirementCaseStatus::CANCELLED;
            $case->save();

            AuditLog::record('employee.retirement_case_cancelled', $case->employee, [
                'case_id' => $case->public_id,
            ]);

            return $case;
        });
    }

    /** Years of service as of today, from `hire_date`. Zero if unset. */
    private function serviceYears(Employee $employee): float
    {
        if (! $employee->hire_date) {
            return 0.0;
        }

        return round($employee->hire_date->diffInDays(now()) / 365.25, 2);
    }

    /**
     * `date_of_birth` + the tenant's `retirement_age` setting (default 60).
     * Null if `date_of_birth` is unset — this is informational, not a gate,
     * so a missing birth date does not block initiating a case.
     */
    private function eligibleRetirementDate(Employee $employee): ?Carbon
    {
        if (! $employee->date_of_birth) {
            return null;
        }

        $retirementAge = (int) ($employee->tenant->settings['retirement_age'] ?? 60);

        return $employee->date_of_birth->copy()->addYears($retirementAge);
    }

    private function assertNotTerminal(RetirementCase $case): void
    {
        if (in_array($case->status, [
            RetirementCaseStatus::REJECTED,
            RetirementCaseStatus::FINALIZED,
            RetirementCaseStatus::CANCELLED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => [__('retirement.invalid_transition', [
                    'from' => $case->status->value,
                    'to' => 'under_review',
                ])],
            ]);
        }
    }

    private function assertTransition(RetirementCase $case, RetirementCaseStatus $target): void
    {
        if (! $case->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => [__('retirement.invalid_transition', [
                    'from' => $case->status->value,
                    'to' => $target->value,
                ])],
            ]);
        }
    }
}
