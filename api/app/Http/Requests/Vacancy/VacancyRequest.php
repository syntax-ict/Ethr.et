<?php

declare(strict_types=1);

namespace App\Http\Requests\Vacancy;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Vacancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Creating or editing a vacancy. A public one is shown before sign-in, so
 * links are https-only and the text is plain (rendered as text, never HTML).
 *
 * `department_id` and `branch_id` are public ids, resolved through the tenant
 * scope: one from another tenant is refused with the same message as one that
 * does not exist, as for announcement targets.
 */
class VacancyRequest extends FormRequest
{
    private const RELATIONS = [
        'department_id' => Department::class,
        'branch_id' => Branch::class,
    ];

    /** @var array<string, int|null> */
    private array $resolved = [];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:150'],
            'employment_type' => [$required, 'string', 'in:'.implode(',', Vacancy::EMPLOYMENT_TYPES)],
            'description' => [$required, 'string', 'max:5000'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:120'],
            'positions' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'visibility' => ['sometimes', 'string', 'in:'.implode(',', Vacancy::VISIBILITIES)],
            'status' => ['sometimes', 'string', 'in:'.implode(',', Vacancy::STATUSES)],
            'closes_on' => ['nullable', 'date_format:Y-m-d', ...($this->input('status') === 'open' ? ['after_or_equal:today'] : [])],
            'apply_email' => ['nullable', 'email', 'max:120'],
            'apply_url' => ['nullable', 'string', 'url:https', 'max:500'],
            'department_id' => ['nullable', 'string', 'max:26'],
            'branch_id' => ['nullable', 'string', 'max:26'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (self::RELATIONS as $field => $model) {
                if (! $this->has($field) || $validator->errors()->has($field)) {
                    continue;
                }

                $publicId = $this->input($field);
                if ($publicId === null || $publicId === '') {
                    $this->resolved[$field] = null;

                    continue;
                }

                $id = $model::query()->where('public_id', $publicId)->value('id');
                if ($id === null) {
                    $validator->errors()->add($field, __('validation.exists', ['attribute' => str_replace('_id', '', $field)]));

                    continue;
                }

                $this->resolved[$field] = (int) $id;
            }
        });
    }

    /**
     * The validated attributes with relation public ids turned into ids.
     *
     * @return array<string, mixed>
     */
    public function vacancyAttributes(): array
    {
        return [...$this->validated(), ...$this->resolved];
    }
}
