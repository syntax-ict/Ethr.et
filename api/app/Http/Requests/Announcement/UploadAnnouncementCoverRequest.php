<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Http\Requests\Settings\UploadBrandAssetRequest;
use App\Rules\VerifyFileContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A news story's cover photo, on the terms the organisation's own cover
 * photo is taken on (UploadBrandAssetRequest): raster only, the file's own
 * bytes checked, 5 MB and 4096 px to bound the decode on shared hosting, and
 * at least as wide as the widest copy a page asks for. What passes is
 * re-encoded by FileStorageService::uploadNewsCover(), so only pixels are
 * served.
 */
class UploadAnnouncementCoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gate::authorize('announcement.manage') runs in the controller.
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
            if ($validator->errors()->has('image')) {
                return;
            }
            $file = $this->file('image');
            $size = $file === null ? false : @getimagesize($file->getPathname());
            if ($size !== false && $size[0] < UploadBrandAssetRequest::COVER_MIN_WIDTH) {
                $validator->errors()->add('image', __('validation.cover_too_narrow', ['width' => UploadBrandAssetRequest::COVER_MIN_WIDTH]));
            }
        });
    }
}
