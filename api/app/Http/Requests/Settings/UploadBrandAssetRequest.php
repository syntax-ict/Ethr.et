<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Rules\VerifyFileContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * One of an organisation's other brand images: a logo for dark backgrounds,
 * a square mark, or a cover photo for its portal. The kind is in the route.
 *
 * Raster only, as the logo: SVG can carry script and is refused. The content
 * check reads the file's own bytes, and FileStorageService::uploadBrandImage()
 * re-encodes what passes, so the public copy is only pixels. 5 MB and
 * 4096 px bound the decode on shared hosting; a cover narrower than the
 * phone-width variant it would be served at is refused in withValidator(),
 * which Scramble does not read, so the contract stays one rule set.
 */
class UploadBrandAssetRequest extends FormRequest
{
    public const COVER_MIN_WIDTH = 960;

    public function authorize(): bool
    {
        // Gate::authorize('settings.manage') runs in the controller.
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'max:5120',
                'mimes:png,jpg,jpeg,webp',
                'mimetypes:image/png,image/jpeg,image/webp',
                'dimensions:min_width=32,min_height=32,max_width=4096,max_height=4096',
                new VerifyFileContent,
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->route('kind') !== 'cover' || $validator->errors()->has('image')) {
                return;
            }
            $file = $this->file('image');
            $size = $file === null ? false : @getimagesize($file->getPathname());
            if ($size !== false && $size[0] < self::COVER_MIN_WIDTH) {
                $validator->errors()->add('image', __('validation.cover_too_narrow', ['width' => self::COVER_MIN_WIDTH]));
            }
        });
    }
}
