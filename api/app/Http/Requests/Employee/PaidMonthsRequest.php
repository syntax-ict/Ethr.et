<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class PaidMonthsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // $this->authorize('view', $employee) runs in the controller.
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
