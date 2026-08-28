<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformSetting
 */
class PlatformSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'bank_name' => $this->bank_name,
            'bank_account_number' => $this->bank_account_number,
            'bank_account_name' => $this->bank_account_name,
            'payment_instructions' => $this->payment_instructions,
            'payment_instructions_am' => $this->payment_instructions_am,
            'is_configured' => $this->hasPaymentDetails(),
            'updated_at' => $this->updated_at,
        ];
    }
}
