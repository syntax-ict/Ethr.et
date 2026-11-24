<?php

declare(strict_types=1);

namespace App\Http\Requests\Migration;

use App\Services\Migration\StagedChanges;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Accepting the differences of the named fields for many rows at once. The
 * fields are the ones a reviewer may accept on one row, from the same list.
 * Nothing is accepted that is not named.
 */
class AcceptDifferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', 'distinct', Rule::in(StagedChanges::acceptable())],
            'cursor' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return list<string> */
    public function fields(): array
    {
        return array_values(array_map(strval(...), (array) $this->validated('fields')));
    }

    public function cursor(): int
    {
        return (int) ($this->validated('cursor') ?? 0);
    }
}
