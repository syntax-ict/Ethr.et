<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Support\NotificationTemplates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateNotificationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_en' => ['nullable', 'string', 'max:200'],
            'subject_am' => ['nullable', 'string', 'max:200'],
            'body_en' => ['nullable', 'string', 'max:2000'],
            'body_am' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Refuse a placeholder the template type does not provide. It would reach
     * the recipient verbatim — `{employe_name}` in the middle of a sentence —
     * so the admin hears about the typo now rather than from an employee.
     * A hook rather than a rule, so `rules()` and the API contract stay as they were.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $type = $this->route('type');

            // An unknown type is the controller's 404, not a validation error.
            if (! is_string($type) || ! NotificationTemplates::exists($type)) {
                return;
            }

            foreach (NotificationTemplates::FIELDS as $field) {
                $value = $this->input($field);
                if (! is_string($value) || $v->errors()->has($field)) {
                    continue;
                }

                $unknown = NotificationTemplates::unknownPlaceholders($type, $value);
                if ($unknown !== []) {
                    $v->errors()->add($field, __('settings.template_unknown_variables', [
                        'variables' => implode(', ', $unknown),
                        'allowed' => implode(', ', array_map(
                            fn (string $name): string => '{'.$name.'}',
                            NotificationTemplates::variables($type),
                        )),
                    ]));
                }
            }
        });
    }
}
