<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A logo or hero image for the tenant's public landing page.
 *
 * Its own endpoint, and a POST, for the same reason UploadProfilePhotoRequest
 * is: PHP does not populate $_FILES on a PUT, so a file field on the JSON
 * settings endpoint could never arrive however the client sent it.
 *
 * GIF is absent from the accepted types deliberately. The other three cover
 * every real logo — PNG and WebP for transparency, JPEG for photographs — and
 * an animated logo on an organisation's public page is a support question, not
 * a feature. Narrower input is also less for FileStorageService's decoder to
 * be handed.
 */
class UploadPublicImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // VerifyUploadedFiles has already checked the magic bytes against
            // the declared type by the time this runs, and FileStorageService
            // strips EXIF and re-encodes. These rules keep obviously wrong
            // uploads out of that path rather than being the only defence.
            //
            // 2 MB rather than the 5 MB profile photos allow: this image is
            // fetched by every anonymous visitor on a connection that is often
            // mobile data, so the ceiling is a page-weight decision as much as
            // a storage one.
            'image' => ['required', 'image', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'image.max' => 'The image must be 2 MB or smaller.',
            'image.mimes' => 'The image must be a JPG, PNG or WebP file.',
        ];
    }
}
