<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class FileDisciplinaryAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('manage', DisciplinaryCase) runs in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'grounds' => ['required', 'string', 'max:5000'],
        ];
    }
}
