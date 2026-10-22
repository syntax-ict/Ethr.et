<?php

declare(strict_types=1);

namespace App\Http\Requests\Offboarding;

use App\Models\ExitClearanceItem;
use App\Support\ExitClearanceTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The organisation's exit clearance lines. At most one line may show the
 * leaver's outstanding balance, since a second would show the same figure.
 */
class UpdateExitClearanceTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.ExitClearanceTemplate::MAX_ITEMS],
            'items.*.key' => ['nullable', 'string', 'max:40'],
            'items.*.label' => ['required', 'string', 'min:2', 'max:160'],
            'items.*.owner' => ['required', 'string', Rule::in(ExitClearanceItem::OWNERS)],
            'items.*.kind' => ['nullable', 'string', Rule::in([ExitClearanceItem::KIND_MANUAL, ExitClearanceItem::KIND_OBLIGATIONS])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $kinds = array_column((array) $this->input('items', []), 'kind');
            $obligations = array_filter($kinds, fn ($kind) => $kind === ExitClearanceItem::KIND_OBLIGATIONS);

            if (count($obligations) > 1) {
                $validator->errors()->add('items', __('exit_clearance.one_obligations_line'));
            }
        });
    }
}
