<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Migration\StageRowsRequest;
use App\Http\Requests\Migration\UpdateStagingRowRequest;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Services\CurrentTenant;
use App\Services\Migration\WorkforceMigrationService;
use Illuminate\Http\JsonResponse;
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
    ) {}

    public function stageFromDevice(Device $device): JsonResponse
    {
        Gate::authorize('employee.create');

        $batch = $this->migration->stageFromDevice($device, request()->user());

        AuditLog::record('migration.staged', $batch, ['source' => 'device', 'device_id' => $device->public_id]);

        return response()->json($this->batchPayload($batch), 201);
    }

    public function stageFromRows(StageRowsRequest $request): JsonResponse
    {
        Gate::authorize('employee.create');

        $tenantId = $this->currentTenant->id();
        abort_if($tenantId === null, 422, __('general.not_found', ['resource' => 'Tenant']));

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $request->validated('rows');

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

        $this->migration->setAction($row, $request->validated('action'), $employee);

        return response()->json($this->rowPayload($row->refresh()->load('resolvedEmployee')));
    }

    public function commit(MigrationBatch $batch): JsonResponse
    {
        Gate::authorize('employee.create');

        $totals = $this->migration->commit($batch);

        AuditLog::record('migration.committed', $batch->refresh(), ['totals' => $totals]);

        return response()->json([
            'message' => __('general.updated', ['resource' => 'Migration']),
            'totals' => $totals,
            'status' => $batch->status,
        ]);
    }

    private function batchPayload(MigrationBatch $batch): array
    {
        return [
            'public_id' => $batch->public_id,
            'source_type' => $batch->source_type,
            'source_ref' => $batch->source_ref,
            'status' => $batch->status,
            'totals' => $batch->totals,
            'summary' => $this->summarize($batch),
            'rows' => $batch->rows()->with('resolvedEmployee')->get()->map(fn (MigrationStagingRow $row) => $this->rowPayload($row))->all(),
        ];
    }

    private function rowPayload(MigrationStagingRow $row): array
    {
        $processedAt = $row->getAttribute('processed_at');

        return [
            'public_id' => $row->public_id,
            'display_name' => $row->display_name,
            'external_identifier' => $row->external_identifier,
            'match_outcome' => $row->match_outcome,
            'match_confidence' => $row->match_confidence,
            'candidates' => $row->candidates,
            'resolved_employee_public_id' => $row->resolvedEmployee?->public_id,
            'action' => $row->action,
            'processed_at' => $processedAt instanceof \DateTimeInterface ? $processedAt->format('c') : null,
        ];
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
