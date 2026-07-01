<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Approval;

use App\Enums\LeaveStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ApprovalController extends Controller
{
    public function pending(Request $request): JsonResponse
    {
        Gate::authorize('leave.viewTeam');

        $user = $request->user();
        $employee = $user->employee;
        $teamIds = $this->getTeamIds($employee);

        $items = [];

        $leaveRequests = LeaveRequest::query()
            ->whereIn('employee_id', $teamIds)
            ->where('status', LeaveStatus::PENDING)
            ->with('employee:id,name,public_id', 'leaveType:id,name')
            ->orderByDesc('created_at')
            ->get();

        foreach ($leaveRequests as $lr) {
            $items[] = [
                'type' => 'leave',
                'public_id' => $lr->public_id,
                'employee_name' => $lr->employee?->name,
                'employee_public_id' => $lr->employee?->public_id,
                'summary' => ($lr->leaveType?->name ?? 'Leave').': '.$lr->start_date->format('M d').' - '.$lr->end_date->format('M d'),
                'submitted_at' => $lr->created_at,
            ];
        }

        if (class_exists(AttendanceCorrection::class)) {
            $corrections = AttendanceCorrection::query()
                ->whereIn('employee_id', $teamIds)
                ->where('status', 'pending')
                ->with('employee:id,name,public_id')
                ->orderByDesc('created_at')
                ->get();

            foreach ($corrections as $c) {
                $items[] = [
                    'type' => 'correction',
                    'public_id' => $c->public_id,
                    'employee_name' => $c->employee?->name,
                    'employee_public_id' => $c->employee?->public_id,
                    'summary' => 'Attendance correction for '.$c->date->format('M d'),
                    'submitted_at' => $c->created_at,
                ];
            }
        }

        usort($items, fn ($a, $b) => $b['submitted_at'] <=> $a['submitted_at']);

        return response()->json([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    public function batch(Request $request): JsonResponse
    {
        Gate::authorize('leave.approve');

        $request->validate([
            'actions' => ['required', 'array', 'min:1'],
            'actions.*.type' => ['required', 'string', 'in:leave,correction'],
            'actions.*.public_id' => ['required', 'string'],
            'actions.*.action' => ['required', 'string', 'in:approve,reject'],
            'actions.*.reason' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $results = [];

        foreach ($request->input('actions') as $action) {
            $result = match ($action['type']) {
                'leave' => $this->processLeaveAction($action, $user),
                'correction' => $this->processCorrectionAction($action, $user),
                default => ['status' => 'error', 'detail' => 'Unknown type'],
            };

            $results[] = array_merge(['public_id' => $action['public_id']], $result);
        }

        return response()->json(['results' => $results]);
    }

    private function processLeaveAction(array $action, $user): array
    {
        $lr = LeaveRequest::where('public_id', $action['public_id'])->first();
        if (! $lr || $lr->status !== LeaveStatus::PENDING) {
            return ['status' => 'error', 'detail' => 'Not found or not pending'];
        }

        if ($action['action'] === 'approve') {
            $lr->update([
                'status' => LeaveStatus::APPROVED,
                'approved_by' => array_merge($lr->approved_by ?? [], [$user->id]),
            ]);

            $balance = LeaveBalance::where('employee_id', $lr->employee_id)
                ->where('leave_type_id', $lr->leave_type_id)
                ->where('year', $lr->start_date->year)
                ->first();

            if ($balance) {
                $balance->update([
                    'used_days' => $balance->used_days + $lr->days,
                    'pending_days' => max(0, $balance->pending_days - $lr->days),
                ]);
            }

            AuditLog::record('leave.approved', $lr);

            return ['status' => 'approved'];
        }

        $lr->update([
            'status' => LeaveStatus::REJECTED,
            'rejected_by' => $user->id,
            'rejected_reason' => $action['reason'] ?? 'Batch rejected',
        ]);

        $balance = LeaveBalance::where('employee_id', $lr->employee_id)
            ->where('leave_type_id', $lr->leave_type_id)
            ->where('year', $lr->start_date->year)
            ->first();

        if ($balance) {
            $balance->update([
                'pending_days' => max(0, $balance->pending_days - $lr->days),
            ]);
        }

        AuditLog::record('leave.rejected', $lr);

        return ['status' => 'rejected'];
    }

    private function processCorrectionAction(array $action, $user): array
    {
        if (! class_exists(AttendanceCorrection::class)) {
            return ['status' => 'error', 'detail' => 'Corrections not available'];
        }

        $correction = AttendanceCorrection::where('public_id', $action['public_id'])->first();
        if (! $correction || $correction->status !== 'pending') {
            return ['status' => 'error', 'detail' => 'Not found or not pending'];
        }

        if ($action['action'] === 'approve') {
            $correction->update([
                'status' => 'approved',
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            AuditLog::record('correction.approved', $correction);

            return ['status' => 'approved'];
        }

        $correction->update([
            'status' => 'rejected',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'review_notes' => $action['reason'] ?? 'Batch rejected',
        ]);

        AuditLog::record('correction.rejected', $correction);

        return ['status' => 'rejected'];
    }

    private function getTeamIds($employee): array
    {
        if (! $employee) {
            return [];
        }

        return Employee::query()
            ->where('supervisor_id', $employee->id)
            ->pluck('id')
            ->toArray();
    }
}
