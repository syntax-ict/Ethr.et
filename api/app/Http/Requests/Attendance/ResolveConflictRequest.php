<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Enums\ConflictResolutionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveConflictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution' => [
                'required',
                'string',
                Rule::in([
                    ConflictResolutionStatus::KEEP_A->value,
                    ConflictResolutionStatus::KEEP_B->value,
                    ConflictResolutionStatus::MERGED->value,
                    ConflictResolutionStatus::DISMISSED->value,
                ]),
            ],
            'resolution_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
