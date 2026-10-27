<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Models\AttendanceSetting;
use App\Rules\IpOrCidr;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'ot_daily_cap_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'overtime_requires_approval' => ['sometimes', 'boolean'],
            'lateness_deduction_enabled' => ['sometimes', 'boolean'],
            'lateness_deduction_basis' => ['sometimes', 'nullable', 'string', 'max:500'],
            'reconciliation_mode' => ['sometimes', 'string', 'in:off,advisory,required'],
            'confidence_threshold' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'reminders_enabled' => ['sometimes', 'boolean'],
            'reminder_lead_minutes' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'auto_checkout_enabled' => ['sometimes', 'boolean'],
            'auto_checkout_after_minutes' => ['sometimes', 'integer', 'min:15', 'max:720'],
            'geofence_auto_checkin_enabled' => ['sometimes', 'boolean'],
            'photo_retention_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'risk_review_threshold' => ['sometimes', 'integer', 'min:10', 'max:100'],
            'adaptive_selfie' => ['sometimes', 'boolean'],
            'liveness_check' => ['sometimes', 'boolean'],
            'device_binding' => ['sometimes', 'string', 'in:off,flag,enforce'],
            'office_networks' => ['sometimes', 'nullable', 'array', 'max:20'],
            'office_networks.*' => ['string', new IpOrCidr],
        ];
    }

    /**
     * Lateness deductions take pay, which Proclamation 1156/2019 Art. 59
     * allows only on a stated basis (law, a collective agreement, the work
     * rules, or the worker's written consent). Switching them on therefore
     * needs that basis in writing, either sent now or already saved. A hook,
     * so `rules()`, which Scramble publishes, stays as it is.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('lateness_deduction_enabled')) {
                return;
            }

            $basis = $this->has('lateness_deduction_basis')
                ? $this->input('lateness_deduction_basis')
                : AttendanceSetting::query()->value('lateness_deduction_basis');

            if (! is_string($basis) || trim($basis) === '') {
                $validator->errors()->add('lateness_deduction_basis', __('attendance.lateness_basis_required'));
            }
        });
    }
}
