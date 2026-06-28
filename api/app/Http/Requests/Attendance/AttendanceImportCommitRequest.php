<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceImportCommitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'import_key' => ['required', 'string', 'max:64'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.employee_code' => ['required', 'string'],
            'rows.*.date' => ['required', 'date'],
            'rows.*.check_in' => ['required', 'date_format:H:i'],
            'rows.*.check_out' => ['nullable', 'date_format:H:i'],
        ];
    }
}
