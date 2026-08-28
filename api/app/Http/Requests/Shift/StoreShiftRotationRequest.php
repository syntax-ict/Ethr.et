<?php

declare(strict_types=1);

namespace App\Http\Requests\Shift;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreShiftRotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            // 366 keeps a cycle inside a year: beyond that it is not a rotation
            // but a one-off calendar, and the step list becomes unmanageable.
            'cycle_days' => ['required', 'integer', 'min:1', 'max:366'],
            'is_active' => ['boolean'],

            'steps' => ['required', 'array', 'min:1'],
            'steps.*.day_offset' => ['required', 'integer', 'min:0'],
            // Null is a rest day, so nullable is meaningful here rather than
            // merely permissive.
            'steps.*.shift_id' => ['nullable', 'string', 'exists:shifts,public_id'],
        ];
    }

    /**
     * Cross-field rules the per-field ones cannot express: an offset must fall
     * inside the cycle, and each offset may appear once. Both would otherwise
     * surface as a database constraint violation (a 500) instead of a 422, and
     * an out-of-range offset is worse than that — it is simply unreachable, so
     * the rotation would quietly never use it.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $cycle = (int) $this->input('cycle_days');
                $steps = $this->input('steps');

                if (! is_array($steps) || $cycle < 1) {
                    return;
                }

                $seen = [];

                foreach ($steps as $i => $step) {
                    $offset = $step['day_offset'] ?? null;

                    if (! is_int($offset) && ! ctype_digit((string) $offset)) {
                        continue;
                    }

                    $offset = (int) $offset;

                    if ($offset >= $cycle) {
                        $validator->errors()->add(
                            "steps.{$i}.day_offset",
                            "Day offset {$offset} falls outside a {$cycle}-day cycle, so it would never be reached.",
                        );
                    }

                    if (in_array($offset, $seen, true)) {
                        $validator->errors()->add(
                            "steps.{$i}.day_offset",
                            "Day offset {$offset} is defined more than once.",
                        );
                    }

                    $seen[] = $offset;
                }
            },
        ];
    }
}
