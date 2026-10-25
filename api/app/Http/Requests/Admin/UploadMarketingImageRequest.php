<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Rules\VerifyFileContent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A picture for a landing-page row or for the hero.
 *
 * Raster only, as the organisation's brand images: SVG can carry script and
 * is refused. The content check reads the file's own bytes, and
 * FileStorageService::uploadMarketingImage() re-encodes what passes, so the
 * public copy is only pixels. 5 MB and 4096 px bound the decode; the live
 * host accepts 2 MB per request, so the console shrinks first. 640 by 360
 * is the smallest picture the page can draw without upscaling.
 */
class UploadMarketingImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'max:5120',
                'mimes:png,jpg,jpeg,webp',
                'mimetypes:image/png,image/jpeg,image/webp',
                'dimensions:min_width=640,min_height=360,max_width=4096,max_height=4096',
                new VerifyFileContent,
            ],
        ];
    }
}
