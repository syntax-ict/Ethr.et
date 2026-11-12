<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Enums\NationalityStatus;
use App\Models\Device;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Device\DeviceManager;
use App\Services\EmployeeNumbering;
use App\Services\EmployeeUpdater;
use App\Services\Identity\EmployeeIntake;
use App\Services\Identity\IdentityMatch;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use App\Services\Identity\IntakeResult;
use App\Services\PlanLimitService;
use App\Support\DepartmentMatch;
use App\Support\EmployeeImportRow;
use App\Support\EthiopianPhone;
use App\Support\RelationMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The workforce-migration workspace: discover people from a source, stage them
 * with a resolver verdict, let a human choose an action per row, then commit.
 *
 * Nothing about a person is created until commit, and commit honours the chosen
 * action — so an ambiguous match is never guessed and a known person is never
 * duplicated. Builds on IdentityResolver (Slice 4) and device enrollment
 * discovery (Slice 5). See ONBOARDING_V2.md decision D6.
 *
 * A row that matched an employee also carries what it would change on their
 * record (StagedChanges): blanks are filled at commit, and a difference is
 * written only where a reviewer accepted it. The edit is EmployeeUpdater's, so
 * it leaves what an edit on the form leaves.
 *
 * A list is committed a slice at a time. One request has thirty seconds on
 * the host; `commitSlice()` works for `SLICE_SECONDS`, records how far it got
 * and says whether anything is left, and the caller asks again.
 */
final class WorkforceMigrationService
{
    /** How long one request keeps committing before it answers. */
    public const SLICE_SECONDS = 12.0;

    /** Rows written per transaction, and per hold of the organisation's lock. */
    public const CHUNK = 50;

    private const NO_TOTALS = ['created' => 0, 'merged' => 0, 'updated' => 0, 'skipped' => 0, 'deferred' => 0];

    /** A new person's own details, staged under the employee column they fill. */
    private const DETAILS = ['name_am', 'national_id', 'tin', 'gender', 'date_of_birth', 'marital_status', 'nationality'];

    /** @var array<string, RelationMatch> branches and positions, read once per organisation */
    private array $relations = [];

    public function __construct(
        private readonly IdentityResolver $resolver,
        private readonly DeviceManager $devices,
        private readonly EmployeeNumbering $numbering,
        private readonly PlanLimitService $planLimits,
        private readonly EmployeeIntake $intake,
        private readonly StagedChanges $changes,
        private readonly EmployeeUpdater $updater,
        private readonly float $sliceSeconds = self::SLICE_SECONDS,
        private readonly int $chunk = self::CHUNK,
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
        $departments = DepartmentMatch::forTenant($device->tenant_id);

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
                // The terminal's number is tried as an employee number; nobody
                // said it is one (IdentitySignals::forDeviceUser).
                'code_is_guess' => true,
            ];

            $this->stageRow($batch, $raw, $enrollment['name'] ?? null, $userId, $departments);
        }

        return $batch->refresh();
    }

    /**
     * Stage rows from a spreadsheet/CSV (or manual entry). Each row is a map of
     * employee fields as EmployeeImportRow::staged() names them (name, email,
     * phone, employee_code, hire_date, department, salary_cents, …).
     *
     * A row that brings an id from another system is staged with the identity
     * it is linked as, so the same id in a later list finds the same person.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function stageFromRows(array $rows, int $tenantId, string $sourceType = 'csv', ?string $sourceRef = null, ?User $creator = null): MigrationBatch
    {
        $batch = $this->createBatch($tenantId, $sourceType, $sourceRef, $creator);
        $departments = DepartmentMatch::forTenant($tenantId);

        foreach ($rows as $row) {
            $row = [
                ...EmployeeImportRow::externalIdentity(isset($row['external_id']) ? (string) $row['external_id'] : null),
                ...$row,
            ];
            $name = isset($row['name']) ? (string) $row['name'] : null;
            $this->stageRow($batch, $row, $name, isset($row['employee_code']) ? (string) $row['employee_code'] : null, $departments);
        }

        return $batch->refresh();
    }

    /**
     * $resolvedEmployee lets a reviewer pick which candidate an "ambiguous" row
     * (no single confident match — {@see IdentityResolver}) actually is, rather
     * than committing being the only way that choice could ever take effect.
     * Passing null leaves any previously-resolved match untouched, so re-saving
     * just the action on an already-decided row doesn't clear it.
     *
     * `$accept` is the differences the reviewer wants written to the employee
     * (StagedChanges): the fields named are accepted and every other one is
     * not. Null leaves what was accepted as it is. Picking another employee
     * starts the list again, since it was drawn up against someone else.
     *
     * @param  list<string>|null  $accept
     */
    public function setAction(MigrationStagingRow $row, string $action, ?Employee $resolvedEmployee = null, ?array $accept = null): MigrationStagingRow
    {
        $attributes = ['action' => $action];
        $changed = $resolvedEmployee !== null && $resolvedEmployee->id !== $row->resolved_employee_id;

        if ($resolvedEmployee !== null) {
            $attributes['resolved_employee_id'] = $resolvedEmployee->id;
        }

        if ($changed) {
            $attributes['changes'] = $this->changes->between(
                $resolvedEmployee,
                $this->rawOf($row),
                DepartmentMatch::forTenant($row->tenant_id),
            );
        }

        if ($accept !== null) {
            $attributes['changes'] = array_map(
                fn (array $change): array => [
                    ...$change,
                    'accepted' => ($change['kind'] ?? null) === StagedChanges::CONFLICT
                        && ($change['taken'] ?? false) !== true
                        && in_array($change['field'] ?? null, $accept, true),
                ],
                $attributes['changes'] ?? $row->changes ?? [],
            );
        }

        $row->update($attributes);

        return $row;
    }

    /**
     * Apply every row's action, to the end of the list. Create-only for new
     * people; a merge links the source's identity and writes the row's fills
     * and accepted differences; skip/defer/pending do nothing. Idempotent: an
     * already-committed batch is returned untouched.
     *
     * For a caller with no time limit. A request uses `commitSlice()`.
     *
     * `$actor` is who the changes to existing employees are made by. With
     * none, people are created and linked and nobody's record is edited.
     *
     * @param  array{status?: string}|null  $options  what the whole list is brought in with (commitSlice)
     * @return array<string, int>
     */
    public function commit(MigrationBatch $batch, ?User $actor = null, ?array $options = null): array
    {
        do {
            $progress = $this->commitSlice($batch, $actor, $options);
        } while (! $progress->done);

        return $progress->totals;
    }

    /**
     * Commit as much of the list as fits in one request, in chunks that are
     * each their own transaction under the organisation's lock. What a chunk
     * wrote stays written: a request that dies loses the chunk it was in and
     * the next one starts from the row after the last that was finished.
     *
     * `$options` is what the reviewer chose for the whole list: the status
     * its new people arrive with. It is kept with the list when the first
     * slice begins and read from there afterwards, so a list carried on from
     * another tab, or by a request that says something else, goes on as it
     * began.
     *
     * @param  array{status?: string}|null  $options
     */
    public function commitSlice(MigrationBatch $batch, ?User $actor = null, ?array $options = null): CommitProgress
    {
        if ($batch->isCommitted()) {
            return new CommitProgress(true, $this->totalsOf($batch), 0);
        }

        if ($options !== null) {
            MigrationBatch::query()
                ->whereKey($batch->getKey())
                ->where('tenant_id', $batch->tenant_id)
                ->where('status', 'reviewing')
                ->update(['options' => json_encode($options)]);
            $batch->refresh();
        }

        $tenant = Tenant::query()->findOrFail($batch->tenant_id);
        $departments = DepartmentMatch::forTenant($batch->tenant_id);
        $started = microtime(true);

        do {
            $progress = $this->commitChunk($batch, $tenant, $departments, $actor);
        } while (! $progress->done && microtime(true) - $started < $this->sliceSeconds);

        $batch->refresh();

        return $progress;
    }

    private function commitChunk(MigrationBatch $batch, Tenant $tenant, DepartmentMatch $departments, ?User $actor): CommitProgress
    {
        // The organisation's seat lock, with the transaction inside it, so the
        // next writer counts what this one committed (A-Z review A-02). It is
        // also the lock every question about "is this person here already?" is
        // asked under (EmployeeIntake).
        return $this->planLimits->serialised($tenant, 'employees', fn (): CommitProgress => DB::transaction(function () use ($batch, $tenant, $departments, $actor): CommitProgress {
            // Two commits of one batch (a second tab, a retry) both passed the
            // check above and created everyone twice. The second now waits
            // here and starts after the first one's last row.
            $locked = MigrationBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isCommitted()) {
                return new CommitProgress(true, $this->totalsOf($locked), 0);
            }

            $cursor = (int) ($locked->commit_cursor ?? 0);

            // The plan's limit, on everyone this list still has to add, before
            // any of them is written: as the file import applies it.
            $this->planLimits->assertCanAdd(
                $tenant,
                'employees',
                $batch->rows()->where('id', '>', $cursor)->where('action', MigrationStagingRow::ACTION_CREATE)->count(),
            );

            $rows = $batch->rows()->where('id', '>', $cursor)->orderBy('id')->limit($this->chunk)->get();
            $totals = $this->totalsOf($locked);

            // Numbers for the people who bring none, read once for the chunk
            // as the file import does. Each used to read every employee code
            // to find the next one.
            $unnumbered = $rows->filter(
                fn (MigrationStagingRow $row): bool => $row->action === MigrationStagingRow::ACTION_CREATE
                    && trim((string) ($this->rawOf($row)['employee_code'] ?? '')) === ''
            )->count();
            $pool = $unnumbered > 0 ? $this->numbering->sequence($tenant, $unnumbered) : [];

            foreach ($rows as $row) {
                $raw = $this->rawOf($row);
                $signals = $this->signalsFromRaw($raw);

                switch ($row->action) {
                    case MigrationStagingRow::ACTION_CREATE:
                        $admitted = $this->admit($row, $raw, $signals, $batch, $tenant, $departments, $pool);
                        $employee = $admitted->record();

                        if ($admitted->created()) {
                            // A reviewer chose this row's action, so the number
                            // goes where they said, even from a confirmed holder.
                            $this->resolver->link($employee, $signals, 1.0, verified: true, reassign: true);
                            $totals['created']++;
                        } else {
                            // The person is here now and was not when the
                            // list was reviewed: an earlier row of this
                            // list, or someone adding them meanwhile. One
                            // record, and this source's number linked to
                            // it, unconfirmed because nobody looked. Their
                            // record is left as it is: nobody was shown what
                            // this row would change on it.
                            $this->resolver->link($employee, $signals, $admitted->match->confidence);
                            $totals['merged']++;
                        }
                        $row->update(['resolved_employee_id' => $employee->id, 'processed_at' => now()]);
                        break;

                    case MigrationStagingRow::ACTION_MERGE:
                        $employee = $this->mergeTarget($row, $batch->tenant_id, $signals);
                        if ($employee !== null) {
                            $this->resolver->link($employee, $signals, $row->match_confidence ?? 1.0, verified: true, reassign: true);
                            if ($this->applyChanges($row, $employee, $raw, $departments, $actor)) {
                                $totals['updated']++;
                            }
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

            $last = $rows->last();
            $cursor = $last instanceof MigrationStagingRow ? (int) $last->getKey() : $cursor;
            $remaining = $batch->rows()->where('id', '>', $cursor)->count();
            $done = $remaining === 0;

            $locked->update([
                'status' => $done ? 'committed' : 'committing',
                'committed_at' => $done ? now() : null,
                'commit_cursor' => $cursor,
                'totals' => $totals,
            ]);

            return new CommitProgress($done, $totals, $remaining);
        }));
    }

    /**
     * What the list has done so far, with every count present.
     *
     * @return array<string, int>
     */
    private function totalsOf(MigrationBatch $batch): array
    {
        $totals = self::NO_TOTALS;
        $stored = $batch->getAttribute('totals');

        foreach (is_array($stored) ? $stored : [] as $key => $value) {
            if (is_string($key) && is_int($value)) {
                $totals[$key] = $value;
            }
        }

        return $totals;
    }

    /**
     * Write a merged row's fills and accepted differences to the employee it
     * matched. True when their record changed.
     *
     * Nothing is written for a deleted record, for someone `$actor` may not
     * edit, or for someone with a change already scheduled for a later date.
     * Salary and TIN need the pay permission as well; without it the rest of
     * the row is still written.
     *
     * The list is drawn up again here, from the employee as they are now. What
     * is stored on the row says only which differences were accepted, and an
     * acceptance holds while both values are the ones the reviewer was shown.
     *
     * @param  array<string, mixed>  $raw
     */
    private function applyChanges(MigrationStagingRow $row, Employee $employee, array $raw, DepartmentMatch $departments, ?User $actor): bool
    {
        if ($actor === null || $employee->trashed()) {
            return false;
        }

        $gate = Gate::forUser($actor);
        if (! $gate->allows('update', $employee) || $this->changes->scheduled($employee)) {
            return false;
        }

        $changes = $this->changes->between($employee, $raw, $departments, $row->changes ?? []);
        $fields = $this->changes->applicable($changes);

        if (array_intersect($fields, StagedChanges::PAY) !== [] && ! $gate->inspect('updateFinancial', $employee)->allowed()) {
            $fields = array_values(array_diff($fields, StagedChanges::PAY));
        }

        $attributes = $this->changes->attributes($employee, $changes, $fields, $departments);
        if ($attributes === []) {
            return false;
        }

        $this->updater->update($employee, $attributes, $actor);

        return true;
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
            ->filter(fn (MigrationStagingRow $row): bool => empty($this->rawOf($row)['hire_date']))
            ->count();
    }

    /**
     * The row as the source gave it. Read via getAttribute so the cast's loose
     * static type does not defeat the guard (the value is an array at runtime).
     *
     * @return array<string, mixed>
     */
    private function rawOf(MigrationStagingRow $row): array
    {
        $raw = $row->getAttribute('raw');

        return is_array($raw) ? $raw : [];
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
    private function stageRow(MigrationBatch $batch, array $raw, ?string $displayName, ?string $externalIdentifier, DepartmentMatch $departments): void
    {
        $match = $this->resolver->resolve($batch->tenant_id, $this->signalsFromRaw($raw));
        $employee = $match->employee;

        MigrationStagingRow::create([
            'tenant_id' => $batch->tenant_id,
            'batch_id' => $batch->id,
            'raw' => $raw,
            'display_name' => $displayName,
            'external_identifier' => $externalIdentifier,
            'match_outcome' => $match->outcome,
            'match_confidence' => $match->confidence,
            'candidates' => $match->candidates,
            'resolved_employee_id' => $employee?->id,
            'action' => $this->suggestedAction($match, $displayName),
            'changes' => $employee !== null && ! $employee->trashed()
                ? $this->changes->between($employee, $raw, $departments)
                : null,
        ]);
    }

    /**
     * The default action for a verdict. A confident match merges; anything
     * uncertain is deferred to a human; a clear new person is created, unless
     * the source gave no name. A device often holds only an enrolment number,
     * and every such row used to become an employee called "Unnamed".
     *
     * A match with someone who has left is deferred too. Linking a terminal's
     * number to a leaver is rarely what the list means: the person is back, or
     * the number was given to someone else, and only a person can say which.
     */
    private function suggestedAction(IdentityMatch $match, ?string $name): string
    {
        return match ($match->outcome) {
            IdentityMatch::MATCHED => $match->isFormerEmployee()
                ? MigrationStagingRow::ACTION_DEFER
                : MigrationStagingRow::ACTION_MERGE,
            IdentityMatch::NEW => trim((string) $name) === ''
                ? MigrationStagingRow::ACTION_DEFER
                : MigrationStagingRow::ACTION_CREATE,
            default => MigrationStagingRow::ACTION_DEFER, // probable, ambiguous, archived
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
            nationalId: isset($raw['national_id']) ? (string) $raw['national_id'] : null,
            sourceType: isset($raw['source_type']) ? (string) $raw['source_type'] : null,
            sourceRef: isset($raw['source_ref']) ? (string) $raw['source_ref'] : null,
            identifierType: isset($raw['identifier_type']) ? (string) $raw['identifier_type'] : null,
            identifierValue: isset($raw['identifier_value']) ? (string) $raw['identifier_value'] : null,
            codeIsGuess: ($raw['code_is_guess'] ?? false) === true,
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
     * A "create" row, asked again under the lock (EmployeeIntake).
     *
     * The verdict a reviewer saw was reached when the list was staged, and the
     * roster has moved since: an earlier row of the same list, the form, a
     * directory. A reviewer's "create" stands over everything they were shown
     * (a likeness, a leaver, a deleted record, and a certain match when that
     * is what the row said at review). What they were not shown is a certain
     * match that appeared afterwards; that row is linked to the person, not
     * made a second time.
     *
     * A department is matched by code or name (DepartmentMatch). A pasted list
     * was checked when it was staged; a device's own department names are
     * not, so one the organisation does not have leaves the person unassigned.
     *
     * The record is made from everything the row brings. A pasted list used
     * to bring six fields and the rest of a person (salary, national id,
     * branch, position) went through the file import instead, which has no
     * review. A device's roster brings a name and a number, as before.
     *
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $pool  the batch's numbers for people who bring none
     */
    private function admit(MigrationStagingRow $row, array $raw, IdentitySignals $signals, MigrationBatch $batch, Tenant $tenant, DepartmentMatch $departments, array &$pool): IntakeResult
    {
        $accept = $row->match_outcome === IdentityMatch::MATCHED
            ? [...IntakeResult::CONFIRMABLE, IntakeResult::MATCHED]
            : IntakeResult::CONFIRMABLE;

        $text = static fn (string $key): ?string => isset($raw[$key]) && trim((string) $raw[$key]) !== '' ? (string) $raw[$key] : null;

        $details = [];
        foreach (self::DETAILS as $column) {
            if ($text($column) !== null) {
                $details[$column] = $text($column);
            }
        }
        if ($text('branch') !== null) {
            $details['branch_id'] = $this->relation('branch', $batch->tenant_id)->id($text('branch'));
        }
        if ($text('position') !== null) {
            $details['position_id'] = $this->relation('position', $batch->tenant_id)->id($text('position'));
        }
        if (isset($raw['salary_cents']) && (int) $raw['salary_cents'] > 0) {
            $details['salary_cents'] = (int) $raw['salary_cents'];
        }
        if ($text('nationality_status') !== null) {
            $details['nationality_status'] = NationalityStatus::fromInput((string) $text('nationality_status'))?->value;
        }

        // EmployeeNumbering gives the next number when the row brings none,
        // as adding one person or importing a file does. The plan's limit was
        // checked for the whole batch before the first row.
        return $this->intake->admit($tenant, [
            'name' => $this->nameOf($raw),
            'email' => $text('email'),
            'phone' => EthiopianPhone::canonicalOrRaw($text('phone')),
            'employee_code' => $text('employee_code'),
            'badge_number' => $text('badge_number'),
            'hire_date' => $text('hire_date') ?? now()->toDateString(),
            'department_id' => $departments->id($text('department')),
            // The line's own status, or the one chosen for the whole list.
            ...EmployeeImportRow::employment($text('status') ?? $batch->arrivalStatus()),
            ...array_filter($details, fn (mixed $value): bool => $value !== null),
        ], $signals, $batch->source_type === 'device' ? EmployeeIntake::VIA_DEVICE : EmployeeIntake::VIA_MIGRATION, $accept, seatChecked: true, pool: $pool);
    }

    private function relation(string $kind, int $tenantId): RelationMatch
    {
        return $this->relations["{$kind}:{$tenantId}"] ??= $kind === 'branch'
            ? RelationMatch::branches($tenantId)
            : RelationMatch::positions($tenantId);
    }
}
