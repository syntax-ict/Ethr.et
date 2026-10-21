<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Approval;

use App\Enums\ApproverKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Approval\StoreApprovalWorkflowRequest;
use App\Http\Requests\Approval\UpdateApprovalWorkflowRequest;
use App\Http\Resources\ApprovalWorkflowResource;
use App\Models\ApprovalWorkflow;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * An organisation's approval chains (docs/APPROVAL-ENGINE-DESIGN.md, Phase B).
 *
 * A change applies to requests submitted after it: each request runs the
 * snapshot of the workflow it was submitted under. With no active workflow
 * for a request type, that type is decided in one step, as it always was.
 */
class ApprovalWorkflowController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('settings.manage');

        return ApprovalWorkflowResource::collection(
            ApprovalWorkflow::query()
                ->with('steps')
                ->orderBy('request_type')
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get()
        );
    }

    public function store(StoreApprovalWorkflowRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $workflow = DB::transaction(function () use ($request): ApprovalWorkflow {
            $workflow = ApprovalWorkflow::create($this->attributes($request));
            $this->writeSteps($workflow, $request);

            return $workflow;
        });

        $workflow->load('steps');
        AuditLog::record('approval_workflow.created', $workflow, ['steps' => $this->stepSummary($workflow)]);

        return (new ApprovalWorkflowResource($workflow))->response()->setStatusCode(201);
    }

    public function update(UpdateApprovalWorkflowRequest $request, ApprovalWorkflow $approvalWorkflow): ApprovalWorkflowResource
    {
        Gate::authorize('settings.manage');

        $approvalWorkflow->load('steps');
        $before = $this->stepSummary($approvalWorkflow);

        DB::transaction(function () use ($request, $approvalWorkflow): void {
            $approvalWorkflow->update($this->attributes($request));
            $approvalWorkflow->steps()->delete();
            $this->writeSteps($approvalWorkflow, $request);
        });

        $approvalWorkflow->load('steps');
        AuditLog::record('approval_workflow.updated', $approvalWorkflow, [
            'steps_before' => $before,
            'steps' => $this->stepSummary($approvalWorkflow),
        ]);

        return new ApprovalWorkflowResource($approvalWorkflow);
    }

    public function destroy(ApprovalWorkflow $approvalWorkflow): JsonResponse
    {
        Gate::authorize('settings.manage');

        $approvalWorkflow->delete();
        AuditLog::record('approval_workflow.deleted', $approvalWorkflow);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function attributes(StoreApprovalWorkflowRequest $request): array
    {
        $conditions = array_filter(
            (array) $request->validated('conditions', []),
            static fn ($ids): bool => is_array($ids) && $ids !== [],
        );

        return [
            'request_type' => $request->validated('request_type'),
            'name' => $request->validated('name'),
            'priority' => (int) $request->validated('priority', 0),
            'is_active' => (bool) $request->validated('is_active', true),
            'conditions' => $conditions === [] ? null : $conditions,
        ];
    }

    private function writeSteps(ApprovalWorkflow $workflow, StoreApprovalWorkflowRequest $request): void
    {
        foreach (array_values((array) $request->validated('steps')) as $i => $step) {
            $kind = ApproverKind::from($step['approver_kind']);

            $workflow->steps()->create([
                'tenant_id' => $workflow->tenant_id,
                'position' => $i + 1,
                'approver_kind' => $kind,
                'approver_ref' => in_array($kind, [ApproverKind::ROLE, ApproverKind::USER], true) ? $step['approver_ref'] : null,
                'sla_hours' => (int) ($step['sla_hours'] ?? 48),
            ]);
        }
    }

    /** @return list<string> */
    private function stepSummary(ApprovalWorkflow $workflow): array
    {
        return $workflow->steps
            ->map(fn ($step): string => trim($step->approver_kind->value.' '.($step->approver_ref ?? '')))
            ->values()
            ->all();
    }
}
