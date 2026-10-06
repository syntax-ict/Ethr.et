<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'saved_report_public_id' => ['required', 'string'],
            'frequency' => ['required', 'string', 'in:daily,weekly,monthly'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['email'],
        ];
    }
}
