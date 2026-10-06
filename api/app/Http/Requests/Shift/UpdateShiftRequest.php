<?php

declare(strict_types=1);

namespace App\Http\Requests\Shift;

use App\Http\Requests\Shift\Concerns\ShiftRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateShiftRequest extends FormRequest
{
    use ShiftRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->shiftRules('sometimes');
    }
}
