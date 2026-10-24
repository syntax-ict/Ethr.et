<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The new order of a section's rows: every row's public id, first to last.
 * An id from another section is refused.
 */
class ReorderMarketingItemsRequest extends FormRequest
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
            'order' => ['required', 'array', 'min:1', 'max:12'],
            'order.*' => ['string', 'distinct', Rule::exists('marketing_items', 'public_id')->where('section_kind', (string) $this->route('kind'))],
        ];
    }
}
