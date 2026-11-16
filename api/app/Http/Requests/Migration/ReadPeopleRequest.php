<?php

declare(strict_types=1);

namespace App\Http\Requests\Migration;

use App\Http\Requests\Migration\Concerns\ReadsPeopleGrid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReadPeopleRequest extends FormRequest
{
    use ReadsPeopleGrid;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // `complete` reads the list as one that adds nobody: it has no sign-in column.
        $rules = $this->gridRules('required');

        return [...$rules, 'mode' => ['sometimes', 'nullable', 'string', 'in:complete']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isEmpty()) {
                $this->checkSource($validator);
            }
        });
    }
}
