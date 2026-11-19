<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Enums\OnboardingStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Migration\CommitMigrationRequest;
use App\Http\Requests\Migration\ReadPeopleRequest;
use App\Http\Requests\Migration\StageRowsRequest;
use App\Http\Requests\Migration\UpdateStagingRowRequest;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\ImportMapping;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Models\OnboardingProgress;
use App\Models\PersonnelAction;
use App\Services\CurrentTenant;
use App\Services\Invitations\EmployeeLogins;
use App\Services\Migration\MigrationTerminals;
use App\Services\Migration\SignInChoices;
use App\Services\Migration\StagedChanges;
use App\Services\Migration\StageProgress;
use App\Services\Migration\WorkforceMigrationService;
use App\Support\EmployeeImportRow;
use App\Support\PeopleRead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The workforce-migration workspace (OnboardingStep::WORKFORCE_MIGRATION).
 * Stage people from a device or a spreadsheet, review the resolver's verdicts,
 * set an action per row, then commit. See ONBOARDING_V2.md decision D6.
 */
class MigrationController extends Controller
{
    public function __construct(
        private readonly WorkforceMigrationService $migration,
        private readonly CurrentTenant $currentTenant,
        private readonly EmployeeLogins $logins,
        private readonly MigrationTerminals $terminals,
        private readonly SignInChoices $signIn,
    ) {}

    public function stageFromDevice(Device $device): JsonResponse
    {
        Gate::authorize('employee.create');

        $batch = $this->migration->stageFromDevice($device, request()->user());

        AuditLog::record('migration.staged', $batch, ['source' => 'device', 'device_id' => $device->public_id]);

        return response()->json($this->batchPayload($batch), 201);
    }

    /**
     * Read a list of people without staging it.
     *
     * `grid` is the list as lines of cells, the first line its column names
     * or none, with a title or a date above it when the file has one.
     * `header_line` names the line of the file that holds the column names
     * (0: there are none); left out, the line that looks most like them is
     * used, and the answer says which. The answer says how many people are
     * ready, which columns were read and which column names mean nothing
     * here, and every line that cannot be staged, with its number as the
     * reader counts it. Nothing is kept.
     *
     * A list from another system names its columns its own way. Send
     * `mapping`, a list of `{header, column}` saying what each of its column
     * names is (null leaves one out), or `source`, a saved source whose
     * column names are already matched. `headers` describes the first line
     * for that: each name as written, the column it is read as, and for one
     * that is not read, what it might be. `suggested_source` is a saved
     * source whose column names this list has; `needs_name` says the columns
     * were matched and none of them is the name.
     */
    public function read(ReadPeopleRequest $request): JsonResponse
    {
        Gate::authorize('employee.create');

        $read = $request->people();
        abort_if($read === null, 422, __('general.not_found', ['resource' => 'Tenant']));

        $suggested = $request->columnMapping() === null ? $this->sourceFor($read) : null;

        return response()->json([
            'people' => count($read->rows),
            'header' => $read->header,
            'header_line' => $read->headerLine,
            'columns' => $read->columns,
            'ignored' => $read->ignored,
            'calendar' => $read->calendar,
            'calendar_from_header' => $read->calendarFromHeader,
            'without_hire_date' => $read->withoutHireDate,
            'problems' => $read->problems,
            'warnings' => $read->warnings,
            'headers' => $read->headers,
            'header_likely' => $read->headerLikely,
            'needs_name' => $read->needsName,
            'source' => $request->importSource()?->public_id,
            'suggested_source' => $suggested?->public_id,
        ]);
    }

    /**
     * The saved source this list looks like it came from: one that knows
     * which of these columns is the name, and knows more of them than any
     * other does. This month's export may have a column fewer or one more
     * than the one the source was saved from, so it need not know them all.
     * A list ETHR reads whole needs no source and is offered none.
     */
    private function sourceFor(PeopleRead $read): ?ImportMapping
    {
        $unread = array_filter($read->headers, fn (array $header): bool => $header['column'] === null);
        if ($unread === [] || ($read->header === false && ! $read->headerLikely)) {
            return null;
        }

        $present = array_map(fn (array $header): string => EmployeeImportRow::headerKey($header['header']), $read->headers);
        $best = null;
        $most = 0;

        foreach (ImportMapping::query()->orderBy('name')->limit(ImportMapping::LIMIT)->get() as $source) {
            $known = array_intersect_key(
                array_filter($source->map(), fn (?string $column): bool => $column !== null),
                array_flip($present),
            );
            if (! in_array('name', $known, true) || count($known) <= $most) {
                continue;
            }

            $best = $source;
            $most = count($known);
        }

        return $best;
    }

    /**
     * Stage a list of people for review.
     *
     * Send `grid` (the list as lines of cells, read as `/migration/read`
     * reads it) or `rows` (people already keyed by field). A grid with a line
     * that cannot be staged is refused whole, each line named, unless
     * `skip_problems` is set: then those lines are left out. A list that sets
     * a salary, a TIN or a bank account needs the pay permission, as the
     * add-employee form does.
     *
     * `source` and `mapping` say how the list names its columns, as for
     * `/migration/read`. With a `source`, an id from the other system is kept
     * against that source, so the same id in its next list is the same person.
     *
     * Every person is stored at once and matched against the people already
     * here a slice at a time. A short list is matched before this answers and
     * the answer is the whole list for review. A long one answers with its
     * `public_id`, `status` `staging`, how many are `staged` and how many
     * `remaining`; the same request to `/migration/batches/{batch}/stage` is
     * sent again until `status` is `reviewing`, and then the list is read
     * from `/migration/batches/{batch}`.
     */
    public function stageFromRows(StageRowsRequest $request): JsonResponse
    {
        $startedAt = (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));

        Gate::authorize('employee.create');

        $tenantId = $this->currentTenant->id();
        abort_if($tenantId === null, 422, __('general.not_found', ['resource' => 'Tenant']));

        $rows = $request->staged();
        foreach ($rows as $row) {
            if (array_intersect(StagedChanges::PAY, array_keys($row)) !== []) {
                Gate::authorize('employee.updateFinancial');
                break;
            }
        }

        [$batch, $progress] = $this->migration->beginStaging(
            $rows,
            $tenantId,
            $startedAt,
            $request->string('source_type', 'csv')->value(),
            $request->importSource() === null ? $request->input('source_ref') : $request->sourceName(),
            $request->user(),
            $request->mode(),
        );

        AuditLog::record('migration.staged', $batch, ['source' => $batch->source_type, 'rows' => count($rows), 'mode' => $batch->mode()]);

        if (! $progress->done) {
            return response()->json($this->stagingPayload($batch, $progress), 201);
        }

        return response()->json($this->batchPayload($batch), 201);
    }

    /**
     * Carry on matching a list's people.
     *
     * Send it again while `status` is `staging`; `staged` and `remaining`
     * count the people matched and not yet matched. When `status` is
     * `reviewing` the list is ready: read it from `/migration/batches/{batch}`.
     * This answer is never the list itself, which is slow to build for a
     * long one. Asking for a list that is already ready changes nothing.
     */
    public function stage(Request $request, MigrationBatch $batch): JsonResponse
    {
        $startedAt = (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));

        Gate::authorize('employee.create');

        $progress = $this->migration->stageSlice($batch, $startedAt);

        return response()->json($this->stagingPayload($batch, $progress));
    }

    /**
     * @return array{public_id: string, status: string, staged: int, remaining: int}
     */
    private function stagingPayload(MigrationBatch $batch, StageProgress $progress): array
    {
        return [
            'public_id' => $batch->public_id,
            'status' => $batch->status,
            'staged' => $progress->staged,
            'remaining' => $progress->remaining,
        ];
    }

    /**
     * The organisation's lists that are not finished: still being read
     * (`staging`), waiting for review (`reviewing`) or part way through being
     * added (`committing`), newest first, at most twenty. A list that has been
     * added is not here; it is kept for a month but there is nothing left to
     * do with it.
     *
     * Each is named by what it is, not by who is in it: `rows` is how many
     * lines it holds, `mode` is `add` or `complete`, `created_by_name` is
     * null when whoever started it has gone, and `mine` says the viewer
     * started it. Any member of the organisation who may view employees can
     * open any of these, as `/migration/batches/{batch}` already allows.
     *
     * @response array{data: list<array{public_id: string, status: string, mode: string, source_type: string, rows: int, created_at: string|null, updated_at: string|null, created_by_name: string|null, mine: bool}>}
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('employee.viewAny');

        $batches = MigrationBatch::query()
            ->whereIn('status', MigrationBatch::UNFINISHED)
            ->withCount('rows')
            ->with('creator:id,employee_id,display_name', 'creator.employee:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(MigrationBatch::IN_PROGRESS_SHOWN)
            ->get();

        $viewer = $request->user()?->id;

        return response()->json([
            'data' => $batches->map(fn (MigrationBatch $batch): array => [
                'public_id' => $batch->public_id,
                'status' => $batch->status,
                'mode' => $batch->mode(),
                'source_type' => $batch->source_type,
                'rows' => (int) $batch->getAttribute('rows_count'),
                'created_at' => $batch->created_at?->format('c'),
                'updated_at' => $batch->updated_at?->format('c'),
                'created_by_name' => $this->creatorName($batch),
                'mine' => $viewer !== null && $batch->created_by === $viewer,
            ])->all(),
        ]);
    }

    /**
     * Who started the list, as the review would name them: their employee
     * record, else the name an account without one carries. An account is
     * never named by its email address here: the list is shown to anyone who
     * may view employees.
     */
    private function creatorName(MigrationBatch $batch): ?string
    {
        $creator = $batch->creator;
        $employee = $creator?->employee;
        $name = $employee instanceof Employee ? $employee->name : $creator?->display_name;

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    /**
     * Discard a list nobody will finish: the list and its lines are removed.
     *
     * Only a list that has not begun to be added can go. Once its first slice
     * has run, people are written and the list is their record of how they
     * arrived, so it answers 409. The status is read again with the row
     * locked, so a commit that starts a moment earlier is waited for and then
     * seen, and one that starts a moment later finds the list gone.
     */
    public function destroy(MigrationBatch $batch): Response
    {
        Gate::authorize('employee.create');

        DB::transaction(function () use ($batch): void {
            $locked = MigrationBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            abort_if($locked->status === 'committing', 409, __('onboarding.migration.discard_committing'));
            abort_if($locked->isCommitted(), 409, __('onboarding.migration.discard_committed'));

            $rows = $locked->rows()->count();

            // Before the delete, which takes the subject with it; the entry
            // keeps the list's own id so it can still be told which one.
            AuditLog::record('migration.discarded', $locked, [
                'batch' => $locked->public_id,
                'source' => $locked->source_type,
                'mode' => $locked->mode(),
                'status' => $locked->status,
                'rows' => $rows,
            ]);

            $locked->delete();
        });

        return response()->noContent();
    }

    public function show(MigrationBatch $batch): JsonResponse
    {
        Gate::authorize('employee.viewAny');

        return response()->json($this->batchPayload($batch));
    }

    public function updateRow(UpdateStagingRowRequest $request, MigrationStagingRow $row): JsonResponse
    {
        Gate::authorize('employee.create');

        $employeePublicId = $request->validated('employee_public_id');
        // Employee::query() carries the tenant global scope, so a public_id from
        // another tenant (the FormRequest's `exists` rule can't see the scope —
        // see its comment) simply resolves to null here instead of leaking across.
        $employee = $employeePublicId !== null
            ? Employee::where('public_id', $employeePublicId)->first()
            : null;

        // A list still being matched has no verdicts to decide on yet, and one
        // that has begun to be added is past review: a decision changed now
        // would reach the rows not yet written and miss the rest.
        $batch = $row->batch()->firstOrFail();
        abort_if($batch->isStaging(), 409, __('onboarding.migration.still_staging'));
        abort_unless($batch->isReviewing(), 409, __('onboarding.migration.already_committing'));

        // A list that completes people adds nobody.
        abort_if($batch->isCompleting() && $request->validated('action') === MigrationStagingRow::ACTION_CREATE, 422, __('onboarding.migration.complete_adds_nobody'));

        // Accepting a difference is deciding to edit that employee, so it
        // takes what editing them takes: employee.update with reach over the
        // person, and the pay permission for salary or TIN. Without them the
        // row can still be linked; only the edit is refused.
        $accept = $request->validated('accept');
        $target = $employee ?? $row->resolvedEmployee;
        if (is_array($accept) && $accept !== []) {
            abort_if($target === null, 422, __('onboarding.migration.accept_needs_match'));
            // Decided by the field and the list's mode, not by what is stored on
            // the row, which the same request may be about to redraw.
            abort_if(array_diff($accept, StagedChanges::acceptable($batch->isCompleting())) !== [], 422, __('onboarding.migration.accept_locked'));
            Gate::authorize('update', $target);
            if (array_intersect($accept, StagedChanges::PAY) !== []) {
                Gate::authorize('updateFinancial', $target);
            }
        }

        $this->migration->setAction($row, $request->validated('action'), $employee, is_array($accept) ? array_values($accept) : null);

        $row = $row->refresh()->load('resolvedEmployee');

        return response()->json($this->rowPayload($row, $this->scheduledAmong([$row]), $this->candidateCodes([$row])));
    }

    /**
     * Add the list's people, as much as fits in one request.
     *
     * A long list is added a slice at a time. While `status` is `committing`,
     * `remaining` rows are still to be dealt with and the same request is
     * sent again; nothing is added twice. `totals` counts the whole list so
     * far, and `updated` is how many existing employees had details filled in
     * or changed.
     *
     * `status` is what the list's new people arrive as: `hired` when nothing
     * is sent, or `probation` or `confirmed` for people who already work
     * here. A line that names its own status keeps it. It is read when the
     * list begins to be added; a later request cannot change it.
     */
    public function commit(CommitMigrationRequest $request, MigrationBatch $batch): JsonResponse
    {
        Gate::authorize('employee.create');

        // Half a list is not committed: rows not yet matched are all "pending".
        abort_if($batch->isStaging(), 409, __('onboarding.migration.still_staging'));

        $progress = $this->migration->commitSlice($batch, $request->user(), $request->options());
        $totals = $this->totalsPayload($progress->totals);

        if ($progress->done) {
            AuditLog::record('migration.committed', $batch->refresh(), ['totals' => $totals]);

            // The other guided steps record themselves; this one did not, so the
            // sidebar's setup progress never counted a committed migration.
            OnboardingProgress::firstOrCreate(
                ['tenant_id' => $batch->tenant_id],
                ['current_step' => 1, 'completed_steps' => [], 'step_data' => []]
            )->markStepComplete(OnboardingStep::WORKFORCE_MIGRATION->value, ['totals' => $totals]);
        }

        return response()->json([
            'message' => __('general.updated', ['resource' => 'Migration']),
            'totals' => $totals,
            'status' => $batch->status,
            'remaining' => $progress->remaining,
            ...$this->loginsPayload($batch, $progress->done),
        ]);
    }

    /**
     * Every count, named, so the published contract says what `totals` holds.
     *
     * @param  array<string, int>  $totals
     * @return array{created: int, merged: int, updated: int, skipped: int, deferred: int}
     */
    private function totalsPayload(array $totals): array
    {
        return [
            'created' => (int) ($totals['created'] ?? 0),
            'merged' => (int) ($totals['merged'] ?? 0),
            'updated' => (int) ($totals['updated'] ?? 0),
            'skipped' => (int) ($totals['skipped'] ?? 0),
            'deferred' => (int) ($totals['deferred'] ?? 0),
        ];
    }

    /**
     * Who among a committed batch's people cannot sign in yet, and how each
     * would be invited, for the "give them a way to sign in" step after a
     * migration. Also how many were given today as their hire date because
     * the source had none.
     */
    public function logins(MigrationBatch $batch): JsonResponse
    {
        Gate::authorize('employee.viewAny');

        return response()->json($this->loginsPayload($batch));
    }

    /**
     * What the attendance terminals hold for the people a committed batch
     * brought in: per device of their branches, how many were sent, are
     * waiting, were refused or were not sent for their number, and for a
     * terminal ETHR cannot write to, who to enrol by hand.
     *
     * Device information needs `device.viewAny`; without it the answer is the
     * empty one with `visible` false. Someone the viewer may not open is
     * neither named nor counted. `held` lists, for a viewer who may assign a
     * badge (`attendance.manage`), the held badges that are now exactly one of
     * those people; it is empty for anyone else.
     *
     * @response array{visible: bool, devices: list<array{device_public_id: string, device_name: string, adapter_type: string, writable: bool, receives_only: bool, queued: int, done: int, failed: int, not_sent: int, last_error: string|null, enrol_by_hand: list<array{employee_public_id: string, name: string, device_user_id: string}>}>, without_number: int, without_branch: int, can_assign: bool, held: list<array{device_public_id: string, device_name: string, badge: string, punches: int, employee_public_id: string, employee_name: string}>, held_more: int}
     */
    public function terminals(MigrationBatch $batch): JsonResponse
    {
        Gate::authorize('employee.viewAny');

        if (! Gate::allows('device.viewAny')) {
            return response()->json($this->terminals->hidden());
        }

        $people = $this->migration->employees($batch)
            ->filter(fn (Employee $employee): bool => Gate::allows('view', $employee))
            ->values();

        return response()->json($this->terminals->read($people, $batch->tenant_id, Gate::allows('attendance.manage')));
    }

    /**
     * @return array{without_login: list<array{employee_public_id: string, name: string, email: string|null, phone: string|null, channel: string, invited: bool, asked: bool|null}>, hire_dates_defaulted: int}
     */
    private function loginsPayload(MigrationBatch $batch, bool $finished = true): array
    {
        // Until the whole list is added the answer would be about part of
        // it, and reading it costs two queries a slice for nothing.
        $people = $finished ? $this->migration->employees($batch) : new Collection;
        $asked = $finished ? $this->signIn->byPublicId($people, $this->signIn->asked($batch)) : [];

        return [
            'without_login' => $this->logins->pending($people, $asked),
            'hire_dates_defaulted' => $finished ? $this->migration->hireDatesDefaulted($batch) : 0,
        ];
    }

    private function batchPayload(MigrationBatch $batch): array
    {
        $rows = $batch->rows()->with('resolvedEmployee')->get();
        $scheduled = $this->scheduledAmong($rows->all());
        $codes = $this->candidateCodes($rows->all());

        return [
            'public_id' => $batch->public_id,
            'source_type' => $batch->source_type,
            'source_ref' => $batch->source_ref,
            'status' => $batch->status,
            'mode' => $batch->mode(),
            'arrival_status' => $batch->arrivalStatus(),
            'totals' => $batch->totals,
            'summary' => $this->summarize($batch),
            'sign_in_plan' => $this->signIn->plan($batch, $rows),
            'rows' => $rows->map(fn (MigrationStagingRow $row) => $this->rowPayload($row, $scheduled, $codes))->all(),
        ];
    }

    /**
     * @param  list<int>  $scheduled  employees with a change waiting for its date
     * @param  array<string, string|null>  $codes  candidates' employee numbers (candidateCodes)
     */
    private function rowPayload(MigrationStagingRow $row, array $scheduled = [], array $codes = []): array
    {
        $processedAt = $row->getAttribute('processed_at');
        $employee = $row->resolvedEmployee;
        $mayView = $employee !== null && Gate::allows('view', $employee);

        return [
            'public_id' => $row->public_id,
            'display_name' => $row->display_name,
            'external_identifier' => $row->external_identifier,
            'match_outcome' => $row->match_outcome,
            'match_confidence' => $row->match_confidence,
            'candidates' => $this->candidatesPayload($row, $codes),
            'resolved_employee_public_id' => $employee?->public_id,
            'resolved_employee_code' => $mayView ? $this->codeOf($employee) : null,
            'action' => $row->action,
            'changes' => $this->changesPayload($row, $employee, $mayView),
            'warnings' => $this->warningsPayload($row),
            'can_apply_changes' => $this->mayEdit($employee),
            'scheduled_action' => $this->isScheduled($employee, $scheduled),
            'processed_at' => $processedAt instanceof \DateTimeInterface ? $processedAt->format('c') : null,
        ];
    }

    /**
     * What staging noted about the row: a number that did not fit, each as a
     * code and a message. Read from the row's own details.
     *
     * @return list<array{code: string, message: string}>
     */
    private function warningsPayload(MigrationStagingRow $row): array
    {
        $raw = $row->getAttribute('raw');
        $warnings = is_array($raw) && is_array($raw['warnings'] ?? null) ? $raw['warnings'] : [];

        return array_values(array_map(
            fn (mixed $w): array => ['code' => (string) ($w['code'] ?? ''), 'message' => (string) ($w['message'] ?? '')],
            array_filter($warnings, 'is_array'),
        ));
    }

    /**
     * The row's shortlist. A row held because someone is already here under
     * the same name (`same_name`) also gives each person's employee number,
     * which is how a reviewer tells two of them apart; it is null for anyone
     * the viewer may not open, as `resolved_employee_code` is.
     *
     * @param  array<string, string|null>  $codes
     * @return list<array{employee_public_id: string, employee_name: string, score: float, reasons: list<string>, employee_code?: string|null}>|null
     */
    private function candidatesPayload(MigrationStagingRow $row, array $codes): ?array
    {
        $candidates = $row->getAttribute('candidates');
        if (! is_array($candidates)) {
            return null;
        }

        $sameName = $row->match_outcome === MigrationStagingRow::OUTCOME_SAME_NAME;
        $payload = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $id = (string) ($candidate['employee_public_id'] ?? '');
            $one = [
                'employee_public_id' => $id,
                'employee_name' => (string) ($candidate['employee_name'] ?? ''),
                'score' => (float) ($candidate['score'] ?? 0),
                'reasons' => array_values(array_map(strval(...), is_array($candidate['reasons'] ?? null) ? $candidate['reasons'] : [])),
            ];

            if ($sameName) {
                $one['employee_code'] = $codes[$id] ?? null;
            }

            $payload[] = $one;
        }

        return $payload;
    }

    /**
     * The employee numbers of the people `same_name` rows name, for those the
     * viewer may open. One query for the list.
     *
     * @param  array<int, MigrationStagingRow>  $rows
     * @return array<string, string|null>
     */
    private function candidateCodes(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $candidates = $row->getAttribute('candidates');
            if ($row->match_outcome !== MigrationStagingRow::OUTCOME_SAME_NAME || ! is_array($candidates)) {
                continue;
            }
            foreach ($candidates as $candidate) {
                if (is_array($candidate) && is_string($candidate['employee_public_id'] ?? null)) {
                    $ids[] = $candidate['employee_public_id'];
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $codes = [];
        foreach (Employee::query()->whereIn('public_id', array_values(array_unique($ids)))->get() as $employee) {
            $codes[(string) $employee->public_id] = Gate::allows('view', $employee) ? $this->codeOf($employee) : null;
        }

        return $codes;
    }

    /** The matched employee's number, to be shown only to a viewer who may open that person's record. */
    private function codeOf(?Employee $employee): ?string
    {
        $code = $employee?->getAttribute('employee_code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    /** The viewer may edit the employee this row matched. */
    private function mayEdit(?Employee $employee): bool
    {
        return $employee !== null && Gate::allows('update', $employee);
    }

    /**
     * @param  list<int>  $scheduled
     */
    private function isScheduled(?Employee $employee, array $scheduled): bool
    {
        return $employee !== null && in_array($employee->id, $scheduled, true);
    }

    /**
     * What committing the row would change on the employee it matched, for
     * the review screen.
     *
     * A national id, a TIN and a salary are named and never shown, either
     * side. What ETHR holds today is shown only to someone who may open that
     * employee's record; anyone else sees that the field differs.
     *
     * @return list<array{field: string, kind: string, current: string|null, incoming: string|null, masked: bool, accepted: bool, taken: bool}>
     */
    private function changesPayload(MigrationStagingRow $row, ?Employee $employee, bool $mayView): array
    {
        if ($employee === null) {
            return [];
        }

        // A move the reviewer may not make is not promised: the commit would
        // drop it, so it is shown as left alone.
        $mayMove = Gate::allows('transition', $employee);
        $payload = [];

        foreach ($row->changes ?? [] as $change) {
            $field = (string) ($change['field'] ?? '');
            $masked = in_array($field, StagedChanges::MASKED, true);
            $current = $change['current'] ?? null;
            $incoming = $change['incoming'] ?? null;

            $payload[] = [
                'field' => $field,
                'kind' => $field === StagedChanges::STATUS && ! $mayMove ? StagedChanges::LOCKED : (string) ($change['kind'] ?? ''),
                'current' => $masked || ! $mayView || ! is_string($current) ? null : $current,
                'incoming' => $masked || ! is_string($incoming) ? null : $incoming,
                'masked' => $masked,
                'accepted' => ($change['accepted'] ?? false) === true,
                'taken' => ($change['taken'] ?? false) === true,
            ];
        }

        return $payload;
    }

    /**
     * Which of these rows' employees have a personnel action waiting for its
     * date. One query for the list, not one per row.
     *
     * @param  array<int, MigrationStagingRow>  $rows
     * @return list<int>
     */
    private function scheduledAmong(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (MigrationStagingRow $row): ?int => $row->resolved_employee_id,
            $rows,
        ))));

        if ($ids === []) {
            return [];
        }

        return PersonnelAction::query()
            ->whereNull('applied_at')
            ->whereIn('employee_id', $ids)
            ->pluck('employee_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, int> */
    private function summarize(MigrationBatch $batch): array
    {
        return $batch->rows()
            ->get()
            ->groupBy('match_outcome')
            ->map(fn ($group) => $group->count())
            ->all();
    }
}
