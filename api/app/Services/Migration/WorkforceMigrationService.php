<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Enums\EmployeeStatus;
use App\Enums\NationalityStatus;
use App\Exceptions\DeviceRosterUnavailable;
use App\Exceptions\ImpersonationRestricted;
use App\Models\Device;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ImpersonationToken;
use App\Services\Device\DeviceIdRules;
use App\Services\Device\DeviceManager;
use App\Services\EmployeeContractService;
use App\Services\EmployeeNumbering;
use App\Services\EmployeeRelatedRecords;
use App\Services\EmployeeTransitionService;
use App\Services\EmployeeUpdater;
use App\Services\Identity\EmployeeIntake;
use App\Services\Identity\IdentityMatch;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use App\Services\Identity\IntakeResult;
use App\Services\Identity\SameNameRoster;
use App\Services\PlanLimitService;
use App\Support\DepartmentMatch;
use App\Support\EmployeeImportRow;
use App\Support\EthiopianPhone;
use App\Support\PersonName;
use App\Support\RelationMatch;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

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
 *
 * A list is staged the same way: every row is stored first, unmatched, and
 * matching the people (the resolver costs about 65 ms a row on the host) is
 * done a slice at a time by `beginStaging()` and `stageSlice()`.
 */
final class WorkforceMigrationService
{
    /** How long one request keeps committing before it answers. */
    public const SLICE_SECONDS = 12.0;

    /** Rows written per transaction, and per hold of the organisation's lock. */
    public const CHUNK = 50;

    /** Rows written per insert statement when a list is stored. */
    private const INSERT_CHUNK = 250;

    /** employees.employee_code is a 30 character column. */
    private const EMPLOYEE_CODE_MAX = 30;

    /** The codes of the warnings a held number puts on a row. */
    public const NUMBER_HELD = 'number_held';

    public const BADGE_HELD = 'badge_held';

    /** The warning on a row set to merge into someone whose name shares no word with it. */
    public const MAYBE_OTHER_PERSON = 'maybe_other_person';

    private const NO_TOTALS = ['created' => 0, 'merged' => 0, 'updated' => 0, 'skipped' => 0, 'deferred' => 0];

    /** A new person's own details, staged under the employee column they fill. */
    private const DETAILS = ['name_am', 'national_id', 'tin', 'gender', 'date_of_birth', 'marital_status', 'nationality'];

    /** @var array<string, RelationMatch> branches, positions, grades and cost centres, read once per organisation */
    private array $relations = [];

    public function __construct(
        private readonly IdentityResolver $resolver,
        private readonly DeviceManager $devices,
        private readonly EmployeeNumbering $numbering,
        private readonly PlanLimitService $planLimits,
        private readonly EmployeeIntake $intake,
        private readonly StagedChanges $changes,
        private readonly EmployeeUpdater $updater,
        private readonly EmployeeRelatedRecords $related,
        private readonly EmployeeContractService $contracts,
        private readonly EmployeeTransitionService $transitions,
        private readonly HeldNumbers $heldNumbers,
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
        if (DeviceManager::hasNoRoster($device)) {
            throw DeviceRosterUnavailable::receivesOnly($device);
        }

        $enrollments = $this->devices->adapter($device)->pullEnrollments($device);
        $batch = $this->createBatch($device->tenant_id, 'device', 'device:'.$device->id, $creator);
        $departments = DepartmentMatch::forTenant($device->tenant_id);
        $names = $this->resolver->sameNameRoster($device->tenant_id);
        $held = $this->heldNumbers->among($device->tenant_id, array_column($enrollments, 'device_user_id'), array_column($enrollments, 'device_user_id'));

        foreach ($enrollments as $enrollment) {
            $userId = $enrollment['device_user_id'];
            $card = $enrollment['card_number'] ?? null;

            $raw = [
                'device_user_id' => $userId,
                'name' => $enrollment['name'] ?? null,
                'card_number' => $card,
                'department' => $enrollment['department'] ?? null,
                'employee_code' => $userId,
                // The terminal's own number: a punch carries it, never the card.
                'badge_number' => $userId,
                'source_type' => $device->adapter_type,
                'source_ref' => 'device:'.$device->id,
                'identifier_type' => 'device_user_id',
                'identifier_value' => $userId,
                // The terminal's number is tried as an employee number; nobody
                // said it is one (IdentitySignals::forDeviceUser).
                'code_is_guess' => true,
            ];

            $this->stageRow($batch, $this->flagUnfitDeviceId($raw, $userId), $enrollment['name'] ?? null, $userId, $departments, $names, $held);
        }

        return $batch->refresh();
    }

    /**
     * A terminal's number that cannot be an employee number or a badge number
     * (the columns are 30 and 50 wide, and the badge rule allows 32 letters,
     * digits and hyphens) is left out of those two and flagged on the row, so
     * it is seen at review and not as a failure at commit. It stays the
     * terminal's own number: the identity is linked all the same.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function flagUnfitDeviceId(array $raw, string $userId): array
    {
        $warnings = [];

        if (mb_strlen($userId) > self::EMPLOYEE_CODE_MAX) {
            unset($raw['employee_code']);
            $warnings[] = ['code' => 'device_id_too_long', 'message' => __('onboarding.migration.device_id_too_long', ['value' => $userId, 'max' => self::EMPLOYEE_CODE_MAX])];
        }

        if (! DeviceIdRules::fitsBadge($userId)) {
            unset($raw['badge_number']);
            $warnings[] = ['code' => 'device_id_not_badge', 'message' => __('onboarding.migration.device_id_not_badge', ['value' => $userId])];
        }

        return $warnings === [] ? $raw : [...$raw, 'warnings' => $warnings];
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
    public function stageFromRows(array $rows, int $tenantId, string $sourceType = 'csv', ?string $sourceRef = null, ?User $creator = null, ?string $mode = null): MigrationBatch
    {
        $batch = $this->storeRows($rows, $tenantId, $sourceType, $sourceRef, $creator, $mode);

        // No time limit: for a caller that is not a request.
        $this->resolveChunks($batch, null);

        return $batch->refresh();
    }

    /**
     * Stage a list as a request does: store every row, then match as many as
     * fit in the time the request has left. The rest is matched by
     * `stageSlice()`, asked again until the list is `reviewing`.
     *
     * `$startedAt` is when the request began, not when this method did: the
     * list was read and validated first, and that time is spent.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: MigrationBatch, 1: StageProgress}
     */
    public function beginStaging(array $rows, int $tenantId, float $startedAt, string $sourceType = 'csv', ?string $sourceRef = null, ?User $creator = null, ?string $mode = null): array
    {
        $batch = $this->storeRows($rows, $tenantId, $sourceType, $sourceRef, $creator, $mode);
        $progress = $this->resolveChunks($batch, $startedAt);

        return [$batch->refresh(), $progress];
    }

    /**
     * Match as many of a staging list's people as fit in one request. A list
     * that is no longer `staging` has nothing left to match and answers done.
     */
    public function stageSlice(MigrationBatch $batch, float $startedAt): StageProgress
    {
        $batch->refresh();

        if (! $batch->isStaging()) {
            return new StageProgress(true, $batch->rows()->count(), 0);
        }

        $progress = $this->resolveChunks($batch, $startedAt);
        $batch->refresh();

        return $progress;
    }

    /**
     * Store every row of a list, unmatched, in bulk. Matching a person costs a
     * query or two each, and a request that did it for a long list ran out of
     * time with nothing to show for it; storing a row costs a share of one
     * insert. A bulk insert skips the model, so the id, the timestamps and
     * the encryption the model gives a row are done here, through its own cast.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function storeRows(array $rows, int $tenantId, string $sourceType, ?string $sourceRef, ?User $creator, ?string $mode = null): MigrationBatch
    {
        $batch = $this->createBatch($tenantId, $sourceType, $sourceRef, $creator, MigrationBatch::STAGING, $mode === MigrationBatch::MODE_COMPLETE ? ['mode' => $mode] : null);
        $stamp = now()->toDateTimeString();
        $completing = $mode === MigrationBatch::MODE_COMPLETE;

        foreach (array_chunk(array_values($rows), self::INSERT_CHUNK) as $chunk) {
            $records = [];

            foreach ($chunk as $row) {
                // A list that completes people already here invites nobody,
                // so a sign-in column in it is not kept.
                if ($completing) {
                    unset($row[EmployeeImportRow::SIGN_IN]);
                }

                $row = [
                    ...EmployeeImportRow::externalIdentity(isset($row['external_id']) ? (string) $row['external_id'] : null),
                    ...$row,
                ];

                $stored = (new MigrationStagingRow)->forceFill(['raw' => $row])->getAttributes();

                $records[] = [
                    'public_id' => (string) Str::ulid(),
                    'tenant_id' => $tenantId,
                    'batch_id' => $batch->id,
                    'raw' => $stored['raw'],
                    'display_name' => isset($row['name']) ? (string) $row['name'] : null,
                    'external_identifier' => isset($row['employee_code']) ? (string) $row['employee_code'] : null,
                    'action' => MigrationStagingRow::ACTION_PENDING,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];
            }

            MigrationStagingRow::query()->insert($records);
        }

        return $batch;
    }

    /**
     * Match the rows not yet matched, a chunk at a time, until none are left
     * or `$startedAt` is `sliceSeconds` ago. Every call matches at least one
     * chunk, so a request that is already late still moves the list on. Null
     * for `$startedAt` goes to the end.
     *
     * Matching a row twice (two tabs carrying on at once) writes the same
     * values twice. When none are left the list becomes `reviewing`, once.
     */
    private function resolveChunks(MigrationBatch $batch, ?float $startedAt): StageProgress
    {
        $departments = DepartmentMatch::forTenant($batch->tenant_id);
        $completing = $batch->isCompleting();
        $names = $this->resolver->sameNameRoster($batch->tenant_id);

        do {
            $rows = $batch->rows()->whereNull('match_outcome')->orderBy('id')->limit($this->chunk)->get();

            // The numbers of the chunk, asked once: which another record holds.
            $raws = $rows->map(fn (MigrationStagingRow $row): array => $this->rawOf($row));
            $held = $this->heldNumbers->among($batch->tenant_id, $raws->pluck('employee_code'), $raws->pluck('badge_number'));

            foreach ($rows as $row) {
                // Written only while the row is still unmatched: a request
                // that was slow behind another must not overwrite a decision
                // made on a row the other had already finished.
                $row->forceFill($this->resolvedAttributes($batch->tenant_id, $this->rawOf($row), $row->display_name, $departments, $names, $held, $completing));
                MigrationStagingRow::query()
                    ->whereKey($row->getKey())
                    ->whereNull('match_outcome')
                    ->update([...$row->getDirty(), 'updated_at' => now()]);
            }

            $remaining = $batch->rows()->whereNull('match_outcome')->count();
        } while ($remaining > 0 && $rows->isNotEmpty() && ($startedAt === null || microtime(true) - $startedAt < $this->sliceSeconds));

        if ($remaining === 0) {
            MigrationBatch::query()
                ->whereKey($batch->getKey())
                ->where('tenant_id', $batch->tenant_id)
                ->where('status', MigrationBatch::STAGING)
                ->update(['status' => 'reviewing']);
        }

        return new StageProgress($remaining === 0, $batch->rows()->whereNotNull('match_outcome')->count(), $remaining);
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
                [],
                $row->batch()->first()?->isCompleting() === true,
            );
        }

        if ($accept !== null) {
            $attributes['changes'] = $this->changes->withAccepted($attributes['changes'] ?? $row->changes ?? [], $accept);
        }

        // Who the row merges into, and whether it still looks like them, is
        // asked again whenever the action or the person moves.
        $raw = $this->rawOf($row);
        $flagged = $this->withOtherPersonWarning($raw, $action, $resolvedEmployee ?? $row->resolvedEmployee);
        if ($flagged !== $raw) {
            $attributes['raw'] = $flagged;
        }

        $row->update($attributes);

        return $row;
    }

    /**
     * "These are all them": every row still waiting because someone is
     * already here under the same name, and naming exactly one person, is
     * set to merge into that person. A row naming several people waits for
     * the reviewer to say which, and a row the reviewer has already decided
     * (merge, create, skip) is left as they decided it. Returns how many rows
     * changed.
     *
     * The rows were drawn up against that person when they were staged
     * (sameName()), so nothing is drawn up again: the commit fills their
     * blanks as a merge chosen one row at a time would.
     */
    public function confirmSameName(MigrationBatch $batch): int
    {
        return $batch->rows()
            ->where('match_outcome', MigrationStagingRow::OUTCOME_SAME_NAME)
            ->where('action', MigrationStagingRow::ACTION_DEFER)
            ->whereNull('processed_at')
            ->whereNotNull('resolved_employee_id')
            ->update(['action' => MigrationStagingRow::ACTION_MERGE, 'updated_at' => now()]);
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
     * A list is written in chunks, each its own transaction, so one found to
     * hold bank details half way through would half apply. An operator in a
     * support session does not write where people are paid: the whole list is
     * refused before a row is written, whichever way its bank columns arrived
     * (a grid is mapped only when it is read, so the request cannot say).
     */
    private function refuseBankDataInSupportSession(MigrationBatch $batch): void
    {
        if (! ImpersonationToken::sessionActive()) {
            return;
        }

        $carried = false;
        MigrationStagingRow::query()
            ->where('tenant_id', $batch->tenant_id)
            ->where('batch_id', $batch->id)
            ->whereNotIn('action', [MigrationStagingRow::ACTION_SKIP, MigrationStagingRow::ACTION_DEFER])
            ->chunkById(500, function ($rows) use (&$carried): bool {
                foreach ($rows as $row) {
                    foreach (['bank_name', 'bank_branch', 'bank_account'] as $column) {
                        $value = $row->raw[$column] ?? null;
                        if (is_scalar($value) && trim((string) $value) !== '') {
                            $carried = true;

                            return false;
                        }
                    }
                }

                return true;
            });

        if ($carried) {
            throw new ImpersonationRestricted('WorkforceMigrationService::commitSlice', ['bank_name', 'bank_branch', 'bank_account']);
        }
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

        $this->refuseBankDataInSupportSession($batch);

        if ($options !== null) {
            // What the list was staged with (its mode) is kept beside what the
            // reviewer chose now.
            $stored = $batch->getAttribute('options');

            MigrationBatch::query()
                ->whereKey($batch->getKey())
                ->where('tenant_id', $batch->tenant_id)
                ->where('status', 'reviewing')
                ->update(['options' => json_encode([...(is_array($stored) ? $stored : []), ...$options])]);
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
            $completing = $locked->isCompleting();

            // The plan's limit, on everyone this list still has to add, before
            // any of them is written: as the file import applies it.
            $this->planLimits->assertCanAdd(
                $tenant,
                'employees',
                $completing ? 0 : $batch->rows()->where('id', '>', $cursor)->where('action', MigrationStagingRow::ACTION_CREATE)->count(),
            );

            $rows = $batch->rows()->where('id', '>', $cursor)->orderBy('id')->limit($this->chunk)->get();
            $totals = $this->totalsOf($locked);

            // Numbers for the people who bring none, read once for the chunk
            // as the file import does. Each used to read every employee code
            // to find the next one.
            $unnumbered = $completing ? 0 : $rows->filter(
                fn (MigrationStagingRow $row): bool => $row->action === MigrationStagingRow::ACTION_CREATE
                    && trim((string) ($this->rawOf($row)['employee_code'] ?? '')) === ''
            )->count();
            $pool = $unnumbered > 0 ? $this->numbering->sequence($tenant, $unnumbered) : [];

            // Which numbers another record holds, asked once for the chunk and
            // under the lock, for the rows about to be made. The answer at
            // review was a request or a day ago.
            $making = $completing ? collect() : $rows
                ->filter(fn (MigrationStagingRow $row): bool => $row->action === MigrationStagingRow::ACTION_CREATE)
                ->map(fn (MigrationStagingRow $row): array => $this->rawOf($row));
            $held = $this->heldNumbers->among($batch->tenant_id, $making->pluck('employee_code'), $making->pluck('badge_number'));

            foreach ($rows as $row) {
                $raw = $this->rawOf($row);
                $signals = $this->signalsFromRaw($raw);

                switch ($row->action) {
                    case MigrationStagingRow::ACTION_CREATE:
                        // A list that completes people adds nobody, whatever a row says.
                        if ($completing) {
                            $totals['skipped']++;
                            break;
                        }

                        $made = false;
                        $admitted = $this->admit($row, $raw, $signals, $batch, $tenant, $departments, $pool, $held);

                        // The number is another record's. Nobody is made, and
                        // the row is left for review as it was staged.
                        if ($admitted === null) {
                            $totals['deferred']++;
                            break;
                        }

                        $employee = $admitted->record();

                        if ($admitted->created()) {
                            // A reviewer chose this row's action, so the number
                            // goes where they said, even from a confirmed holder.
                            $this->resolver->link($employee, $signals, 1.0, verified: true, reassign: true);
                            $this->writeProfile($employee, $raw, $actor);
                            $totals['created']++;
                            $made = true;
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
                        $row->update(['resolved_employee_id' => $employee->id, 'processed_at' => now(), 'created_employee' => $made]);
                        break;

                    case MigrationStagingRow::ACTION_MERGE:
                        $employee = $this->mergeTarget($row, $batch->tenant_id, $signals);
                        if ($employee !== null) {
                            $this->resolver->link($employee, $signals, $row->match_confidence ?? 1.0, verified: true, reassign: true);
                            if ($this->applyChanges($row, $employee, $raw, $departments, $actor, $completing)) {
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
    private function applyChanges(MigrationStagingRow $row, Employee $employee, array $raw, DepartmentMatch $departments, ?User $actor, bool $completing = false): bool
    {
        if ($actor === null || $employee->trashed()) {
            return false;
        }

        $gate = Gate::forUser($actor);
        if (! $gate->allows('update', $employee) || $this->changes->scheduled($employee)) {
            return false;
        }

        $changes = $this->changes->between($employee, $raw, $departments, $row->changes ?? [], $completing);
        $fields = $this->changes->applicable($changes);

        if (array_intersect($fields, StagedChanges::PAY) !== [] && ! $gate->inspect('updateFinancial', $employee)->allowed()) {
            $fields = array_values(array_diff($fields, StagedChanges::PAY));
        }

        // Moving someone through the statuses is its own permission, which
        // editing them does not imply.
        if (in_array(StagedChanges::STATUS, $fields, true) && ! $gate->allows('transition', $employee)) {
            $fields = array_values(array_diff($fields, [StagedChanges::STATUS]));
        }

        $wrote = false;

        $attributes = $this->changes->attributes($employee, $changes, $fields, $departments);
        if ($attributes !== []) {
            $this->updater->update($employee, $attributes, $actor);
            $wrote = true;
        }

        if (array_intersect(StagedChanges::RECORDS, $fields) !== []) {
            $this->writeRecords($employee, $raw, $fields, $actor);
            $wrote = true;
        }

        // A person still hired is moved by the service every other status
        // change goes through, so the move has its record and its audit entry.
        if (in_array(StagedChanges::STATUS, $fields, true)) {
            $to = EmployeeStatus::tryFrom(mb_strtolower(trim(is_scalar($raw['status'] ?? null) ? (string) $raw['status'] : '')));
            if ($to !== null && $employee->status === EmployeeStatus::HIRED && $employee->status->canTransitionTo($to)) {
                $this->transitions->transition($employee, $to, EmployeeTransitionService::today(), null, $actor->id);
                $wrote = true;
            }
        }

        return $wrote;
    }

    /**
     * The records a list adds for a person who has none: the bank account
     * (primary), the emergency contact and the contract. Written in the
     * chunk's transaction, through the services their own screens use.
     *
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $fields  the changes being applied
     */
    private function writeRecords(Employee $employee, array $raw, array $fields, User $actor): void
    {
        $text = static fn (string $key): ?string => isset($raw[$key]) && trim((string) $raw[$key]) !== '' ? trim((string) $raw[$key]) : null;

        if (in_array('contract', $fields, true)) {
            $this->contracts->create($employee, [
                'contract_type' => $text('contract_type'),
                'start_date' => $employee->hire_date->toDateString(),
                'end_date' => $text('contract_end_date'),
            ], $actor->id);
        }

        if (in_array('bank', $fields, true)) {
            $this->related->addBankDetail($employee, [
                'bank_name' => (string) $text('bank_name'),
                'branch_name' => $text('bank_branch'),
                'account_number' => (string) $text('bank_account'),
                'is_primary' => true,
            ]);
        }

        if (in_array('emergency_contact', $fields, true)) {
            $this->related->addEmergencyContact($employee, [
                'name' => (string) $text('emergency_name'),
                'relationship' => (string) $text('emergency_relationship'),
                'phone' => (string) $text('emergency_phone'),
            ]);
        }
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
     * because the source gave none, and who are still employees: someone
     * archived since, by an undo for one, is not counted. A device never gives
     * a date. Owner decision (2026-10-28): warn, and leave the date for HR to
     * correct.
     */
    public function hireDatesDefaulted(MigrationBatch $batch): int
    {
        $ids = $batch->rows()
            ->where('action', MigrationStagingRow::ACTION_CREATE)
            ->whereNotNull('processed_at')
            ->whereNotNull('resolved_employee_id')
            ->get()
            ->filter(fn (MigrationStagingRow $row): bool => empty($this->rawOf($row)['hire_date']))
            ->pluck('resolved_employee_id')
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return 0;
        }

        return Employee::query()
            ->where('tenant_id', $batch->tenant_id)
            ->whereIn('id', $ids)
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

    /** @param  array<string, string>|null  $options */
    private function createBatch(int $tenantId, string $sourceType, ?string $sourceRef, ?User $creator, string $status = 'reviewing', ?array $options = null): MigrationBatch
    {
        return MigrationBatch::create([
            'tenant_id' => $tenantId,
            'created_by' => $creator?->id,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'status' => $status,
            'options' => $options,
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function stageRow(MigrationBatch $batch, array $raw, ?string $displayName, ?string $externalIdentifier, DepartmentMatch $departments, SameNameRoster $names, HeldSet $held): void
    {
        MigrationStagingRow::create([
            'tenant_id' => $batch->tenant_id,
            'batch_id' => $batch->id,
            'raw' => $raw,
            'display_name' => $displayName,
            'external_identifier' => $externalIdentifier,
            ...$this->resolvedAttributes($batch->tenant_id, $raw, $displayName, $departments, $names, $held),
        ]);
    }

    /**
     * What the resolver and the staged-changes comparison say about a row:
     * the one place a row is matched, whether as it is stored (a device) or
     * afterwards (a list).
     *
     * A row the resolver calls new is then looked for by its full name
     * (sameName()). One that is found is held, not added.
     *
     * Whatever the verdict, a row whose employee number another record holds
     * cannot be added as a new person (flagHeldNumbers()).
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function resolvedAttributes(int $tenantId, array $raw, ?string $displayName, DepartmentMatch $departments, SameNameRoster $names, HeldSet $held, bool $completing = false): array
    {
        $attributes = $this->verdictAttributes($tenantId, $raw, $displayName, $departments, $names, $completing);

        return $completing ? $attributes : $this->flagHeldNumbers($attributes, is_array($attributes['raw'] ?? null) ? $attributes['raw'] : $raw, $held);
    }

    /**
     * An employee number is unique over every record the organisation keeps,
     * archived ones included, so a person cannot be made with a number
     * another record holds: the insert would fail, in the middle of a commit.
     * The row says so, and a row that would have been added is left for review
     * instead. A row that merges into someone is not touched: the number is
     * theirs. A badge number is not unique, but the form refuses one another
     * record holds, so a list that brings one adds the person without it.
     *
     * The warning is stored with what it needs and no more: who holds the
     * number, and whether they are archived. What the viewer is told, and
     * whether the holder is named to them, is decided when the row is read
     * (MigrationController::warningsPayload).
     *
     * @param  array<string, mixed>  $attributes  the row's verdict
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function flagHeldNumbers(array $attributes, array $raw, HeldSet $held): array
    {
        $kept = array_values(array_filter(
            is_array($raw['warnings'] ?? null) ? $raw['warnings'] : [],
            static fn (mixed $warning): bool => is_array($warning) && ! in_array($warning['code'] ?? null, [self::NUMBER_HELD, self::BADGE_HELD], true),
        ));
        $warnings = $kept;

        if (($attributes['action'] ?? null) !== MigrationStagingRow::ACTION_MERGE) {
            $holder = $held->code($raw['employee_code'] ?? null);
            if ($holder !== null) {
                $warnings[] = self::heldWarning(self::NUMBER_HELD, $raw['employee_code'], $holder);

                if (($attributes['action'] ?? null) === MigrationStagingRow::ACTION_CREATE) {
                    $attributes['action'] = MigrationStagingRow::ACTION_DEFER;
                }
            }

            $holder = $held->badge($raw['badge_number'] ?? null);
            if ($holder !== null) {
                $warnings[] = self::heldWarning(self::BADGE_HELD, $raw['badge_number'], $holder);
            }
        }

        if ($warnings === ($raw['warnings'] ?? [])) {
            return $attributes;
        }

        return [...$attributes, 'raw' => [...$raw, 'warnings' => $warnings]];
    }

    /**
     * @param  array{id: int, archived: bool}  $holder
     * @return array<string, mixed>
     */
    private static function heldWarning(string $code, mixed $value, array $holder): array
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return [
            'code' => $code,
            'value' => $value,
            'holder' => $holder['archived'] ? 'archived' : 'current',
            'holder_id' => $holder['id'],
            'message' => __($holder['archived'] ? "onboarding.migration.{$code}_archived" : "onboarding.migration.{$code}_current", ['value' => $value]),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function verdictAttributes(int $tenantId, array $raw, ?string $displayName, DepartmentMatch $departments, SameNameRoster $names, bool $completing): array
    {
        $match = $this->resolver->resolve($tenantId, $this->signalsFromRaw($raw));

        if ($match->outcome === IdentityMatch::NEW) {
            $held = $this->sameName($tenantId, $raw, $departments, $names, $match->confidence, $completing);
            if ($held !== null) {
                return $held;
            }
        }

        $employee = $match->employee;
        $action = $completing && $match->outcome === IdentityMatch::NEW
            ? MigrationStagingRow::ACTION_SKIP
            : $this->suggestedAction($match, $displayName);

        $attributes = [
            'match_outcome' => $match->outcome,
            'match_confidence' => $match->confidence,
            'candidates' => $match->candidates,
            'resolved_employee_id' => $employee?->id,
            // Nobody is added by a list that completes people: a row that
            // matches no one is left out, and the reviewer is told so.
            'action' => $action,
            'changes' => $employee !== null && ! $employee->trashed()
                ? $this->changes->between($employee, $raw, $departments, [], $completing)
                : null,
        ];

        $flagged = $this->withOtherPersonWarning($raw, $action, $employee);

        return $flagged === $raw ? $attributes : [...$attributes, 'raw' => $flagged];
    }

    /**
     * A row set to merge into someone whose name shares no word with it may be
     * someone else: a list whose numbers are shifted by a line matches every
     * row to the wrong person by number. Name is compared with name and the
     * Amharic name with the Amharic name (the same script, PersonName), and
     * only when a pair can be compared at all; one pair that shares a word is
     * enough to clear the row.
     *
     * The row is left out of accepting differences for many rows at once and
     * decided on its own. The warning is recomputed whenever the action or the
     * person it names changes, and removed when it no longer applies.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed> the row's raw details, with the warning as it now stands
     */
    private function withOtherPersonWarning(array $raw, string $action, ?Employee $target): array
    {
        $warnings = array_values(array_filter(
            is_array($raw['warnings'] ?? null) ? $raw['warnings'] : [],
            static fn (mixed $warning): bool => is_array($warning) && ($warning['code'] ?? null) !== self::MAYBE_OTHER_PERSON,
        ));

        if ($action === MigrationStagingRow::ACTION_MERGE && $target !== null && ! $target->trashed() && $this->maybeOtherPerson($raw, $target)) {
            $warnings[] = [
                'code' => self::MAYBE_OTHER_PERSON,
                'message' => __('onboarding.migration.maybe_other_person'),
            ];
        }

        if ($warnings === ($raw['warnings'] ?? [])) {
            return $raw;
        }

        return $warnings === [] ? array_diff_key($raw, ['warnings' => true]) : [...$raw, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function maybeOtherPerson(array $raw, Employee $target): bool
    {
        $known = [$target->name, $target->name_am];
        $text = static fn (string $key): ?string => isset($raw[$key]) && is_scalar($raw[$key]) ? (string) $raw[$key] : null;

        $compared = [PersonName::overlap($text('name'), $known), PersonName::overlap($text('name_am'), $known)];

        return in_array(false, $compared, true) && ! in_array(true, $compared, true);
    }

    /**
     * Accept the differences of the named fields on every merge row that may
     * take them, a slice at a time, for a reviewer who would otherwise open
     * each row. Nothing is written to any employee: the acceptance is stored
     * on the row as a per-row one is (StagedChanges::withAccepted), and the
     * commit still writes a field only while both values are those shown.
     *
     * Stateless between requests: it walks rows in id order after `$cursor`,
     * for `sliceSeconds` and a chunk at a time (at least one chunk), and says
     * where it stopped. The caller sends the cursor back until `done`.
     *
     * Rows it passes over, and why they are counted:
     *  - `held`: a row that may be someone else (MAYBE_OTHER_PERSON) is left
     *    for the reviewer to decide on its own row;
     *  - `outOfReach`: the actor may not edit the person, nothing is changed;
     *  - `payRefused`: the row lost its TIN or salary, for want of the pay
     *    permission, and kept the rest.
     * Each is counted only for a row that had a difference to accept among
     * the fields named.
     *
     * @param  list<string>  $fields  the fields to accept, by staged name
     */
    public function acceptDifferences(MigrationBatch $batch, User $actor, array $fields, int $cursor = 0): AcceptProgress
    {
        $fields = array_values(array_intersect($fields, StagedChanges::acceptable($batch->isCompleting())));
        $gate = Gate::forUser($actor);
        $started = microtime(true);
        $rows = $outOfReach = $payRefused = $held = 0;
        $byField = [];

        do {
            $chunk = $batch->rows()
                ->where('id', '>', $cursor)
                ->whereNull('processed_at')
                ->where('action', MigrationStagingRow::ACTION_MERGE)
                ->whereNotNull('resolved_employee_id')
                ->orderBy('id')
                ->limit($this->chunk)
                ->get();

            $targets = Employee::query()
                ->where('tenant_id', $batch->tenant_id)
                ->whereIn('id', $chunk->pluck('resolved_employee_id')->all())
                ->get()
                ->keyBy('id');

            foreach ($chunk as $row) {
                $cursor = (int) $row->getKey();
                $stored = is_array($row->changes) ? $row->changes : [];

                $open = [];
                foreach ($stored as $change) {
                    if (($change['kind'] ?? null) === StagedChanges::CONFLICT
                        && ($change['taken'] ?? false) !== true
                        && ($change['accepted'] ?? false) !== true
                        && in_array($change['field'] ?? null, $fields, true)) {
                        $open[] = (string) $change['field'];
                    }
                }

                if ($open === []) {
                    continue;
                }

                if ($this->hasWarning($row, self::MAYBE_OTHER_PERSON)) {
                    $held++;

                    continue;
                }

                $target = $targets->get($row->resolved_employee_id);
                if (! $target instanceof Employee) {
                    continue;
                }

                if (! $gate->allows('update', $target)) {
                    $outOfReach++;

                    continue;
                }

                if (array_intersect($open, StagedChanges::PAY) !== [] && ! $gate->inspect('updateFinancial', $target)->allowed()) {
                    $open = array_values(array_diff($open, StagedChanges::PAY));
                    $payRefused++;

                    if ($open === []) {
                        continue;
                    }
                }

                $accepted = [];
                foreach ($stored as $change) {
                    if (($change['accepted'] ?? false) === true) {
                        $accepted[] = (string) $change['field'];
                    }
                }

                $row->update(['changes' => $this->changes->withAccepted($stored, [...$accepted, ...$open])]);

                $rows++;
                foreach ($open as $field) {
                    $byField[$field] = ($byField[$field] ?? 0) + 1;
                }
            }

            $more = $chunk->count() === $this->chunk;
        } while ($more && microtime(true) - $started < $this->sliceSeconds);

        return new AcceptProgress(! $more, $cursor, $rows, $byField, $outOfReach, $payRefused, $held);
    }

    private function hasWarning(MigrationStagingRow $row, string $code): bool
    {
        $raw = $this->rawOf($row);

        foreach (is_array($raw['warnings'] ?? null) ? $raw['warnings'] : [] as $warning) {
            if (is_array($warning) && ($warning['code'] ?? null) === $code) {
                return true;
            }
        }

        return false;
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
     * A row nobody matched on anything a person has only one of, looked for by
     * its full name. On 2026-11-18 a list of 167 people was brought in a
     * second time and every one of them was added again: it carried no
     * national ID, phone or email, and a name alone is worth too little to the
     * resolver to say "may be this person".
     *
     * Someone already here under exactly this name, in the department the row
     * would put them in, holds the row as `same_name`, for review:
     *
     *  - one such person: the row is drawn up against them as a likely match
     *    is, with what it would fill in. "This is them" merges into them;
     *  - several: each is listed and the reviewer says which, if any.
     *
     * Either way it waits (`defer`), so committing the list as it stands adds
     * nobody twice. "A different person" is `create`, which commit honours:
     * the resolver still calls them new. Null when nobody has the name.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private function sameName(int $tenantId, array $raw, DepartmentMatch $departments, SameNameRoster $names, float $confidence, bool $completing): ?array
    {
        $department = isset($raw['department']) && is_scalar($raw['department']) ? trim((string) $raw['department']) : '';
        $ids = $names->holders(
            isset($raw['name']) && is_scalar($raw['name']) ? (string) $raw['name'] : null,
            $department !== '',
            $departments->id($department),
        );

        $found = $this->resolver->sameNameCandidates($tenantId, $ids);
        if ($found === []) {
            return null;
        }

        $employee = count($found) === 1 ? $found[0]['employee'] : null;

        return [
            'match_outcome' => MigrationStagingRow::OUTCOME_SAME_NAME,
            'match_confidence' => $confidence,
            'candidates' => array_map(fn (array $one): array => $one['candidate'], $found),
            'resolved_employee_id' => $employee?->id,
            'action' => MigrationStagingRow::ACTION_DEFER,
            'changes' => $employee !== null
                ? $this->changes->between($employee, $raw, $departments, [], $completing)
                : null,
        ];
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
     * An employee number another record holds, archived or current, cannot be
     * given to a new person, whatever the reviewer chose: null, and nobody is
     * made. The one exception is a row that would not be made at all but
     * linked to the person who turned up since the review, as before. A badge
     * number another record holds is left off the new person, as the form
     * would refuse it. If the insert still meets the unique index (a record
     * made a moment ago, by a request this one could not see), it fails inside
     * a savepoint of its own, so the chunk's transaction goes on, and the row is
     * left the same way.
     *
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $pool  the batch's numbers for people who bring none
     */
    private function admit(MigrationStagingRow $row, array $raw, IdentitySignals $signals, MigrationBatch $batch, Tenant $tenant, DepartmentMatch $departments, array &$pool, HeldSet $held): ?IntakeResult
    {
        $accept = $row->match_outcome === IdentityMatch::MATCHED
            ? [...IntakeResult::CONFIRMABLE, IntakeResult::MATCHED]
            : IntakeResult::CONFIRMABLE;

        $text = static fn (string $key): ?string => isset($raw[$key]) && trim((string) $raw[$key]) !== '' ? (string) $raw[$key] : null;

        if ($held->code($text('employee_code')) !== null) {
            $asked = $this->intake->ask($tenant, $signals);

            return $asked->outcome === IntakeResult::MATCHED && ! in_array(IntakeResult::MATCHED, $accept, true) ? $asked : null;
        }

        $badge = $text('badge_number');
        $badgeHeld = $held->badge($badge);

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
        if ($text('grade') !== null) {
            $details['grade_id'] = $this->relation('grade', $batch->tenant_id)->id($text('grade'));
        }
        if ($text('cost_center') !== null) {
            $details['cost_center_id'] = $this->relation('cost_center', $batch->tenant_id)->id($text('cost_center'));
        }
        if ($text('probation_end_date') !== null) {
            $details['probation_end_date'] = $text('probation_end_date');
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
        $attributes = [
            'name' => $this->nameOf($raw),
            'email' => $text('email'),
            'phone' => EthiopianPhone::canonicalOrRaw($text('phone')),
            'employee_code' => $text('employee_code'),
            'badge_number' => $badgeHeld === null ? $badge : null,
            'hire_date' => $text('hire_date') ?? now()->toDateString(),
            'department_id' => $departments->id($text('department')),
            // The line's own status, or the one chosen for the whole list.
            ...EmployeeImportRow::employment($text('status') ?? $batch->arrivalStatus()),
            ...array_filter($details, fn (mixed $value): bool => $value !== null),
        ];
        $via = $batch->source_type === 'device' ? EmployeeIntake::VIA_DEVICE : EmployeeIntake::VIA_MIGRATION;

        try {
            $admitted = DB::transaction(function () use ($tenant, $attributes, $signals, $via, $accept, &$pool): IntakeResult {
                return $this->intake->admit($tenant, $attributes, $signals, $via, $accept, seatChecked: true, pool: $pool);
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'employee_code')) {
                throw $e;
            }

            return null;
        }

        if ($admitted->created() && $badge !== null) {
            if ($badgeHeld === null) {
                $held->addBadge($badge, $admitted->record()->id);
            } else {
                $this->noteHeldBadge($row, $raw, $badge, $badgeHeld);
            }
        }

        return $admitted;
    }

    /**
     * The row keeps a warning that its badge number is another record's, for a
     * list staged before the number was taken or one that never said so.
     *
     * @param  array<string, mixed>  $raw
     * @param  array{id: int, archived: bool}  $holder
     */
    private function noteHeldBadge(MigrationStagingRow $row, array $raw, ?string $badge, array $holder): void
    {
        $warnings = is_array($raw['warnings'] ?? null) ? $raw['warnings'] : [];

        foreach ($warnings as $warning) {
            if (is_array($warning) && ($warning['code'] ?? null) === self::BADGE_HELD) {
                return;
            }
        }

        $row->update(['raw' => [...$raw, 'warnings' => [...$warnings, self::heldWarning(self::BADGE_HELD, $badge, $holder)]]]);
    }

    /**
     * The rest of a new person's profile, written in the transaction of the
     * chunk that made them: a contract, a bank account, an emergency contact.
     * Each is written through the service its own screen uses, so what the
     * list brings is what the profile would hold had it been typed.
     *
     * The list was read against every rule of these records before it was
     * staged. One that still fails here throws, and the chunk is rolled back
     * with the person: nobody is left without the records their row brought,
     * and nothing the row says is dropped.
     *
     * The contract starts on the hire date and states no salary: a contract
     * that carries one overwrites the employee's (EmployeeContractService),
     * and the salary column stays the one source of pay.
     *
     * @param  array<string, mixed>  $raw
     */
    private function writeProfile(Employee $employee, array $raw, ?User $actor): void
    {
        $text = static fn (string $key): ?string => isset($raw[$key]) && trim((string) $raw[$key]) !== '' ? trim((string) $raw[$key]) : null;

        $type = $text('contract_type');
        if ($type !== null) {
            $this->contracts->create($employee, [
                'contract_type' => $type,
                'start_date' => $employee->hire_date->toDateString(),
                'end_date' => $text('contract_end_date'),
            ], $actor?->id);
        }

        if ($text('bank_name') !== null && $text('bank_account') !== null) {
            $this->related->addBankDetail($employee, [
                'bank_name' => $text('bank_name'),
                'branch_name' => $text('bank_branch'),
                'account_number' => $text('bank_account'),
                'is_primary' => true,
            ]);
        }

        if ($text('emergency_name') !== null && $text('emergency_relationship') !== null && $text('emergency_phone') !== null) {
            $this->related->addEmergencyContact($employee, [
                'name' => $text('emergency_name'),
                'relationship' => $text('emergency_relationship'),
                'phone' => $text('emergency_phone'),
            ]);
        }
    }

    private function relation(string $kind, int $tenantId): RelationMatch
    {
        return $this->relations["{$kind}:{$tenantId}"] ??= match ($kind) {
            'branch' => RelationMatch::branches($tenantId),
            'grade' => RelationMatch::grades($tenantId),
            'cost_center' => RelationMatch::costCenters($tenantId),
            default => RelationMatch::positions($tenantId),
        };
    }
}
