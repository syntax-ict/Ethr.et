<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The organisation's sign-in page: its own words, help contacts and panel
 * background. Everything here is shown publicly, before sign-in.
 *
 * The photo background draws the cover the organisation uploaded through
 * `POST /settings/branding/assets/cover`; there is no image URL to give.
 * Choosing it before a cover exists is refused in withValidator(), which
 * Scramble does not read, so the contract stays a plain rule set.
 */
class UpdateLandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'headline' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:280'],
            'support_email' => ['nullable', 'email', 'max:120'],
            'support_phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ()-]{6,24}$/'],
            'background' => ['required', 'string', 'in:brand,gradient,pattern,image'],
            'sections' => ['sometimes', 'array:announcements,vacancies,holidays'],
            'sections.announcements' => ['sometimes', 'boolean'],
            'sections.vacancies' => ['sometimes', 'boolean'],
            'sections.holidays' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('background') !== 'image' || $validator->errors()->has('background')) {
                return;
            }
            if (app(CurrentTenant::class)->get()->brandAsset('cover') === null) {
                $validator->errors()->add('background', __('validation.landing_cover_missing'));
            }
        });
    }
}
