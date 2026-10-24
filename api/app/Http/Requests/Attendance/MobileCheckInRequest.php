<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Rules\Base64Image;
use App\Services\Attendance\AttendancePolicy;
use App\Services\FileStorageService;
use App\Support\PhotoConsent;
use App\Support\PunchSignals;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class MobileCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:64'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            // Selfie captured on the device, sent inline as a data URL. The
            // stored object key comes back from FileStorageService — clients
            // never supply `photo_path` themselves.
            'photo' => ['nullable', 'string', new Base64Image(FileStorageService::SELFIE_UPLOAD_MAX_BYTES)],
            'auto' => ['sometimes', 'boolean'],
            ...PunchSignals::rules(),
        ];
    }

    /**
     * `mobile_photo_required` was stored, editable and shown on the settings
     * screen, and enforced nowhere: the phone labelled the selfie "optional"
     * and the server accepted a check-in without one. A hook rather than a
     * rule, so the published contract is unchanged.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->has('photo')) {
                return;
            }

            // A selfie is a facial image, sensitive under Proclamation
            // 1321/2024: none is taken from someone who has not agreed.
            if (filled($this->input('photo'))) {
                if (! PhotoConsent::given($this->user())) {
                    $v->errors()->add('photo', __('attendance.photo_consent_required'));
                }

                return;
            }

            $employee = $this->user()?->employee;
            $policy = $employee !== null
                ? app(AttendancePolicy::class)->for($employee)
                : app(AttendancePolicy::class)->organisation();

            if ($policy->mobile_photo_required === true) {
                $v->errors()->add('photo', __('attendance.photo_required'));
            }
        });
    }
}
