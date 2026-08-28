<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttendanceSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled_methods' => ['sometimes', 'array', 'min:1'],
            'enabled_methods.*' => ['string', 'in:biometric,mobile,qr,kiosk,web,manual,csv'],
            'geofence_required' => ['sometimes', 'boolean'],
            'mobile_photo_required' => ['sometimes', 'boolean'],
            'kiosk_pin_required' => ['sometimes', 'boolean'],
            'qr_expiry_minutes' => ['sometimes', 'integer', 'min:5', 'max:480'],
            'qr_auto_refresh' => ['sometimes', 'boolean'],
            'qr_single_use_limit' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'mobile_accuracy_threshold_meters' => ['sometimes', 'integer', 'min:10', 'max:5000'],
            'offline_sync_enabled' => ['sometimes', 'boolean'],
            'kiosk_auto_reset_seconds' => ['sometimes', 'integer', 'min:2', 'max:30'],
            'grace_period_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'ot_daily_cap_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'confidence_threshold' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ];
    }
}
