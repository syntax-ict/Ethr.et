<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Rules\Base64Image;
use App\Services\FileStorageService;
use App\Support\DeviceProof;
use App\Support\PhotoConsent;
use App\Support\PunchSignals;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MobileCheckOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:64'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'photo' => ['nullable', 'string', new Base64Image(FileStorageService::SELFIE_UPLOAD_MAX_BYTES)],
            ...PunchSignals::rules(),
            ...DeviceProof::rules(),
            'liveness' => ['nullable', 'string', 'in:passed,failed,no_face,unavailable'],
        ];
    }

    /**
     * The check-out selfie is the same facial image as the check-in one, so
     * it needs the same consent (PhotoConsent); only the check-in asked for
     * it (A-Z review D-03). A hook, so the published contract is unchanged.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $v->errors()->has('photo') && filled($this->input('photo')) && ! PhotoConsent::given($this->user())) {
                $v->errors()->add('photo', __('attendance.photo_consent_required'));
            }
        });
    }
}
