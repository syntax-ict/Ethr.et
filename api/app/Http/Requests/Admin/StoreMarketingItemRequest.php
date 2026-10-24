<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\MarketingItem;
use App\Rules\PermittedMarketingClaim;
use App\Support\MarketingContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or edit one row of a landing-page section.
 *
 * Used for both store and update, as StorePlanRequest is: `sometimes` on
 * every field makes the update partial, and `isMethod('post')` decides what
 * is mandatory on create. The rules carry no inline comments, because
 * Scramble publishes a comment beside a rule as that field's description.
 *
 * What the rules do not say, because Scramble publishes one body schema per
 * endpoint and the registry varies by section: which fields a kind uses and
 * how many rows it may hold are enforced in withValidator(). A field a kind
 * does not use is refused rather than stored and ignored, so the console
 * cannot drift from the page.
 *
 *   slug        Stable identity for the seeder and for the drawn scene the
 *               page shows when a row has no picture. Set on create only.
 *   icon        One of MarketingContent::ICONS; the page draws nothing for
 *               a name outside it.
 *   link_path   An internal path only, so the public page never links off
 *               site from editable content.
 */
class StoreMarketingItemRequest extends FormRequest
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
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'slug' => $creating
                ? ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]*$/', Rule::unique('marketing_items', 'slug')->where('section_kind', (string) $this->route('kind'))]
                : ['prohibited'],
            'title' => [$required, 'string', 'max:200', new PermittedMarketingClaim],
            'title_am' => ['sometimes', 'nullable', 'string', 'max:200', new PermittedMarketingClaim],
            'body' => ['sometimes', 'nullable', 'string', 'max:1000', new PermittedMarketingClaim],
            'body_am' => ['sometimes', 'nullable', 'string', 'max:1000', new PermittedMarketingClaim],
            'detail' => ['sometimes', 'nullable', 'string', 'max:1000', new PermittedMarketingClaim],
            'detail_am' => ['sometimes', 'nullable', 'string', 'max:1000', new PermittedMarketingClaim],
            'icon' => ['sometimes', 'nullable', 'string', Rule::in(MarketingContent::ICONS)],
            'link_path' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:/^\/[a-z0-9\-\/]*$/'],
            'image_alt' => ['sometimes', 'nullable', 'string', 'max:300', new PermittedMarketingClaim],
            'image_alt_am' => ['sometimes', 'nullable', 'string', 'max:300', new PermittedMarketingClaim],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The kind this row belongs to: from the route on create, from the row
     * on update.
     */
    public function sectionKind(): ?string
    {
        if ($this->isMethod('post')) {
            $kind = (string) $this->route('kind');

            return in_array($kind, MarketingContent::KINDS, true) ? $kind : null;
        }

        $kind = MarketingItem::query()->where('public_id', (string) $this->route('publicId'))->value('section_kind');

        return is_string($kind) ? $kind : null;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $kind = $this->sectionKind();
            if ($kind === null) {
                return;
            }
            $spec = MarketingContent::spec($kind);

            $optional = ['icon', 'body', 'body_am', 'detail', 'detail_am', 'link_path'];
            foreach ($optional as $field) {
                $base = str_ends_with($field, '_am') ? substr($field, 0, -3) : $field;
                if ($this->has($field) && $this->input($field) !== null && ! in_array($base, $spec['fields'], true)) {
                    $validator->errors()->add($field, __('validation.marketing_field_unused', ['kind' => $kind]));
                }
            }
            if (! $spec['images']) {
                foreach (['image_alt', 'image_alt_am'] as $field) {
                    if ($this->has($field) && $this->input($field) !== null) {
                        $validator->errors()->add($field, __('validation.marketing_field_unused', ['kind' => $kind]));
                    }
                }
            }

            if ($this->isMethod('post')) {
                $count = MarketingItem::query()->where('section_kind', $kind)->count();
                if ($spec['max_items'] === 0 || $count >= $spec['max_items']) {
                    $validator->errors()->add('slug', __('validation.marketing_items_max', ['kind' => $kind, 'max' => $spec['max_items']]));
                }
            }
        });
    }
}
