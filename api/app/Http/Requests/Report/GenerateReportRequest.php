<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use App\Http\Requests\Report\Concerns\ValidatesReportConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GenerateReportRequest extends FormRequest
{
    use ValidatesReportConfig;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'in:employees,attendance,timesheet,absences,leave,payroll'],
            'sort_dir' => ['sometimes', 'string', 'in:asc,desc'],
            'format' => ['sometimes', 'string', 'in:csv,pdf'],
            ...$this->configRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->checkConfigFields($v, '', $this->input('source'), $this->all()));
    }
}
