<?php

declare(strict_types=1);

namespace App\Http\Requests\Shift;

use App\Http\Requests\Shift\Concerns\ValidatesShiftAssignmentTenancy;
use App\Models\ShiftRotation;
use Illuminate\Foundation\Http\FormRequest;

class AssignShiftRotationRequest extends FormRequest
{
    // `rotation_id` and `assignable_id` are checked against the current tenant
    // by this hook, not by an `exists:` rule: the presence verifier ignores the
    // tenant scope, so `exists:shift_rotations,public_id` accepted another
    // tenant's rotation. A line comment, not a class docblock: Scramble
    // publishes a FormRequest's docblock into the contract.
    use ValidatesShiftAssignmentTenancy;

    public function authorize(): bool
    {
        return true;
    }

    protected function scheduleField(): string
    {
        return 'rotation_id';
    }

    protected function scheduleModel(): string
    {
        return ShiftRotation::class;
    }

    protected function assignableField(): string
    {
        return 'assignable_id';
    }

    public function rules(): array
    {
        return [
            'rotation_id' => ['required', 'string'],
            'assignable_type' => ['required', 'string', 'in:employee,department,branch'],
            'assignable_id' => ['required', 'string'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            // The date day_offset 0 falls on. Defaults to effective_from when
            // omitted, which is what a caller almost always means; it is
            // separate because a rotation may start mid-cycle when an employee
            // joins a team already part-way through its pattern.
            'anchor_date' => ['nullable', 'date'],
        ];
    }
}
