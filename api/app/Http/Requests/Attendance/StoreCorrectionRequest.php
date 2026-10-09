<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Models\AttendanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attendance_record_public_id' => ['required', 'string', 'exists:attendance_records,public_id'],
            'reason' => ['required', 'string', 'max:1000'],
            'proposed_check_in' => ['nullable', 'date'],
            'proposed_check_out' => ['nullable', 'date'],
        ];
    }

    /**
     * The `exists` rule above goes through Laravel's presence verifier, which
     * applies no global scope, so another tenant's record id passed it and
     * reached the controller's scoped lookup as a 404 — while a nonexistent id
     * was a 422. The difference confirmed that the id exists somewhere. This
     * re-runs the lookup under the tenant scope and answers with the very
     * message a nonexistent id gets, the `ValidatesRelationTenancy` pattern.
     * It is a hook rather than a rule so `rules()`, which Scramble publishes,
     * stays unchanged.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $field = 'attendance_record_public_id';
            $publicId = $this->input($field);

            if (! is_string($publicId) || $validator->errors()->has($field)) {
                return;
            }

            if (AttendanceRecord::where('public_id', $publicId)->exists()) {
                return;
            }

            $validator->errors()->add($field, __('validation.exists', [
                'attribute' => str_replace('_', ' ', $field),
            ]));
        });
    }
}
