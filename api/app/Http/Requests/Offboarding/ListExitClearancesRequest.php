<?php

declare(strict_types=1);

namespace App\Http\Requests\Offboarding;

use App\Models\ExitClearance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtering the exit clearance list; the controller decides whose it shows. */
class ListExitClearancesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in([ExitClearance::STATUS_OPEN, ExitClearance::STATUS_COMPLETE])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
