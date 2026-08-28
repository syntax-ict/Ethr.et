<?php

declare(strict_types=1);

namespace App\Http\Requests\Shift;

use Illuminate\Foundation\Http\FormRequest;

class AssignShiftRotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rotation_id' => ['required', 'string', 'exists:shift_rotations,public_id'],
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
