<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Services\Analytics\AlertEvaluator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAlertThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'metric' => ['required', 'string', Rule::in(array_keys(AlertEvaluator::METRICS))],
            'operator' => ['required', 'string', Rule::in(AlertEvaluator::OPERATORS)],
            'threshold_value' => ['required', 'numeric'],
            'severity' => ['required', 'string', Rule::in(['warning', 'critical'])],
        ];
    }
}
