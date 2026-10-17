<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Models\Announcement;
use App\Rules\VerifyFileContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A document for a news story: a PDF, checked by its own bytes (`%PDF`), at
 * most 5 MB, the size a circular or a form needs on shared hosting's quota.
 * A story takes at most Announcement::ATTACHMENTS_MAX, refused in
 * withValidator(), which Scramble does not read.
 */
class UploadAnnouncementFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gate::authorize('announcement.manage') runs in the controller.
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:5120',
                'mimes:pdf',
                'mimetypes:application/pdf',
                new VerifyFileContent,
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $announcement = $this->route('announcement');
            if ($announcement instanceof Announcement
                && count($announcement->attachedFiles()) >= Announcement::ATTACHMENTS_MAX) {
                $validator->errors()->add('file', __('validation.announcement_files_max', ['max' => Announcement::ATTACHMENTS_MAX]));
            }
        });
    }
}
