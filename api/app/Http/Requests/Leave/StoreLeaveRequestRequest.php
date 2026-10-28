<?php

declare(strict_types=1);

namespace App\Http\Requests\Leave;

use App\Models\Employee;
use App\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
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
            'employee_public_id' => ['nullable', 'string'],
            'start_date' => ['required', 'date'],
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
            $this->checkFiledFor($validator);

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

    /** Whether the request is filed for someone else, by HR. */
    public function onBehalf(): bool
    {
        return is_string($this->input('employee_public_id')) && $this->input('employee_public_id') !== '';
    }

    /** The named employee when filed on someone's behalf; null otherwise or when none resolves. */
    public function filedFor(): ?Employee
    {
        return $this->onBehalf()
            ? Employee::where('public_id', (string) $this->input('employee_public_id'))->first()
            : null;
    }

    /**
     * An employee asks for leave ahead: a start date in the past is refused,
     * as `after_or_equal:today` did here before. HR filing on someone's behalf
     * may record a day already gone, which is how an absence found when
     * reconciling attendance is settled as leave, unpaid or excused.
     */
    private function checkFiledFor(Validator $validator): void
    {
        if ($validator->errors()->has('start_date')) {
            return;
        }

        if ($this->onBehalf()) {
            if ($this->filedFor() === null) {
                $validator->errors()->add('employee_public_id', __('validation.exists', [
                    'attribute' => 'employee public id',
                ]));
            }

            return;
        }

        if (Carbon::parse((string) $this->input('start_date'))->startOfDay()->lt(Carbon::today())) {
            $validator->errors()->add('start_date', __('validation.after_or_equal', [
                'attribute' => 'start date',
                'date' => 'today',
            ]));
        }
    }
}
