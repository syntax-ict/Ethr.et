<?php

declare(strict_types=1);

namespace App\Http\Requests\Shift;

use App\Http\Requests\Shift\Concerns\ValidatesShiftAssignmentTenancy;
use App\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;

class AssignShiftRequest extends FormRequest
{
    // `shift_public_id` and `assignable_public_id` are checked against the
    // current tenant by this hook, so an unknown or foreign id is a 422 on its
    // field rather than a 404 from the controller. A line comment, not a class
    // docblock: Scramble publishes a FormRequest's docblock into the contract.
    use ValidatesShiftAssignmentTenancy;

    public function authorize(): bool
    {
        return true;
    }

    protected function scheduleField(): string
    {
        return 'shift_public_id';
    }

    protected function scheduleModel(): string
    {
        return Shift::class;
    }

    protected function assignableField(): string
    {
        return 'assignable_public_id';
    }

    public function rules(): array
    {
        return [
            'shift_public_id' => ['required', 'string', 'size:26'],
            'assignable_type' => ['required', 'string', 'in:employee,department,branch'],
            'assignable_public_id' => ['required', 'string', 'size:26'],
            'effective_from' => ['required', 'date', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }
}
