<?php

declare(strict_types=1);

namespace App\Http\Requests\Shift\Concerns;

/**
 * The shift fields, shared by `StoreShiftRequest` and `UpdateShiftRequest`.
 *
 * The two differ only in whether `name`, `start_time` and `end_time` must be
 * present, so that is the one argument. Scramble evaluates `rules()` at
 * runtime, so returning this array publishes the same contract as spelling it
 * out — `api/openapi.json` and `src/src/api/generated.ts` were regenerated and
 * are byte-identical. Keep explanatory comments off the array keys: Scramble
 * publishes them as OpenAPI descriptions.
 */
trait ShiftRules
{
    /**
     * @param  'required'|'sometimes'  $presence
     * @return array<string, list<string>>
     */
    protected function shiftRules(string $presence): array
    {
        return [
            'name' => [$presence, 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'start_time' => [$presence, 'date_format:H:i'],
            'end_time' => [$presence, 'date_format:H:i'],
            'crosses_midnight' => ['boolean'],
            'grace_minutes' => ['integer', 'min:0', 'max:120'],
            'early_departure_minutes' => ['integer', 'min:0', 'max:120'],
            'break_minutes' => ['integer', 'min:0', 'max:180'],
            'working_days' => ['string', 'regex:/^[1-7](,[1-7])*$/'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
