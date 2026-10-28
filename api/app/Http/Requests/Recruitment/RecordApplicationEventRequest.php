<?php

declare(strict_types=1);

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A note, or an interview: when it is or was, and the panel's assessment out
 * of 100 if they gave one.
 */
class RecordApplicationEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $interview = $this->input('type') === 'interview';

        return [
            'type' => ['required', 'string', 'in:note,interview'],
            'note' => [$interview ? 'nullable' : 'required', 'string', 'max:5000'],
            'score' => [$interview ? 'nullable' : 'prohibited', 'integer', 'min:0', 'max:100'],
            'scheduled_at' => [$interview ? 'required' : 'prohibited', 'date'],
        ];
    }
}
