<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An image for one entry on the public page, and the text that describes it.
 *
 * The same limits as the hero and logo uploads — 2 MB, JPG/PNG/WebP, no GIF —
 * because these are page-weight decisions and a builder can add two dozen of
 * them. FileStorageService verifies the magic bytes and strips EXIF, so a file
 * whose extension disagrees with its contents is refused there rather than
 * trusted here.
 *
 * `alt` is required, and that is the reason this request exists rather than
 * reusing UploadPublicImageRequest. An uploaded image with no alternative text
 * is a WCAG 1.1.1 failure, on a page whose entire purpose is to be read by the
 * public — including by someone using a screen reader. Making it required at
 * the moment the file arrives is the only point where the two are certainly
 * together; anywhere later and "add it afterwards" quietly becomes "never".
 */
class UploadPublicItemImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
            'alt' => ['required', 'string', 'max:180'],
            'alt_am' => ['sometimes', 'nullable', 'string', 'max:180'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'image.max' => 'The image must be 2 MB or smaller.',
            'image.mimes' => 'The image must be a JPG, PNG or WebP file.',
            'alt.required' => 'Describe the image for visitors who cannot see it.',
        ];
    }
}
