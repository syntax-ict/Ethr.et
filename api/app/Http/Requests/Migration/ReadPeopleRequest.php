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
        return $this->gridRules('required');
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
