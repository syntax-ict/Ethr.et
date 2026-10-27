<?php

declare(strict_types=1);

namespace App\Http\Requests\Recruitment;

use App\Enums\ApplicationStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving an application to its next stage. An offer needs a start date;
 * rejecting or withdrawing needs a reason, kept on the selection record.
 */
class MoveJobApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $stage = $this->input('stage');
        $closing = in_array($stage, [ApplicationStage::REJECTED->value, ApplicationStage::WITHDRAWN->value], true);

        return [
            'stage' => ['required', 'string', Rule::in([
                ApplicationStage::SCREENING->value,
                ApplicationStage::INTERVIEW->value,
                ApplicationStage::OFFER->value,
                ApplicationStage::REJECTED->value,
                ApplicationStage::WITHDRAWN->value,
            ])],
            'note' => [$closing ? 'required' : 'nullable', 'string', 'max:500'],
            'offer_start_date' => [$stage === ApplicationStage::OFFER->value ? 'required' : 'prohibited', 'date_format:Y-m-d', 'after_or_equal:today'],
            'offer_salary_cents' => [$stage === ApplicationStage::OFFER->value ? 'nullable' : 'prohibited', 'integer', 'min:0'],
            'offer_notes' => [$stage === ApplicationStage::OFFER->value ? 'nullable' : 'prohibited', 'string', 'max:2000'],
        ];
    }
}
