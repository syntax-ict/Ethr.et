<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ApplicationStage;
use App\Enums\EmployeeStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\JobApplication;
use App\Models\JobApplicationEvent;
use App\Models\User;
use App\Models\Vacancy;
use App\Support\EthiopianPhone;
use App\Traits\DispatchesWebhooks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Applicants from first contact to hire.
 *
 * Nothing here decides for a person. Every stage move, the offer approval and
 * the hire are taken by a named user, recorded in the application's history
 * and in the audit log. There is no scoring rule that rejects or advances
 * anyone on its own.
 */
class RecruitmentService
{
    use DispatchesWebhooks;

    public function __construct(
        private readonly EmployeeNumbering $numbering,
        private readonly PlanLimitService $planLimits,
    ) {}

    /** @param array<string, mixed> $data */
    public function receive(Vacancy $vacancy, array $data, User $by): JobApplication
    {
        return DB::transaction(function () use ($vacancy, $data, $by): JobApplication {
            $application = JobApplication::create([
                'vacancy_id' => $vacancy->id,
                'name' => trim((string) $data['name']),
                'email' => $this->email($data['email'] ?? null),
                'phone' => EthiopianPhone::canonicalOrRaw($data['phone'] ?? null),
                'source' => $data['source'],
                'cover_note' => $data['cover_note'] ?? null,
                'stage' => ApplicationStage::APPLIED,
                'created_by' => $by->id,
            ]);

            $this->event($application, 'created', $by, toStage: ApplicationStage::APPLIED->value);
            AuditLog::record('recruitment.application_received', $application, ['vacancy' => $vacancy->public_id]);

            return $application;
        });
    }

    /**
     * Move to a new stage. Moving to `offer` records the offer and leaves it
     * unapproved; closing records the reason.
     *
     * @param  array<string, mixed>  $data
     */
    public function move(JobApplication $application, ApplicationStage $target, array $data, User $by): JobApplication
    {
        return DB::transaction(function () use ($application, $target, $data, $by): JobApplication {
            $application = JobApplication::query()->lockForUpdate()->findOrFail($application->id);
            $from = $application->stage;

            if (! $from->canMoveTo($target)) {
                throw ValidationException::withMessages([
                    'stage' => [__('recruitment.invalid_move', ['from' => $from->value, 'to' => $target->value])],
                ]);
            }

            $application->stage = $target;

            if ($target === ApplicationStage::OFFER) {
                $application->fill([
                    'offer_start_date' => $data['offer_start_date'],
                    'offer_salary_cents' => $data['offer_salary_cents'] ?? null,
                    'offer_notes' => $data['offer_notes'] ?? null,
                    'offer_made_by' => $by->id,
                    'offer_approved_by' => null,
                    'offer_approved_at' => null,
                ]);
            }

            if ($target->isClosed()) {
                $application->closed_at = now();
                $application->closed_reason = $data['note'] ?? null;
            }

            $application->save();

            $this->event($application, 'stage_changed', $by, note: $data['note'] ?? null, fromStage: $from->value, toStage: $target->value);
            AuditLog::record('recruitment.stage_changed', $application, ['from' => $from->value, 'to' => $target->value]);

            return $application;
        });
    }

    /**
     * A note or an interview record. A score is a person's assessment written
     * down, not an input to any automatic decision.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(JobApplication $application, array $data, User $by): JobApplicationEvent
    {
        if ($application->erased_at !== null) {
            throw ValidationException::withMessages(['type' => [__('recruitment.erased')]]);
        }

        $event = $this->event(
            $application,
            $data['type'],
            $by,
            note: $data['note'] ?? null,
            score: $data['score'] ?? null,
            scheduledAt: $data['scheduled_at'] ?? null,
        );

        AuditLog::record('recruitment.'.$data['type'].'_recorded', $application);

        return $event;
    }

    /**
     * Second pair of eyes on the offer: the person who made it cannot approve it.
     */
    public function approveOffer(JobApplication $application, User $by, ?string $note): JobApplication
    {
        return DB::transaction(function () use ($application, $by, $note): JobApplication {
            $application = JobApplication::query()->lockForUpdate()->findOrFail($application->id);

            if ($application->stage !== ApplicationStage::OFFER) {
                throw ValidationException::withMessages(['stage' => [__('recruitment.not_at_offer')]]);
            }
            if ($application->offer_approved_at !== null) {
                return $application;
            }
            if ((int) $application->offer_made_by === $by->id) {
                throw ValidationException::withMessages(['approver' => [__('recruitment.self_approval')]]);
            }

            $application->offer_approved_by = $by->id;
            $application->offer_approved_at = now();
            $application->save();

            $this->event($application, 'offer_approved', $by, note: $note);
            AuditLog::record('recruitment.offer_approved', $application, ['offer_made_by' => $application->offer_made_by]);

            return $application;
        });
    }

    /**
     * Turn an approved offer into one employee record.
     *
     * The application row is locked first, so two requests for the same hire
     * queue on it: the second finds `employee_id` set and gets that employee
     * back with `created = false`. The unique index on `employee_id` is the
     * schema's half of the same rule.
     *
     * @param  array<string, mixed>  $data
     * @return array{employee: Employee, created: bool}
     */
    public function hire(JobApplication $application, array $data, User $by): array
    {
        return DB::transaction(function () use ($application, $data, $by): array {
            $application = JobApplication::query()->lockForUpdate()->findOrFail($application->id);

            if ($application->employee_id !== null) {
                return ['employee' => Employee::query()->findOrFail($application->employee_id), 'created' => false];
            }

            if ($application->stage !== ApplicationStage::OFFER || $application->offer_approved_at === null) {
                throw ValidationException::withMessages(['stage' => [__('recruitment.offer_not_approved')]]);
            }

            $vacancy = $application->vacancy;
            $hired = JobApplication::query()
                ->where('vacancy_id', $application->vacancy_id)
                ->where('stage', ApplicationStage::HIRED->value)
                ->count();
            if ($vacancy !== null && $hired >= $vacancy->positions) {
                throw ValidationException::withMessages(['vacancy' => [__('recruitment.positions_filled', ['positions' => $vacancy->positions])]]);
            }

            $this->refuseCurrentEmployee($application);

            $tenant = app(CurrentTenant::class)->get();
            $this->planLimits->assertCanAdd($tenant, 'employees');

            $status = EmployeeStatus::from($data['status'] ?? EmployeeStatus::HIRED->value);
            $hireDate = $data['hire_date'] ?? $application->offer_start_date?->toDateString();
            $probationEnd = $status === EmployeeStatus::PROBATION ? ($data['probation_end_date'] ?? null) : null;
            if ($probationEnd !== null && $probationEnd <= $hireDate) {
                throw ValidationException::withMessages(['probation_end_date' => [__('recruitment.probation_before_start')]]);
            }

            $employee = $this->numbering->create($tenant, array_filter([
                'name' => $application->name,
                'email' => $application->email,
                'phone' => $application->phone,
                'hire_date' => $hireDate,
                'status' => $status->value,
                'probation_end_date' => $probationEnd,
                'department_id' => $vacancy?->department_id,
                'branch_id' => $vacancy?->branch_id,
                'salary_cents' => $application->offer_salary_cents,
            ], fn ($value) => $value !== null));

            $application->stage = ApplicationStage::HIRED;
            $application->employee_id = $employee->id;
            $application->hired_by = $by->id;
            $application->hired_at = now();
            $application->closed_at = now();
            $application->save();

            $this->event($application, 'hired', $by, fromStage: ApplicationStage::OFFER->value, toStage: ApplicationStage::HIRED->value);
            AuditLog::record('employee.created', $employee, ['source' => 'recruitment', 'application' => $application->public_id]);
            AuditLog::record('recruitment.hired', $application, ['employee' => $employee->public_id]);
            $this->webhook($employee->tenant_id, 'employee.created', ['public_id' => $employee->public_id, 'name' => $employee->name]);

            return ['employee' => $employee, 'created' => true];
        });
    }

    /**
     * Remove a closed application's personal details. The row, its stages and
     * its dates stay, so the vacancy's selection record still adds up; names,
     * contact details and every free-text note go.
     */
    public function erase(JobApplication $application, User $by): JobApplication
    {
        return DB::transaction(function () use ($application, $by): JobApplication {
            $application = JobApplication::query()->lockForUpdate()->findOrFail($application->id);

            if (! in_array($application->stage, [ApplicationStage::REJECTED, ApplicationStage::WITHDRAWN], true)) {
                throw ValidationException::withMessages(['stage' => [__('recruitment.erase_open')]]);
            }
            if ($application->erased_at !== null) {
                return $application;
            }

            $application->fill([
                'name' => __('recruitment.erased_name'),
                'email' => null,
                'phone' => null,
                'cover_note' => null,
                'offer_notes' => null,
                'closed_reason' => null,
                'erased_at' => now(),
            ])->save();

            $application->events()->update(['note' => null]);
            $this->event($application, 'erased', $by);
            AuditLog::record('recruitment.application_erased', $application);

            return $application;
        });
    }

    /**
     * Other applications and current employees with the same e-mail or phone.
     * Shown to HR to decide; never merged, because two people can share a
     * family phone and one person can apply twice.
     *
     * @return list<array{kind: string, public_id: string, name: string, vacancy: string|null}>
     */
    public function possibleDuplicates(JobApplication $application): array
    {
        if ($application->email === null && $application->phone === null) {
            return [];
        }

        $match = function ($query) use ($application): void {
            $query->where(function ($q) use ($application): void {
                if ($application->email !== null) {
                    $q->orWhere('email', $application->email);
                }
                if ($application->phone !== null) {
                    $q->orWhere('phone', $application->phone);
                }
            });
        };

        $applications = JobApplication::query()
            ->with('vacancy:id,title')
            ->whereKeyNot($application->id)
            ->whereNull('erased_at')
            ->where($match)
            ->limit(10)
            ->get()
            ->map(fn (JobApplication $other): array => [
                'kind' => 'application',
                'public_id' => $other->public_id,
                'name' => $other->name,
                'vacancy' => $other->vacancy?->title,
            ]);

        $employees = Employee::query()
            ->where($match)
            ->when($application->employee_id !== null, fn ($q) => $q->whereKeyNot($application->employee_id))
            ->limit(10)
            ->get(['id', 'public_id', 'name'])
            ->map(fn (Employee $employee): array => [
                'kind' => 'employee',
                'public_id' => $employee->public_id,
                'name' => $employee->name,
                'vacancy' => null,
            ]);

        return [...$applications->all(), ...$employees->all()];
    }

    /**
     * A current employee with the same e-mail or phone is very likely the same
     * person, and two records for one person split their attendance and pay.
     * HR resolves it - an internal move is a personnel action, not a hire. A
     * former employee may be hired again.
     */
    private function refuseCurrentEmployee(JobApplication $application): void
    {
        if ($application->email === null && $application->phone === null) {
            return;
        }

        $exists = Employee::query()
            ->whereIn('status', array_map(fn (EmployeeStatus $s) => $s->value, [...EmployeeStatus::current(), EmployeeStatus::SUSPENDED]))
            ->where(function ($q) use ($application): void {
                if ($application->email !== null) {
                    $q->orWhere('email', $application->email);
                }
                if ($application->phone !== null) {
                    $q->orWhere('phone', $application->phone);
                }
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['application' => [__('recruitment.already_employee')]]);
        }
    }

    private function email(mixed $email): ?string
    {
        return is_string($email) && trim($email) !== '' ? Str::lower(trim($email)) : null;
    }

    private function event(
        JobApplication $application,
        string $type,
        User $by,
        ?string $note = null,
        ?string $fromStage = null,
        ?string $toStage = null,
        ?int $score = null,
        mixed $scheduledAt = null,
    ): JobApplicationEvent {
        return JobApplicationEvent::create([
            'job_application_id' => $application->id,
            'type' => $type,
            'from_stage' => $fromStage,
            'to_stage' => $toStage,
            'note' => $note,
            'score' => $score,
            'scheduled_at' => $scheduledAt,
            'actor_id' => $by->id,
        ]);
    }
}
