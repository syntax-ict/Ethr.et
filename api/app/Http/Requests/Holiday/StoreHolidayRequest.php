<?php

declare(strict_types=1);

namespace App\Http\Requests\Holiday;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'branch_public_id' => ['nullable', 'string'],
            'ethiopian_calendar' => ['nullable', 'boolean'],
            'recurring' => ['nullable', 'boolean'],
            'is_estimated' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The branch must exist in this tenant. An unscoped
     * `exists:branches,public_id` let another tenant's branch through, which
     * then became a tenant-wide holiday, and told the caller the id existed
     * somewhere (audit N75).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $publicId = $this->input('branch_public_id');
            if (is_string($publicId) && $publicId !== ''
                && ! Branch::where('public_id', $publicId)->exists()) {
                $validator->errors()->add('branch_public_id', __('validation.exists', [
                    'attribute' => 'branch public id',
                ]));
            }
        });
    }
}
