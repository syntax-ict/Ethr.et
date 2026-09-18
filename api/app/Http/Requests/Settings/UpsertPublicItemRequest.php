<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\TenantPublicItem;
use App\Rules\PublicUrl;
use App\Support\PublicSectionIcons;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for one entry inside a section.
 *
 * Three rules here are load-bearing rather than tidy:
 *
 *  - `link_url` goes through PublicUrl, the same rule the profile's website
 *    field uses: http(s) only, and nothing resolving to a private address. A
 *    `javascript:` value would otherwise become a link the page's own visitors
 *    click.
 *  - `icon` is an allow-list, because the value is interpolated into an SVG
 *    `<use href="#icon-…">` reference where free text is an injection point.
 *  - `meta` admits scalars only, so it stays a place for a stat's figure or an
 *    hours row's times rather than arbitrary nested data no template can read.
 *
 * Alternative text is required too, but not here: the image arrives through
 * its own upload endpoint, so the requirement belongs there, where the file
 * and the text are in the same request. See UploadPublicItemImageRequest.
 */
class UpsertPublicItemRequest extends FormRequest
{
    /** The most entries one section may hold. */
    public const MAX_ITEMS = 24;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'title_am' => ['sometimes', 'nullable', 'string', 'max:160'],
            'body' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'body_am' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'image_alt' => ['sometimes', 'nullable', 'string', 'max:180'],
            'image_alt_am' => ['sometimes', 'nullable', 'string', 'max:180'],

            'icon' => ['sometimes', 'nullable', 'string', Rule::in(PublicSectionIcons::names())],

            'link_url' => ['sometimes', 'nullable', 'string', 'max:255', new PublicUrl],
            'link_label' => ['sometimes', 'nullable', 'string', 'max:160'],
            'link_label_am' => ['sometimes', 'nullable', 'string', 'max:160'],

            'meta' => ['sometimes', 'nullable', 'array'],
            // Structured values only. `meta` exists so a stat can hold a figure
            // and an hours row a pair of times — not as somewhere to put
            // arbitrary nested data that no template knows how to render.
            'meta.*' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'icon.in' => 'That icon is not one of the available icons.',
        ];
    }

    /**
     * Refuse a new entry once the section is full.
     *
     * Checked here rather than in the controller so the caller gets a 422 that
     * names the limit, and so the limit is stated beside the rules it belongs
     * with rather than buried in a branch.
     */
    public function assertSectionHasRoom(int $sectionId): void
    {
        $count = TenantPublicItem::query()->where('section_id', $sectionId)->count();

        if ($count >= self::MAX_ITEMS) {
            abort(422, __('A section may have at most :max entries.', ['max' => self::MAX_ITEMS]));
        }
    }
}
