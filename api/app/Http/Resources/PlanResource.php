<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan as the public pricing page sees it.
 *
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // This class exists to make the contract true rather than roughly true.
        //
        // Returning the model directly let Scramble type the response from the
        // model's @property block, so `generated.ts` promised `is_active`,
        // `created_at` and `updated_at` on the public catalog — three fields the
        // controller's column list never selected. The frontend could not have
        // read them, and tsc would have believed it could. That is the same
        // class of defect as the limit columns typed non-nullable: the gate only
        // catches a contract that DRIFTS, not one that was never true.
        //
        // Fields are listed here and nowhere else, so the query's column list
        // and the published shape cannot disagree without this file changing.
        return [
            'public_id' => (string) $this->public_id,
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
            'description' => $this->description,
            'description_am' => $this->description_am,
            'price_cents' => (int) $this->price_cents,
            'currency' => (string) $this->currency,
            'billing_interval' => (string) $this->billing_interval,
            'max_employees' => $this->max_employees,
            'max_branches' => $this->max_branches,
            'max_devices' => $this->max_devices,
            'features' => $this->features,
            'marketing_features' => $this->marketing_features,
            'marketing_features_am' => $this->marketing_features_am,
            'is_popular' => (bool) $this->is_popular,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
