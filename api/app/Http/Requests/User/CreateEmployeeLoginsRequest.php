<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class CreateEmployeeLoginsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize(...) runs in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'employee_ids.*' => ['string', 'size:26', 'distinct'],
        ];
    }
}
