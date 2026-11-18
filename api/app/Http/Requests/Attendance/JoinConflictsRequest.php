<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class JoinConflictsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.resolveConflicts') === true;
    }

    public function rules(): array
    {
        return [
            'conflict_ids' => ['required_without:employee_public_id', 'prohibits:employee_public_id', 'array', 'min:1', 'max:200'],
            'conflict_ids.*' => ['string', 'size:26', 'distinct'],
            'employee_public_id' => ['required_without:conflict_ids', 'prohibits:conflict_ids', 'string', 'size:26'],
            'after' => ['nullable', 'string', 'size:26'],
        ];
    }
}
