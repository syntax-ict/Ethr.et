<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\NationalityStatus;
use App\Http\Requests\Employee\Concerns\ValidatesRelationTenancy;
use App\Rules\Phone;
use App\Services\CurrentTenant;
use App\Support\TenantTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmployeeRequest extends FormRequest
{
    // Same hook as StoreEmployeeRequest, shared rather than copied so the two
    // cannot drift apart. See the trait.
    use ValidatesRelationTenancy {
        withValidator as private validateRelationTenancy;
    }

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateRelationTenancy($validator);

        // Not after today in the organisation's own zone, which UTC's "today"
        // gets wrong for the first three hours of an Addis Ababa day.
        $validator->after(function (Validator $validator): void {
            $given = $this->input('salary_effective_date');

            if (! is_string($given) || $validator->errors()->has('salary_effective_date')) {
                return;
            }

            $today = TenantTime::today(app(CurrentTenant::class)->get())->toDateString();

            if (Carbon::parse($given)->toDateString() > $today) {
                $validator->errors()->add('salary_effective_date', __('validation.before_or_equal', [
                    'attribute' => __('validation.attributes.salary_effective_date'),
                    'date' => $today,
                ]));
            }
        });
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();
        $employee = $this->route('employee');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', Phone::any()->unlessUnchanged($employee?->phone)],
            'employee_code' => [
                'nullable', 'string', 'max:30',
                Rule::unique('employees')->where('tenant_id', $tenantId)->ignore($employee?->id),
            ],
            'badge_number' => [
                'nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('employees')->where('tenant_id', $tenantId)->ignore($employee?->id),
            ],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'nationality_status' => ['nullable', 'string', Rule::enum(NationalityStatus::class)],
            'national_id' => ['nullable', 'string', 'max:50'],
            'marital_status' => ['nullable', 'string', Rule::in(['single', 'married', 'divorced', 'widowed'])],
            'hire_date' => ['sometimes', 'date', 'before_or_equal:'.now()->addYear()->toDateString()],
            'probation_end_date' => ['nullable', 'date'],
            'salary_cents' => ['nullable', 'integer', 'min:0'],
            'salary_effective_date' => ['nullable', 'date'],
            'tin' => ['nullable', 'string', 'max:20'],
            ...$this->relationRules(),
        ];
    }
}
