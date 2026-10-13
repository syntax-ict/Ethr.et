<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Http\Requests\Employee\Concerns\ValidatesRelationTenancy;
use App\Services\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    // The seven relation `exists` rules (the trait's relationRules()) are
    // unscoped, because Laravel's presence verifier ignores global scopes. The
    // tenant check is a withValidator() hook so that rules() — and therefore
    // the generated API contract — is untouched. See the trait.
    use ValidatesRelationTenancy;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * `hire_date` may be up to a year ahead, for a signed offer; 2099 is a
     * typo (QA 2026-10-13).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'name' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
            'employee_code' => [
                'nullable', 'string', 'max:30',
                Rule::unique('employees')->where('tenant_id', $tenantId),
            ],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date', 'before:today', $this->oldEnoughToWork()],
            'nationality' => ['nullable', 'string', 'max:100'],
            // Encrypted at rest; a blind index drives duplicate detection during
            // import and device sync (see IDENTITY_RESOLUTION.md).
            'national_id' => ['nullable', 'string', 'max:50'],
            'marital_status' => ['nullable', 'string', Rule::in(['single', 'married', 'divorced', 'widowed'])],
            'status' => ['nullable', 'string', Rule::enum(EmployeeStatus::class)],
            'hire_date' => ['required', 'date', 'before_or_equal:'.now()->addYear()->toDateString()],
            'probation_end_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'salary_cents' => ['nullable', 'integer', 'min:0'],
            'tin' => ['nullable', 'string', 'max:20'],
            ...$this->relationRules(),

            // Optionally provision a login account for this employee. Requires an
            // email; the person receives an activation link to set their password.
            'create_login' => ['sometimes', 'boolean'],
            'user_role' => ['sometimes', 'string', Rule::enum(UserRole::class)],
        ];
    }

    /**
     * Ethiopian law forbids employing anyone under 15 (Labour Proclamation
     * 1156/2019, art. 89). A date of birth that makes the person younger
     * than that on their hire date is a typo or a violation either way.
     */
    private function oldEnoughToWork(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $hire = $this->input('hire_date');
            if (! is_string($value) || ! is_string($hire) || strtotime($value) === false || strtotime($hire) === false) {
                return;
            }
            if (Carbon::parse($value)->addYears(15)->gt(Carbon::parse($hire))) {
                $fail(__('validation.employee_minimum_age'));
            }
        };
    }
}
