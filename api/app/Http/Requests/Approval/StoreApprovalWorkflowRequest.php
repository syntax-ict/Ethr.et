<?php

declare(strict_types=1);

namespace App\Http\Requests\Approval;

use App\Enums\ApprovalRequestType;
use App\Enums\ApproverKind;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Department;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A chain of approval steps for one request type (docs/APPROVAL-ENGINE-DESIGN.md).
 *
 * The cross-checks run in withValidator(), not rules(), so the API contract
 * Scramble derives from rules() stays a plain shape. Every public id a
 * workflow names must belong to this organisation; the message is Laravel's
 * generic `validation.exists`, the same for a foreign id as for a missing one.
 */
class StoreApprovalWorkflowRequest extends FormRequest
{
    public const MAX_STEPS = 5;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'request_type' => ['required', Rule::enum(ApprovalRequestType::class)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'conditions' => ['nullable', 'array'],
            'conditions.department_ids' => ['nullable', 'array', 'max:100'],
            'conditions.department_ids.*' => ['string', 'size:26'],
            'conditions.branch_ids' => ['nullable', 'array', 'max:100'],
            'conditions.branch_ids.*' => ['string', 'size:26'],
            'conditions.leave_type_ids' => ['nullable', 'array', 'max:100'],
            'conditions.leave_type_ids.*' => ['string', 'size:26'],
            'steps' => ['required', 'array', 'min:1', 'max:'.self::MAX_STEPS],
            'steps.*.approver_kind' => ['required', Rule::enum(ApproverKind::class)],
            'steps.*.approver_ref' => ['nullable', 'string', 'max:64'],
            'steps.*.sla_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->checkSteps($validator);
            $this->checkConditions($validator);
        });
    }

    private function checkSteps(Validator $validator): void
    {
        /** @var list<array{approver_kind: string, approver_ref?: string|null}> $steps */
        $steps = $this->input('steps', []);

        foreach ($steps as $i => $step) {
            $kind = ApproverKind::from($step['approver_kind']);
            $ref = $step['approver_ref'] ?? null;
            $field = "steps.{$i}.approver_ref";

            if ($kind === ApproverKind::ROLE) {
                $role = UserRole::tryFrom((string) $ref);

                if ($role === null || $role === UserRole::SUPER_ADMIN || $role === UserRole::EMPLOYEE) {
                    $validator->errors()->add($field, __('validation.in', ['attribute' => $field]));
                }
            }

            if ($kind === ApproverKind::USER) {
                $exists = $ref !== null && User::query()
                    ->where('public_id', $ref)
                    ->where('status', 'active')
                    ->exists();

                if (! $exists) {
                    $validator->errors()->add($field, __('validation.exists', ['attribute' => $field]));
                }
            }
        }
    }

    private function checkConditions(Validator $validator): void
    {
        $models = [
            'department_ids' => Department::class,
            'branch_ids' => Branch::class,
            'leave_type_ids' => LeaveType::class,
        ];

        foreach ($models as $key => $model) {
            $ids = array_values(array_unique((array) $this->input("conditions.{$key}", [])));

            if ($ids === []) {
                continue;
            }

            if ($key === 'leave_type_ids' && $this->input('request_type') !== ApprovalRequestType::LEAVE->value) {
                $validator->errors()->add("conditions.{$key}", __('validation.prohibited', ['attribute' => "conditions.{$key}"]));

                continue;
            }

            if ($model::query()->whereIn('public_id', $ids)->count() !== count($ids)) {
                $validator->errors()->add("conditions.{$key}", __('validation.exists', ['attribute' => "conditions.{$key}"]));
            }
        }
    }
}
