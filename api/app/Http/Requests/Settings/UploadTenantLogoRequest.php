<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Rules\VerifyFileContent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An organisation's logo for its public sign-in page.
 *
 * Raster only: SVG can carry script and is refused. The content check reads
 * the file's own bytes, not the name or the browser's claim, and
 * FileStorageService::uploadLogo() re-encodes what passes, so the public copy
 * is only pixels. 1 MB and 4096 px bound the decode on shared hosting.
 */
class UploadTenantLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gate::authorize('settings.manage') runs in the controller.
        return true;
    }

    public function rules(): array
    {
        return [
            'logo' => [
                'required',
                'file',
                'max:1024',
                'mimes:png,jpg,jpeg,webp',
                'mimetypes:image/png,image/jpeg,image/webp',
                'dimensions:min_width=32,min_height=32,max_width=4096,max_height=4096',
                new VerifyFileContent,
            ],
        ];
    }
}
