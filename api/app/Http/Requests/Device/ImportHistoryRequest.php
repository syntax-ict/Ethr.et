<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Chooses the backfill window for a one-off attendance-history import from a
 * device: a preset (last 30 / 90 days), an explicit from-date, or the full
 * history the device retains. See ONBOARDING_V2.md decision D8.
 */
class ImportHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'window' => ['required', 'string', 'in:last_30,last_90,from_date,full'],
            'from_date' => ['required_if:window,from_date', 'nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
