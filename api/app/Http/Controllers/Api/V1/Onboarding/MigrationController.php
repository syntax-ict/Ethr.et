<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Enums\OnboardingStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Migration\ReadPeopleRequest;
use App\Http\Requests\Migration\StageRowsRequest;
use App\Http\Requests\Migration\UpdateStagingRowRequest;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Models\OnboardingProgress;
use App\Models\PersonnelAction;
use App\Services\CurrentTenant;
use App\Services\Invitations\EmployeeLogins;
use App\Services\Migration\StagedChanges;
use App\Services\Migration\WorkforceMigrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
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
     * or none. The answer says how many people are ready, which columns were
     * read and which column names mean nothing here, and every line that
     * cannot be staged, with its number as the reader counts it. Nothing is
     * kept.
     */
    public function read(ReadPeopleRequest $request): JsonResponse
    {
        Gate::authorize('employee.create');

        $read = $request->people();
        abort_if($read === null, 422, __('general.not_found', ['resource' => 'Tenant']));

        return response()->json([
            'people' => count($read->rows),
            'header' => $read->header,
            'columns' => $read->columns,
            'ignored' => $read->ignored,
            'calendar' => $read->calendar,
            'calendar_from_header' => $read->calendarFromHeader,
            'without_hire_date' => $read->withoutHireDate,
            'problems' => $read->problems,
        ]);
    }

    /**
     * Stage a list of people for review.
     *
     * Send `grid` (the list as lines of cells, read as `/migration/read`
     * reads it) or `rows` (people already keyed by field). A grid with a line
     * that cannot be staged is refused whole, each line named, unless
     * `skip_problems` is set: then those lines are left out. A list that sets
     * a salary or a TIN needs the pay permission, as the add-employee form does.
     */
    public function stageFromRows(StageRowsRequest $request): JsonResponse
    {
        Gate::authorize('employee.create');

        $tenantId = $this->currentTenant->id();
        abort_if($tenantId === null, 422, __('general.not_found', ['resource' => 'Tenant']));

        $rows = $request->staged();
        foreach ($rows as $row) {
            if (isset($row['salary_cents']) || isset($row['tin'])) {
                Gate::authorize('employee.updateFinancial');
                break;
            }
        }

        $batch = $this->migration->stageFromRows(
            $rows,
            $tenantId,
            $request->string('source_type', 'csv')->value(),
            $request->input('source_ref'),
            $request->user(),
        );

        AuditLog::record('migration.staged', $batch, ['source' => $batch->source_type, 'rows' => count($rows)]);

        return response()->json($this->batchPayload($batch), 201);
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

        // A list that has begun to be added is past review: a decision
        // changed now would reach the rows not yet written and miss the rest.
        abort_unless($row->batch()->firstOrFail()->isReviewing(), 409, __('onboarding.migration.already_committing'));

        // Accepting a difference is deciding to edit that employee, so it
        // takes what editing them takes: employee.update with reach over the
        // person, and the pay permission for salary or TIN. Without them the
        // row can still be linked; only the edit is refused.
        $accept = $request->validated('accept');
        $target = $employee ?? $row->resolvedEmployee;
        if (is_array($accept) && $accept !== []) {
            abort_if($target === null, 422, __('onboarding.migration.accept_needs_match'));
            Gate::authorize('update', $target);
            if (array_intersect($accept, StagedChanges::PAY) !== []) {
                Gate::authorize('updateFinancial', $target);
            }
        }

        $this->migration->setAction($row, $request->validated('action'), $employee, is_array($accept) ? array_values($accept) : null);

        $row = $row->refresh()->load('resolvedEmployee');

        return response()->json($this->rowPayload($row, $this->scheduledAmong([$row])));
    }

    /**
     * Add the list's people, as much as fits in one request.
     *
     * A long list is added a slice at a time. While `status` is `committing`,
     * `remaining` rows are still to be dealt with and the same request is
     * sent again; nothing is added twice. `totals` counts the whole list so
     * far, and `updated` is how many existing employees had details filled in
     * or changed.
     */
    public function commit(MigrationBatch $batch): JsonResponse
    {
        Gate::authorize('employee.create');

        $progress = $this->migration->commitSlice($batch, request()->user());
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
     * @return array{without_login: list<array{employee_public_id: string, name: string, email: string|null, phone: string|null, channel: string, invited: bool}>, hire_dates_defaulted: int}
     */
    private function loginsPayload(MigrationBatch $batch, bool $finished = true): array
    {
        // Until the whole list is added the answer would be about part of
        // it, and reading it costs two queries a slice for nothing.
        $people = $finished ? $this->migration->employees($batch) : new Collection;

        return [
            'without_login' => $this->logins->pending($people),
            'hire_dates_defaulted' => $finished ? $this->migration->hireDatesDefaulted($batch) : 0,
        ];
    }

    private function batchPayload(MigrationBatch $batch): array
    {
        $rows = $batch->rows()->with('resolvedEmployee')->get();
        $scheduled = $this->scheduledAmong($rows->all());

        return [
            'public_id' => $batch->public_id,
            'source_type' => $batch->source_type,
            'source_ref' => $batch->source_ref,
            'status' => $batch->status,
            'totals' => $batch->totals,
            'summary' => $this->summarize($batch),
            'rows' => $rows->map(fn (MigrationStagingRow $row) => $this->rowPayload($row, $scheduled))->all(),
        ];
    }

    /**
     * @param  list<int>  $scheduled  employees with a change waiting for its date
     */
    private function rowPayload(MigrationStagingRow $row, array $scheduled = []): array
    {
        $processedAt = $row->getAttribute('processed_at');
        $employee = $row->resolvedEmployee;

        return [
            'public_id' => $row->public_id,
            'display_name' => $row->display_name,
            'external_identifier' => $row->external_identifier,
            'match_outcome' => $row->match_outcome,
            'match_confidence' => $row->match_confidence,
            'candidates' => $row->candidates,
            'resolved_employee_public_id' => $employee?->public_id,
            'action' => $row->action,
            'changes' => $this->changesPayload($row, $employee),
            'can_apply_changes' => $this->mayEdit($employee),
            'scheduled_action' => $this->isScheduled($employee, $scheduled),
            'processed_at' => $processedAt instanceof \DateTimeInterface ? $processedAt->format('c') : null,
        ];
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
    private function changesPayload(MigrationStagingRow $row, ?Employee $employee): array
    {
        if ($employee === null) {
            return [];
        }

        $mayView = Gate::allows('view', $employee);
        $payload = [];

        foreach ($row->changes ?? [] as $change) {
            $field = (string) ($change['field'] ?? '');
            $masked = in_array($field, StagedChanges::MASKED, true);
            $current = $change['current'] ?? null;
            $incoming = $change['incoming'] ?? null;

            $payload[] = [
                'field' => $field,
                'kind' => (string) ($change['kind'] ?? ''),
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
