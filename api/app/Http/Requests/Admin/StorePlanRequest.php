<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\PlanFeature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a catalog plan.
 *
 * Validation *is* the registry here, as it is for platform settings: there is
 * no key/value store to guard, so what these rules accept is the whole of what
 * an admin can put on the public pricing page.
 *
 * Used for both store and update — `sometimes` on every field makes the update
 * a partial one without a second class whose rules could drift from these.
 * `$this->isMethod('post')` decides which fields are mandatory on create.
 */
class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.manage') ?? false;
    }

    /**
     * The rules below carry no inline comments, deliberately.
     *
     * Scramble builds the public request schema from this method and turns a
     * comment beside a rule into that field's published `@description`, so a
     * maintainer's note becomes API documentation. The notes live here instead:
     *
     *   slug                Immutable after creation, and not merely awkward to
     *                       change: PlanSeeder and AuthService look a plan up by
     *                       slug ('starter'), so editing it detaches the plan
     *                       every new tenant is put on. Accepted on create only.
     *   price_cents         Cents, non-negative. Editing it re-quotes new
     *                       customers and leaves existing subscribers alone —
     *                       see subscriptions.price_cents.
     *   billing_interval    Monthly is the only interval anything implements;
     *                       GenerateMonthlyInvoicesJob runs monthlyOn(1) and
     *                       nothing reads this column yet. An admin offered
     *                       "yearly" in a dropdown has been told the product
     *                       supports annual billing, and it does not.
     *   max_*               Null means no ceiling, which is what
     *                       PlanLimitService has always returned for an uncapped
     *                       resource and what the schema now allows.
     *   features            The enforcement vocabulary, constrained to the enum:
     *                       RequiresPlanFeature compares strings, so 'payrol'
     *                       would deny access with no error anywhere.
     *   marketing_features  Sales copy. Free text by design — the field exists
     *                       so bullets stop being smuggled into `features`.
     *
     * @return array<string, list<string|Rule>>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],

            'slug' => $creating
                ? ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9-]*$/', 'unique:plans,slug']
                : ['prohibited'],

            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description_am' => ['sometimes', 'nullable', 'string', 'max:500'],

            'price_cents' => [$required, 'integer', 'min:0', 'max:9999999999'],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],

            'billing_interval' => ['sometimes', Rule::in(['monthly'])],

            'max_employees' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_branches' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_devices' => ['sometimes', 'nullable', 'integer', 'min:1'],

            'features' => ['sometimes', 'array'],
            'features.*' => ['string', Rule::enum(PlanFeature::class)],

            'marketing_features' => ['sometimes', 'nullable', 'array', 'max:20'],
            'marketing_features.*' => ['string', 'max:120'],
            'marketing_features_am' => ['sometimes', 'nullable', 'array', 'max:20'],
            'marketing_features_am.*' => ['string', 'max:120'],

            'is_active' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'is_popular' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
