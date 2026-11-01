<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Enums\EmployeeStatus;
use App\Models\Device;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Models\User;
use App\Services\Device\DeviceManager;
use App\Services\Identity\IdentityMatch;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use App\Support\DepartmentMatch;
use App\Support\EthiopianPhone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The workforce-migration workspace: discover people from a source, stage them
 * with a resolver verdict, let a human choose an action per row, then commit.
 *
 * Nothing about a person is created until commit, and commit honours the chosen
 * action — so an ambiguous match is never guessed and a known person is never
 * duplicated. Builds on IdentityResolver (Slice 4) and device enrollment
 * discovery (Slice 5). See ONBOARDING_V2.md decision D6.
 */
final class WorkforceMigrationService
{
    public function __construct(
        private readonly IdentityResolver $resolver,
        private readonly DeviceManager $devices,
    ) {}

    /**
     * Stage everyone enrolled on a device, each resolved against existing
     * employees, with a suggested action.
     */
    public function stageFromDevice(Device $device, ?User $creator = null): MigrationBatch
    {
        // Read the device before creating the batch. A read that fails throws
        // DeviceRequestFailed, and a batch created first would be left behind
        // empty — which is what a down device used to produce, as a batch
        // indistinguishable from a device with nobody enrolled.
        $enrollments = $this->devices->adapter($device)->pullEnrollments($device);
        $batch = $this->createBatch($device->tenant_id, 'device', 'device:'.$device->id, $creator);

        foreach ($enrollments as $enrollment) {
            $userId = $enrollment['device_user_id'];
            $card = $enrollment['card_number'] ?? null;

            $raw = [
                'device_user_id' => $userId,
                'name' => $enrollment['name'] ?? null,
                'card_number' => $card,
                'department' => $enrollment['department'] ?? null,
                'employee_code' => $userId,
                'badge_number' => $card ?? $userId,
                'source_type' => $device->adapter_type,
                'source_ref' => 'device:'.$device->id,
                'identifier_type' => 'device_user_id',
                'identifier_value' => $userId,
            ];

            $this->stageRow($batch, $raw, $enrollment['name'] ?? null, $userId);
        }

        return $batch->refresh();
    }

    /**
     * Stage rows from a spreadsheet/CSV (or manual entry). Each row is a map of
     * employee fields (name, email, phone, employee_code, hire_date, …).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function stageFromRows(array $rows, int $tenantId, string $sourceType = 'csv', ?string $sourceRef = null, ?User $creator = null): MigrationBatch
    {
        $batch = $this->createBatch($tenantId, $sourceType, $sourceRef, $creator);

        foreach ($rows as $row) {
            $name = isset($row['name']) ? (string) $row['name'] : null;
            $this->stageRow($batch, $row, $name, isset($row['employee_code']) ? (string) $row['employee_code'] : null);
        }

        return $batch->refresh();
    }

    /**
     * $resolvedEmployee lets a reviewer pick which candidate an "ambiguous" row
     * (no single confident match — {@see IdentityResolver}) actually is, rather
     * than committing being the only way that choice could ever take effect.
     * Passing null leaves any previously-resolved match untouched, so re-saving
     * just the action on an already-decided row doesn't clear it.
     */
    public function setAction(MigrationStagingRow $row, string $action, ?Employee $resolvedEmployee = null): MigrationStagingRow
    {
        $attributes = ['action' => $action];

        if ($resolvedEmployee !== null) {
            $attributes['resolved_employee_id'] = $resolvedEmployee->id;
        }

        $row->update($attributes);

        return $row;
    }

    /**
     * Apply every row's action. Create-only for new people, link-only for
     * merges; skip/defer/pending do nothing. Idempotent: an already-committed
     * batch is returned untouched.
     *
     * @return array<string, int>
     */
    public function commit(MigrationBatch $batch): array
    {
        if ($batch->isCommitted()) {
            return $batch->totals ?? [];
        }

        $totals = ['created' => 0, 'merged' => 0, 'skipped' => 0, 'deferred' => 0];

        $departments = DepartmentMatch::forTenant($batch->tenant_id);

        DB::transaction(function () use ($batch, $departments, &$totals): void {
            foreach ($batch->rows()->get() as $row) {
                // Read via getAttribute so the array-cast's loose static type does
                // not defeat the guard (the value is an array at runtime).
                $rawAttr = $row->getAttribute('raw');
                $raw = is_array($rawAttr) ? $rawAttr : [];
                $signals = $this->signalsFromRaw($raw);

                switch ($row->action) {
                    case MigrationStagingRow::ACTION_CREATE:
                        $employee = $this->createEmployee($raw, $batch->tenant_id, $departments);
                        $this->resolver->link($employee, $signals, 1.0, verified: true);
                        $row->update(['resolved_employee_id' => $employee->id, 'processed_at' => now()]);
                        $totals['created']++;
                        break;

                    case MigrationStagingRow::ACTION_MERGE:
                        $employee = $this->mergeTarget($row, $batch->tenant_id, $signals);
                        if ($employee !== null) {
                            $this->resolver->link($employee, $signals, $row->match_confidence ?? 1.0, verified: true);
                            $row->update(['resolved_employee_id' => $employee->id, 'processed_at' => now()]);
                            $totals['merged']++;
                        } else {
                            $totals['deferred']++;
                        }
                        break;

                    case MigrationStagingRow::ACTION_SKIP:
                        $totals['skipped']++;
                        break;

                    default: // defer, pending
                        $totals['deferred']++;
                        break;
                }
            }

            $batch->update([
                'status' => 'committed',
                'committed_at' => now(),
                'totals' => $totals,
            ]);
        });

        return $totals;
    }

    /**
     * The employees a committed batch created or merged into, with their
     * logins, in the batch's order.
     *
     * @return Collection<int, Employee>
     */
    public function employees(MigrationBatch $batch): Collection
    {
        $ids = $batch->rows()
            ->whereNotNull('processed_at')
            ->whereNotNull('resolved_employee_id')
            ->orderBy('id')
            ->pluck('resolved_employee_id');

        $employees = Employee::query()
            ->where('tenant_id', $batch->tenant_id)
            ->whereIn('id', $ids->all())
            ->with('user')
            ->get()
            ->keyBy('id');

        return $ids->unique()->map(fn ($id) => $employees->get($id))->filter()->values();
    }

    /**
     * How many people this batch created with today as their hire date,
     * because the source gave none. A device never does. Owner decision
     * (2026-10-28): warn, and leave the date for HR to correct.
     */
    public function hireDatesDefaulted(MigrationBatch $batch): int
    {
        return $batch->rows()
            ->where('action', MigrationStagingRow::ACTION_CREATE)
            ->whereNotNull('processed_at')
            ->get()
            ->filter(function (MigrationStagingRow $row): bool {
                $raw = $row->getAttribute('raw');

                return ! is_array($raw) || empty($raw['hire_date']);
            })
            ->count();
    }

    private function createBatch(int $tenantId, string $sourceType, ?string $sourceRef, ?User $creator): MigrationBatch
    {
        return MigrationBatch::create([
            'tenant_id' => $tenantId,
            'created_by' => $creator?->id,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'status' => 'reviewing',
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function stageRow(MigrationBatch $batch, array $raw, ?string $displayName, ?string $externalIdentifier): void
    {
        $match = $this->resolver->resolve($batch->tenant_id, $this->signalsFromRaw($raw));

        MigrationStagingRow::create([
            'tenant_id' => $batch->tenant_id,
            'batch_id' => $batch->id,
            'raw' => $raw,
            'display_name' => $displayName,
            'external_identifier' => $externalIdentifier,
            'match_outcome' => $match->outcome,
            'match_confidence' => $match->confidence,
            'candidates' => $match->candidates,
            'resolved_employee_id' => $match->employee?->id,
            'action' => $this->suggestedAction($match->outcome, $displayName),
        ]);
    }

    /**
     * The default action for a verdict. A confident match merges; anything
     * uncertain is deferred to a human; a clear new person is created, unless
     * the source gave no name. A device often holds only an enrolment number,
     * and every such row used to become an employee called "Unnamed".
     */
    private function suggestedAction(string $outcome, ?string $name): string
    {
        return match ($outcome) {
            IdentityMatch::MATCHED => MigrationStagingRow::ACTION_MERGE,
            IdentityMatch::NEW => trim((string) $name) === ''
                ? MigrationStagingRow::ACTION_DEFER
                : MigrationStagingRow::ACTION_CREATE,
            default => MigrationStagingRow::ACTION_DEFER, // probable, ambiguous
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function signalsFromRaw(array $raw): IdentitySignals
    {
        return new IdentitySignals(
            employeeCode: isset($raw['employee_code']) ? (string) $raw['employee_code'] : null,
            badgeNumber: isset($raw['badge_number']) ? (string) $raw['badge_number'] : null,
            email: isset($raw['email']) ? (string) $raw['email'] : null,
            phone: isset($raw['phone']) ? (string) $raw['phone'] : null,
            name: isset($raw['name']) ? (string) $raw['name'] : null,
            sourceType: isset($raw['source_type']) ? (string) $raw['source_type'] : null,
            sourceRef: isset($raw['source_ref']) ? (string) $raw['source_ref'] : null,
            identifierType: isset($raw['identifier_type']) ? (string) $raw['identifier_type'] : null,
            identifierValue: isset($raw['identifier_value']) ? (string) $raw['identifier_value'] : null,
        );
    }

    /**
     * The employee a merge should attach to: the row's resolved match if set,
     * otherwise re-resolve now (the roster may have changed since staging).
     */
    private function mergeTarget(MigrationStagingRow $row, int $tenantId, IdentitySignals $signals): ?Employee
    {
        if ($row->resolved_employee_id !== null) {
            // States `tenant_id` rather than trusting the staging column alone.
            // `resolved_employee_id` is written during staging and can be stale
            // by the time a merge runs; the scope is dropped because a merge can
            // run from a queued context with no resolved tenant. The `$tenantId`
            // argument is the same one the `resolve()` branch below already
            // scopes by, so the two halves of this method now agree — they did
            // not before, which is the shape BASELINE.md §11g was opened on.
            return Employee::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->find($row->resolved_employee_id);
        }

        return $this->resolver->resolve($tenantId, $signals)->employee;
    }

    /**
     * The person's name, or one that tells nameless device users apart: they
     * were all "Unnamed".
     *
     * @param  array<string, mixed>  $raw
     */
    private function nameOf(array $raw): string
    {
        $name = trim(isset($raw['name']) ? (string) $raw['name'] : '');
        if ($name !== '') {
            return $name;
        }

        $id = $raw['device_user_id'] ?? $raw['badge_number'] ?? $raw['employee_code'] ?? '?';

        return __('onboarding.migration.device_user', ['id' => (string) $id]);
    }

    /**
     * A department is matched by code or name (DepartmentMatch). A pasted list
     * was checked when it was staged; a device's own department names are
     * not, so one the organisation does not have leaves the person unassigned.
     *
     * @param  array<string, mixed>  $raw
     */
    private function createEmployee(array $raw, int $tenantId, DepartmentMatch $departments): Employee
    {
        return Employee::create([
            'tenant_id' => $tenantId,
            'name' => $this->nameOf($raw),
            'email' => isset($raw['email']) ? (string) $raw['email'] : null,
            'phone' => EthiopianPhone::canonicalOrRaw(isset($raw['phone']) ? (string) $raw['phone'] : null),
            'employee_code' => isset($raw['employee_code']) ? (string) $raw['employee_code'] : null,
            'badge_number' => isset($raw['badge_number']) ? (string) $raw['badge_number'] : null,
            'hire_date' => isset($raw['hire_date']) ? (string) $raw['hire_date'] : now()->toDateString(),
            'department_id' => $departments->id(isset($raw['department']) ? (string) $raw['department'] : null),
            'status' => EmployeeStatus::HIRED,
        ]);
    }
}
