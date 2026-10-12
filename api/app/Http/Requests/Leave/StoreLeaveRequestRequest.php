<?php

declare(strict_types=1);

namespace App\Http\Requests\Leave;

use App\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'leave_type_public_id' => ['required', 'string', 'exists:leave_types,public_id,is_active,1'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'attachment_path' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The `exists` rule above is unscoped, so another tenant's active leave
     * type passed validation and the controller's scoped lookup then answered
     * 404 — a different reply that confirmed the id exists elsewhere
     * (production QA 2026-10-11, P14). This applies the tenant scope and
     * answers with the same message a nonexistent id gets. A hook rather than a
     * rule so `rules()`, which Scramble publishes, stays unchanged — see
     * ValidatesRelationTenancy.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $publicId = $this->input('leave_type_public_id');

            if (! is_string($publicId) || $publicId === '' || $validator->errors()->has('leave_type_public_id')) {
                return;
            }

            if (LeaveType::where('public_id', $publicId)->where('is_active', true)->exists()) {
                return;
            }

            $validator->errors()->add('leave_type_public_id', __('validation.exists', [
                'attribute' => 'leave type public id',
            ]));
        });
    }
}
