<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use App\Http\Requests\Report\Concerns\ValidatesReportConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveReportRequest extends FormRequest
{
    use ValidatesReportConfig;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'config' => ['required', 'array'],
            'config.source' => ['required', 'string', 'in:employees,attendance,timesheet,absences,leave,payroll'],
            ...$this->configRules('config.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->checkConfigFields($v, 'config.', $this->input('config.source'), $this->input('config')));
    }
}
