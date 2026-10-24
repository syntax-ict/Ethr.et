<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Rules\PermittedMarketingClaim;
use App\Support\MarketingContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Edit a landing-page section's heading, subheading, visibility and, for
 * the hero, the words that describe its picture.
 *
 * The rules carry no inline comments: Scramble publishes a comment beside
 * a rule as that field's description. Every text field takes
 * PermittedMarketingClaim, in both languages. The image alt fields are
 * accepted only for a kind whose registry entry allows a section image,
 * checked in withValidator(), which Scramble does not read.
 */
class UpdateMarketingSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'heading' => ['sometimes', 'required', 'string', 'max:300', new PermittedMarketingClaim],
            'heading_am' => ['sometimes', 'nullable', 'string', 'max:300', new PermittedMarketingClaim],
            'subheading' => ['sometimes', 'nullable', 'string', 'max:600', new PermittedMarketingClaim],
            'subheading_am' => ['sometimes', 'nullable', 'string', 'max:600', new PermittedMarketingClaim],
            'is_published' => ['sometimes', 'boolean'],
            'image_alt' => ['sometimes', 'nullable', 'string', 'max:300', new PermittedMarketingClaim],
            'image_alt_am' => ['sometimes', 'nullable', 'string', 'max:300', new PermittedMarketingClaim],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $kind = (string) $this->route('kind');
            if (! in_array($kind, MarketingContent::KINDS, true)) {
                return;
            }
            $spec = MarketingContent::spec($kind);
            if ($spec['section_image']) {
                return;
            }
            foreach (['image_alt', 'image_alt_am'] as $field) {
                if ($this->has($field)) {
                    $validator->errors()->add($field, __('validation.marketing_field_unused', ['kind' => $kind]));
                }
            }
        });
    }
}
