<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Enums\ContractStatus;
use App\Enums\EmployeeStatus;
use App\Exceptions\InvalidEmployeeTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\BulkArchiveRequest;
use App\Http\Requests\Employee\BulkRestoreRequest;
use App\Http\Requests\Employee\BulkUpdateRequest;
use App\Http\Requests\Employee\EmployeeIdsRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\Access\PositionAccess;
use App\Services\Employees\EmployeeArchiver;
use App\Services\Employees\EmployeeRestorer;
use App\Services\EmployeeTransitionService;
use App\Services\EmployeeUpdater;
use App\Support\Csv;
use App\Support\EmployeeGaps;
use App\Support\EmployeeListFilters;
use App\Support\EmployeeRelations;
use App\Support\SupervisorChain;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class EmployeeBulkController extends Controller
{
    /** The most people `GET /employees/ids` names. A longer match is capped and says so. */
    public const IDS_CAP = 2000;

    /**
     * The job fields a bulk update may set, and the relation each is read back
     * through for the audit entry and the access check.
     */
    private const JOB_RELATIONS = [
        'position_id' => 'position',
        'team_id' => 'team',
        'cost_center_id' => 'costCenter',
        'supervisor_id' => 'supervisor',
    ];

    public function __construct(
        private readonly EmployeeTransitionService $transitions,
        private readonly EmployeeUpdater $updater,
    ) {}

    /**
     * Move employees to a department or branch, set their job title, team,
     * cost centre or supervisor, or change their status.
     *
     * The selection is all or nothing: an id outside the requester's org scope
     * refuses the whole request with 422 before anything is written, with the
     * message an id that exists nowhere gets. The changes are per employee: a
     * status change goes through the same transition rules as
     * `POST /employees/{employee}/transition`, the job fields go through the
     * same `EmployeeUpdater` as `PUT /employees/{employee}` (so a job title
     * moves the account's access by the same rule, limited to what the person
     * making the change could do by hand), and `results` reports each id as
     * `updated`, `unchanged` (already as asked) or `failed`, with the reason.
     * A supervisor who is the person themselves, or who would make a loop, is
     * a `failed` for that person only. `access_held` says the person's login
     * follows its job title and could not be moved to the new title's role by
     * the person making the change, so it waits on the Users page. One refused
     * change does not stop the others.
     */
    public function bulkUpdate(BulkUpdateRequest $request): JsonResponse
    {
        Gate::authorize('employee.update');

        $toStatus = $request->filled('status')
            ? EmployeeStatus::from($request->validated('status'))
            : null;

        // A status change is a transition, and the single transition endpoint
        // asks for employee.transition; holding employee.update is not enough.
        if ($toStatus !== null) {
            Gate::authorize('employee.transition');
        }

        $moves = [];
        $department = null;
        $branch = null;

        if ($request->has('department_id')) {
            $department = Department::where('public_id', $request->validated('department_id'))->first();
            if ($department) {
                $moves['department_id'] = $department->id;
            }
        }

        if ($request->has('branch_id')) {
            $branch = Branch::where('public_id', $request->validated('branch_id'))->first();
            if ($branch) {
                $moves['branch_id'] = $branch->id;
            }
        }

        // Resolved through EmployeeRelations::MAP, the tenant-scoped models the
        // single edit resolves them through. The request has already refused a
        // public id that is not this organisation's.
        $job = [];
        foreach (array_keys(self::JOB_RELATIONS) as $field) {
            if ($request->filled($field)) {
                $model = EmployeeRelations::MAP[$field]::where('public_id', $request->validated($field))->first();
                if ($model !== null) {
                    $job[$field] = $model;
                }
            }
        }

        if ($moves === [] && $job === [] && $toStatus === null) {
            return response()->json([
                'type' => 'https://ethr.et/errors/no-update-fields',
                'title' => 'No Update Fields',
                'status' => 422,
                'detail' => 'At least one field to update must be provided.',
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        /** @var list<string> $publicIds */
        $publicIds = array_values(array_unique($request->validated('employee_ids')));

        // The same org scope as the employee list and the export. Without it a
        // manager scoped to one department, holding employee.update, changed
        // anyone in the tenant.
        $query = Employee::query()->with(['department', 'branch', ...array_values(self::JOB_RELATIONS)])->whereIn('public_id', $publicIds);
        $request->user()->scopeAccessibleEmployees($query);
        $employees = $query->get()->keyBy('public_id');

        $request->refuseEmployeesOutOfReach($employees->keys()->all());

        $effectiveDate = $request->validated('effective_date') ?? EmployeeTransitionService::today();
        $reason = $request->validated('reason');
        $results = [];

        foreach ($publicIds as $publicId) {
            $results[] = $this->updateOne($employees->get($publicId), $moves, $job, $department, $branch, $toStatus, $effectiveDate, $reason, $request->user());
        }

        $updated = count(array_filter($results, fn (array $r): bool => $r['outcome'] === 'updated'));
        $failed = count(array_filter($results, fn (array $r): bool => $r['outcome'] === 'failed'));

        return response()->json([
            'updated' => $updated,
            'failed' => $failed,
            'results' => $results,
        ]);
    }

    /**
     * One employee's share of a bulk update, in its own transaction, so a
     * refused transition rolls back that employee's move as well and leaves
     * the others alone.
     *
     * @param  array<string, int>  $moves
     * @param  array<string, Model>  $job  the job fields asked for, each the tenant's own record
     * @return array{employee_id: string, name: string, outcome: 'updated'|'unchanged'|'failed', error: string|null, access_held: bool}
     */
    private function updateOne(
        Employee $employee,
        array $moves,
        array $job,
        ?Department $department,
        ?Branch $branch,
        ?EmployeeStatus $toStatus,
        string $effectiveDate,
        ?string $reason,
        User $actor,
    ): array {
        $changesStatus = $toStatus !== null && $employee->status !== $toStatus;

        // Only what differs from now: a title or supervisor the person already
        // has is not a change, and not a reason to run the supervisor rule.
        $jobChanges = array_filter(
            $job,
            fn (Model $model, string $field): bool => (int) $employee->{$field} !== (int) $model->getKey(),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($moves === [] && $jobChanges === [] && ! $changesStatus) {
            return $this->result($employee, 'unchanged');
        }

        // The edit form's rule, shared (SupervisorChain): not themselves, and
        // not someone who supervises them further up.
        if (isset($jobChanges['supervisor_id'])) {
            $refusal = SupervisorChain::refusal($employee, (string) $jobChanges['supervisor_id']->getAttribute('public_id'));
            if ($refusal !== null) {
                return $this->result($employee, 'failed', (string) __($refusal));
            }
        }

        $before = $this->auditedFields($employee, $moves, $toStatus, $jobChanges);

        try {
            DB::transaction(function () use ($employee, $moves, $jobChanges, $department, $branch, $toStatus, $changesStatus, $effectiveDate, $reason, $before, $actor): void {
                if ($moves !== []) {
                    $employee->fill($moves)->save();

                    if ($department !== null) {
                        $employee->setRelation('department', $department);
                    }

                    if ($branch !== null) {
                        $employee->setRelation('branch', $branch);
                    }
                }

                // The edit the form makes, by the one class that makes it: the
                // stored values, the `employee.updated` audit row and the
                // webhook. A new job title reaches the person's login through
                // the model's own hook (PositionAccess), as an edit's does.
                if ($jobChanges !== []) {
                    $this->updater->update(
                        $employee,
                        array_map(fn (Model $model): string => (string) $model->getAttribute('public_id'), $jobChanges),
                        $actor,
                    );
                    $employee->load(array_map(fn (string $field): string => self::JOB_RELATIONS[$field], array_keys($jobChanges)));
                }

                if ($changesStatus) {
                    $this->transitions->transition($employee, $toStatus, $effectiveDate, $reason, auth()->id());
                }

                AuditLog::record('employee.bulk_updated', $employee, [
                    'before' => $before,
                    'after' => $this->auditedFields($employee, $moves, $toStatus, $jobChanges),
                ]);
            });
        } catch (InvalidEmployeeTransition $e) {
            return $this->result($employee, 'failed', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->result($employee, 'failed', (string) __('employee.bulk_update_failed'));
        }

        $held = isset($jobChanges['position_id']) ? $this->accessHeld($employee) : false;

        return $this->result($employee, 'updated', accessHeld: $held);
    }

    /**
     * Whether this person's login follows its job title and holds a role other
     * than the title's: the title changed, and the person making the change
     * could not have given the role by hand (PositionAccess::sync holds it).
     */
    private function accessHeld(Employee $employee): bool
    {
        $login = User::query()->where('employee_id', $employee->id)->first();
        if (! $login instanceof User) {
            return false;
        }

        $to = app(PositionAccess::class)->titleRole($login);

        return $to !== null && $login->role !== $to;
    }

    /**
     * One entry of `results`. Built in one place so the published contract
     * reads `error` as a string, not as the English text of one message.
     *
     * @param  'updated'|'unchanged'|'failed'  $outcome
     * @return array{employee_id: string, name: string, outcome: 'updated'|'unchanged'|'failed', error: string|null, access_held: bool}
     */
    private function result(Employee $employee, string $outcome, ?string $error = null, bool $accessHeld = false): array
    {
        return ['employee_id' => $employee->public_id, 'name' => $employee->name, 'outcome' => $outcome, 'error' => $error, 'access_held' => $accessHeld];
    }

    /**
     * The fields a bulk update touches, by public id and enum value, so the
     * audit entry reads as what changed without joining back to internal keys.
     *
     * @param  array<string, int>  $moves
     * @param  array<string, Model>  $jobChanges
     * @return array<string, string|null>
     */
    private function auditedFields(Employee $employee, array $moves, ?EmployeeStatus $toStatus, array $jobChanges = []): array
    {
        $fields = [];

        if (array_key_exists('department_id', $moves)) {
            $fields['department_id'] = $employee->department?->public_id;
        }

        if (array_key_exists('branch_id', $moves)) {
            $fields['branch_id'] = $employee->branch?->public_id;
        }

        foreach (array_keys($jobChanges) as $field) {
            $related = $employee->getRelation(self::JOB_RELATIONS[$field]);
            $fields[$field] = $related instanceof Model ? (string) $related->getAttribute('public_id') : null;
        }

        if ($toStatus !== null) {
            $fields['status'] = $employee->status->value;
        }

        return $fields;
    }

    /**
     * Archive people who should not have a record: added by mistake, or twice.
     *
     * Without `confirm` this only says what would happen and writes nothing.
     * With `confirm: true` it archives everyone who has no history, each in
     * their own transaction, asking again whether they have been used at that
     * moment. Someone with attendance, pay, leave, a sign-in or any other
     * history is kept, with the reasons, and leaves through a status change
     * instead. Nobody archives their own record (`self`). Anyone already
     * archived is reported as such, not refused, so the same request twice
     * changes nothing the second time.
     *
     * An id outside the requester's org scope refuses the whole request with
     * the 422 an id that exists nowhere gets. Archived people can be brought
     * back with `POST /employees/bulk-restore`.
     */
    public function bulkArchive(BulkArchiveRequest $request, EmployeeArchiver $archiver): JsonResponse
    {
        Gate::authorize('employee.delete');

        $actor = $request->user();
        $confirmed = $request->boolean('confirm');

        /** @var list<string> $publicIds */
        $publicIds = array_values(array_unique($request->validated('employee_ids')));

        $query = Employee::withTrashed()->whereIn('public_id', $publicIds);
        $actor->scopeAccessibleEmployees($query);
        $people = $query->get()->keyBy('public_id');

        $request->refuseEmployeesOutOfReach($people->keys()->all());

        $live = $people->filter(fn (Employee $person): bool => ! $person->trashed());
        $used = $live->isEmpty()
            ? []
            : $archiver->reasons((int) $live->first()->tenant_id, $live->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $results = [];
        foreach ($publicIds as $publicId) {
            $person = $people->get($publicId);

            if ($person->trashed()) {
                $results[] = $this->archiveResult($person, 'already_archived');

                continue;
            }

            $why = $this->keepReasons($person, $actor, $used[$person->id] ?? []);

            if ($why !== []) {
                $results[] = $this->archiveResult($person, $confirmed ? 'kept' : 'keep', $why);
            } elseif (! $confirmed) {
                $results[] = $this->archiveResult($person, 'archive');
            } else {
                $results[] = $this->archiveOne($person, $actor, $archiver);
            }
        }

        $archived = count(array_filter($results, fn (array $r): bool => in_array($r['outcome'], ['archive', 'archived'], true)));
        $kept = count(array_filter($results, fn (array $r): bool => in_array($r['outcome'], ['keep', 'kept'], true)));
        $already = count(array_filter($results, fn (array $r): bool => $r['outcome'] === 'already_archived'));
        $failed = count(array_filter($results, fn (array $r): bool => $r['outcome'] === 'failed'));

        if ($confirmed && $archived > 0) {
            AuditLog::record('employee.bulk_archived', null, [
                'requested' => count($publicIds),
                'archived' => $archived,
                'kept' => $kept,
                'already_archived' => $already,
                'failed' => $failed,
            ]);
        }

        return response()->json([
            'confirmed' => $confirmed,
            'archive_count' => $archived,
            'keep_count' => $kept,
            'already_archived' => $already,
            'failed' => $failed,
            'results' => $results,
        ]);
    }

    /**
     * One person's share of a confirmed archive, in its own transaction. The
     * row is locked and read again, so someone archived, or used, since the
     * preview a moment ago is not archived on the strength of it.
     *
     * @return array{employee_id: string, name: string, outcome: 'archive'|'keep'|'archived'|'kept'|'already_archived'|'failed', reasons: list<string>, error: string|null}
     */
    private function archiveOne(Employee $person, User $actor, EmployeeArchiver $archiver): array
    {
        try {
            return DB::transaction(function () use ($person, $actor, $archiver): array {
                $fresh = Employee::withTrashed()->whereKey($person->id)->lockForUpdate()->first();

                if (! $fresh instanceof Employee || $fresh->trashed()) {
                    return $this->archiveResult($person, 'already_archived');
                }

                $used = $archiver->reasons((int) $fresh->tenant_id, [$fresh->id]);
                $why = $this->keepReasons($fresh, $actor, $used[$fresh->id] ?? []);
                if ($why !== []) {
                    return $this->archiveResult($fresh, 'kept', $why);
                }

                $done = $archiver->archive(
                    new EloquentCollection([$fresh]),
                    $actor,
                    ['via' => 'bulk_archive'],
                    ['reason' => 'bulk_archive'],
                    $used,
                );

                return $done === []
                    ? $this->archiveResult($fresh, 'failed', error: (string) __('employee.bulk_archive_failed'))
                    : $this->archiveResult($fresh, 'archived');
            });
        } catch (Throwable $e) {
            report($e);

            return $this->archiveResult($person, 'failed', error: (string) __('employee.bulk_archive_failed'));
        }
    }

    /**
     * Why this person is not archived, if they are not: what they have been
     * used for (AdditionUse), or that the record is the actor's own.
     *
     * @param  list<string>  $used
     * @return list<string>
     */
    private function keepReasons(Employee $person, User $actor, array $used): array
    {
        $own = ($actor->employee_id !== null && (int) $actor->employee_id === (int) $person->id)
            || ($person->user_id !== null && (int) $person->user_id === (int) $actor->id);

        return $own ? ['self', ...$used] : $used;
    }

    /**
     * One entry of a bulk archive's `results`, built in one place so the
     * published contract reads `outcome` and `reasons` the same on every path.
     *
     * @param  'archive'|'keep'|'archived'|'kept'|'already_archived'|'failed'  $outcome
     * @param  list<string>  $reasons
     * @return array{employee_id: string, name: string, outcome: 'archive'|'keep'|'archived'|'kept'|'already_archived'|'failed', reasons: list<string>, error: string|null}
     */
    private function archiveResult(Employee $employee, string $outcome, array $reasons = [], ?string $error = null): array
    {
        $codes = [];
        foreach ($reasons as $reason) {
            $codes[] = (string) $reason;
        }

        return [
            'employee_id' => $employee->public_id,
            'name' => $employee->name,
            'outcome' => $outcome,
            'reasons' => $codes,
            'error' => $error,
        ];
    }

    /**
     * Bring archived people back, each as `POST /employees/{employee}/restore`
     * does: the plan's employee limit is counted under the seat lock, the
     * restore is audited and the webhook is sent. `results` reports each id as
     * `restored`, `unchanged` (not archived) or `failed`, with the reason, for
     * example that the plan has no seat left. One refusal does not stop the
     * others. A restore does not reopen a login that archiving closed.
     */
    public function bulkRestore(BulkRestoreRequest $request, EmployeeRestorer $restorer): JsonResponse
    {
        Gate::authorize('employee.delete');

        /** @var list<string> $publicIds */
        $publicIds = array_values(array_unique($request->validated('employee_ids')));

        $query = Employee::withTrashed()->whereIn('public_id', $publicIds);
        $request->user()->scopeAccessibleEmployees($query);
        $people = $query->get()->keyBy('public_id');

        $request->refuseEmployeesOutOfReach($people->keys()->all());

        $results = [];
        foreach ($publicIds as $publicId) {
            $results[] = $this->restoreOne($people->get($publicId), $restorer);
        }

        $restored = count(array_filter($results, fn (array $r): bool => $r['outcome'] === 'restored'));
        $failed = count(array_filter($results, fn (array $r): bool => $r['outcome'] === 'failed'));

        return response()->json([
            'restored' => $restored,
            'failed' => $failed,
            'results' => $results,
        ]);
    }

    /**
     * @return array{employee_id: string, name: string, outcome: 'restored'|'unchanged'|'failed', error: string|null}
     */
    private function restoreOne(Employee $person, EmployeeRestorer $restorer): array
    {
        try {
            return $this->restoreResult($person, $restorer->restore($person) ? 'restored' : 'unchanged');
        } catch (HttpException $e) {
            return $this->restoreResult($person, 'failed', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->restoreResult($person, 'failed', (string) __('employee.bulk_restore_failed'));
        }
    }

    /**
     * @param  'restored'|'unchanged'|'failed'  $outcome
     * @return array{employee_id: string, name: string, outcome: 'restored'|'unchanged'|'failed', error: string|null}
     */
    private function restoreResult(Employee $employee, string $outcome, ?string $error = null): array
    {
        return ['employee_id' => $employee->public_id, 'name' => $employee->name, 'outcome' => $outcome, 'error' => $error];
    }

    /**
     * The public ids of everyone the employees list matches
     *
     * For "select all matching this filter". A read only: nothing is changed by
     * filter. The screen sends these ids to the bulk endpoints, which check each
     * person against the requester's reach again.
     *
     * Takes the list's own `search` and `filter[...]` parameters, through the
     * same code (`EmployeeListFilters`) and the same org scope as
     * `GET /employees`. At most 2,000 ids come back; `total` is the full count
     * and `capped` is true when `ids` holds fewer than `total`.
     */
    public function ids(EmployeeIdsRequest $request): JsonResponse
    {
        Gate::authorize('employee.viewAny');

        $query = Employee::query();
        $request->user()->scopeAccessibleEmployees($query);
        EmployeeListFilters::apply($query, $request, [
            'search' => $request->input('search'),
            'status' => $request->input('filter.status'),
            'missing' => $request->input('filter.missing'),
            'department_id' => $request->input('filter.department_id'),
            'branch_id' => $request->input('filter.branch_id'),
            'position_id' => $request->input('filter.position_id'),
        ]);

        $total = (int) (clone $query)->count();

        /** @var list<string> $ids */
        $ids = [];
        foreach ($query->orderBy('id')->limit(self::IDS_CAP)->pluck('public_id') as $publicId) {
            $ids[] = (string) $publicId;
        }

        return response()->json($this->idsAnswer($ids, $total));
    }

    /**
     * @param  list<string>  $ids
     * @return array{ids: list<string>, total: int, capped: bool, cap: int}
     */
    private function idsAnswer(array $ids, int $total): array
    {
        return ['ids' => $ids, 'total' => $total, 'capped' => $total > self::IDS_CAP, 'cap' => self::IDS_CAP];
    }

    public function export(Request $request): JsonResponse
    {
        Gate::authorize('employee.viewAny');

        // The profile columns are what the employee's own record shows to
        // someone who may open it. A caller with viewAny alone gets neither
        // them nor the queries for them.
        $profile = $request->user()->hasPermission('employee.view');

        $query = Employee::query()
            ->with(['department', 'branch', 'position']);
        if ($profile) {
            $query->with([
                'grade',
                'costCenter',
                'contracts' => fn ($contracts) => $contracts->where('status', ContractStatus::ACTIVE->value)->orderByDesc('id'),
                'emergencyContacts' => fn ($contacts) => $contacts->orderBy('priority')->orderBy('id'),
            ]);
        }

        // The same org scope as the employee list. Without it a supervisor —
        // who holds employee.viewAny to see their team — downloaded every
        // employee's name, e-mail and phone in the tenant.
        $request->user()->scopeAccessibleEmployees($query);

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        // The list's own filters and sort (EmployeeController::index), so the
        // file holds what the screen showed: the export read only search,
        // status and department, and the page sent only search.
        if ($request->boolean('filter.nationality_needs_review')) {
            $query->where('nationality_needs_review', true);
        }

        EmployeeGaps::restrict($query, $request->input('filter.missing'));

        if ($request->has('filter.department_id')) {
            $dept = Department::where('public_id', $request->input('filter.department_id'))->first();
            if ($dept) {
                $query->where('department_id', $dept->id);
            }
        }

        if ($request->has('filter.branch_id')) {
            $branch = Branch::where('public_id', $request->input('filter.branch_id'))->first();
            if ($branch) {
                $query->where('branch_id', $branch->id);
            }
        }

        if ($request->has('filter.position_id')) {
            $position = Position::where('public_id', $request->input('filter.position_id'))->first();
            if ($position) {
                $query->where('position_id', $position->id);
            }
        }

        $sortField = (string) $request->input('sort', 'name');
        $sortDir = str_starts_with($sortField, '-') ? 'desc' : 'asc';
        $sortField = ltrim($sortField, '-');
        if (in_array($sortField, ['name', 'employee_code', 'hire_date', 'status', 'created_at'], true)) {
            $query->orderBy($sortField, $sortDir);
        }

        $employees = $query->get();

        // The import's own column names, so an exported file imports as it
        // stands; department, branch and position by name, which the import
        // reads as readily as a code. Salary, national ID and TIN stay out:
        // this is a viewAny export, and a supervisor can run it. So do the
        // bank columns: bank details are read with the pay permission, which
        // this export does not ask for, and a bank name alone would fail
        // every line it was imported on, because an account needs its number.
        $headers = [
            'name', 'name_am', 'employee_code', 'email', 'phone', 'gender', 'date_of_birth',
            'hire_date', 'department', 'branch', 'position', 'badge_number',
            'nationality', 'nationality_status', 'marital_status', 'status',
        ];
        if ($profile) {
            array_push(
                $headers,
                'grade', 'cost_center', 'probation_end_date', 'contract_type', 'contract_end_date',
                'emergency_name', 'emergency_relationship', 'emergency_phone',
            );
        }
        $rows = [];

        foreach ($employees as $emp) {
            $contract = $profile ? $emp->contracts->first() : null;
            $contact = $profile ? $emp->emergencyContacts->first() : null;

            $rows[] = [
                $emp->name,
                $emp->name_am,
                $emp->employee_code,
                $emp->email,
                $emp->phone,
                $emp->gender,
                $emp->date_of_birth?->format('Y-m-d'),
                $emp->hire_date?->format('Y-m-d'),
                $emp->department?->name,
                $emp->branch?->name,
                $emp->position?->title,
                $emp->badge_number,
                $emp->nationality,
                $emp->nationality_status?->value,
                $emp->marital_status,
                $emp->status?->value,
                ...($profile ? [
                    $emp->grade?->name,
                    $emp->costCenter?->name,
                    $emp->probation_end_date?->format('Y-m-d'),
                    $contract?->contract_type->value,
                    $contract?->end_date?->format('Y-m-d'),
                    $contact?->name,
                    $contact?->relationship,
                    $contact?->phone,
                ] : []),
            ];
        }

        // Through Csv::row, like every other export: a name such as
        // =HYPERLINK(...) is neutralised instead of executing when HR opens it.
        $csv = Csv::row($headers)."\n";
        foreach ($rows as $row) {
            $csv .= Csv::row($row)."\n";
        }

        return response()->json([
            'csv' => $csv,
            'count' => count($rows),
        ]);
    }
}
