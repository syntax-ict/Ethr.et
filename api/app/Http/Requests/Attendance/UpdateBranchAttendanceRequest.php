<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A branch's attendance overrides. Every key is optional and nullable: a key
 * left out or sent as null is inherited from the organisation. The limits
 * match UpdateAttendanceSettingRequest's.
 */
class UpdateBranchAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'overrides' => ['present', 'array'],
            'overrides.enabled_methods' => ['nullable', 'array', 'min:1'],
            'overrides.enabled_methods.*' => ['string', 'in:biometric,mobile,qr,kiosk,web,manual,csv'],
            'overrides.geofence_required' => ['nullable', 'boolean'],
            'overrides.mobile_photo_required' => ['nullable', 'boolean'],
            'overrides.kiosk_pin_required' => ['nullable', 'boolean'],
            'overrides.mobile_accuracy_threshold_meters' => ['nullable', 'integer', 'min:10', 'max:5000'],
            'overrides.auto_checkout_enabled' => ['nullable', 'boolean'],
            'overrides.auto_checkout_after_minutes' => ['nullable', 'integer', 'min:15', 'max:720'],
            'overrides.geofence_auto_checkin_enabled' => ['nullable', 'boolean'],
        ];
    }
}
