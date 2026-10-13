<?php

declare(strict_types=1);

namespace App\Http\Requests\Voice;

use App\Models\Recognition;
use Illuminate\Foundation\Http\FormRequest;

class StoreRecognitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'to_employee_public_id' => ['required', 'string', 'max:26'],
            'value' => ['required', 'string', 'in:'.implode(',', Recognition::VALUES)],
            'message' => ['required', 'string', 'max:500'],
        ];
    }
}
