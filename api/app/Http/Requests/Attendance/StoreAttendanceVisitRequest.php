<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Rules\Base64Image;
use App\Services\FileStorageService;
use App\Support\PhotoConsent;
use App\Support\PunchSignals;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAttendanceVisitRequest extends FormRequest
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
            'occurred_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'photo' => ['nullable', 'string', new Base64Image(FileStorageService::SELFIE_UPLOAD_MAX_BYTES)],
            ...PunchSignals::rules(),
        ];
    }

    /**
     * A photo of a person is sensitive under Proclamation 1321/2024: none is
     * kept from someone who has not agreed, as for check-in selfies.
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
