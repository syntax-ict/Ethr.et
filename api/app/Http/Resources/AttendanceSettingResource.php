<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'enabled_methods' => $this->enabled_methods,
            'geofence_required' => $this->geofence_required,
            'mobile_photo_required' => $this->mobile_photo_required,
            'kiosk_pin_required' => $this->kiosk_pin_required,
            'qr_expiry_minutes' => $this->qr_expiry_minutes,
            'qr_auto_refresh' => $this->qr_auto_refresh,
            'qr_single_use_limit' => $this->qr_single_use_limit,
            'mobile_accuracy_threshold_meters' => $this->mobile_accuracy_threshold_meters,
            'offline_sync_enabled' => $this->offline_sync_enabled,
            'kiosk_auto_reset_seconds' => $this->kiosk_auto_reset_seconds,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
