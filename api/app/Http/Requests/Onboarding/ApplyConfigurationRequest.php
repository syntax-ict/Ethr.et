<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Services\Onboarding\IndustryCatalog;
use App\Services\Onboarding\PolicyProfileCatalog;
use App\Services\Onboarding\WorkforceProfileCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Accepts a (possibly edited) configuration plan for provisioning. Item shapes
 * are validated only loosely here — OrganizationProvisioner normalizes every
 * entry defensively — but array sizes are capped so an admin cannot push an
 * abusive payload through the create path.
 */
class ApplyConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'industry' => ['nullable', 'string', Rule::in(app(IndustryCatalog::class)->keys())],
            'workforce_profile' => ['nullable', 'string', Rule::in(app(WorkforceProfileCatalog::class)->keys())],
            'policy_profile' => ['nullable', 'string', Rule::in(app(PolicyProfileCatalog::class)->keys())],
            'plan' => ['required', 'array'],
            'plan.branches' => ['sometimes', 'array', 'max:100'],
            'plan.departments' => ['sometimes', 'array', 'max:200'],
            'plan.positions' => ['sometimes', 'array', 'max:200'],
            'plan.grades' => ['sometimes', 'array', 'max:100'],
            'plan.shifts' => ['sometimes', 'array', 'max:50'],
            'plan.leave_types' => ['sometimes', 'array', 'max:100'],
            'plan.rotations' => ['sometimes', 'array', 'max:20'],
            'plan.rotations.*.steps' => ['sometimes', 'array', 'max:366'],
            'plan.allowances' => ['sometimes', 'array', 'max:50'],
            'plan.attendance' => ['sometimes', 'array'],
            'plan.holidays' => ['sometimes'],
            'plan.settings' => ['sometimes', 'array'],
            'save' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * `plan.settings` writes tenant settings, so it is held to what `PUT
     * /settings` accepts: the keys that endpoint may write, under its own
     * rules, plus the `payroll` and `attendance` namespaces templates have
     * always carried and the `timezone` and `locale` sign-up sets. It accepted
     * any key unchecked, so a plan could store a retirement age of 5 or a run
     * day of 31 (onboarding review 2026-10-30). A hook rather than rules, so
     * `rules()` and the generated contract keep describing a plain array.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $settings = $this->input('plan.settings');
            if (! is_array($settings) || $settings === [] || $validator->errors()->isNotEmpty()) {
                return;
            }

            $unknown = array_diff(array_map('strval', array_keys($settings)), self::settingKeys());
            if ($unknown !== []) {
                $validator->errors()->add('plan.settings', __('validation.array', ['attribute' => 'plan.settings']));

                return;
            }

            $update = new UpdateSettingsRequest;
            $rules = array_filter(
                $update->rules(),
                static fn (string $key): bool => str_starts_with($key, 'settings.'),
                ARRAY_FILTER_USE_KEY,
            );

            $check = ValidatorFactory::make(['settings' => $settings], $rules, $update->messages(), $update->attributes());

            foreach ($check->errors()->messages() as $key => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add('plan.'.$key, $message);
                }
            }
        });
    }

    /**
     * The settings a plan may write.
     *
     * @return list<string>
     */
    public static function settingKeys(): array
    {
        return array_values(array_unique([
            ...UpdateSettingsRequest::writableKeys(),
            'payroll',
            'attendance',
            'timezone',
            'locale',
        ]));
    }
}
