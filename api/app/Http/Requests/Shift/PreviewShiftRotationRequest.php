<?php

declare(strict_types=1);

namespace App\Http\Requests\Shift;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Convention #6: all validation in a dedicated FormRequest, no inline
 * `$request->validate()`.
 *
 * `ShiftRotationController::preview()` was the only controller in the codebase
 * still validating inline — one of 305 validation sites, which is why it
 * survived: a lone exception in an otherwise uniform codebase is invisible to
 * everything except a sweep. `ControllerValidationConventionTest` is now that
 * sweep.
 *
 * NO EXPLANATORY COMMENTS ABOVE THE KEYS IN `rules()`. Scramble generates
 * `src/src/api/generated.ts` from this array and publishes such comments as
 * OpenAPI `description`s — verbatim, into the client contract. The sibling
 * `AssignShiftRotationRequest` carries one above `anchor_date`, and it is
 * readable at `generated.ts:5270` today. Put the explanation on a statement, in
 * this docblock, or in BASELINE.md.
 *
 * On the rules themselves: `anchor_date` is the date day-offset zero falls on.
 * It defaults to `from` when omitted, which is what a caller almost always
 * means; it is a separate parameter because a rotation may start mid-cycle.
 */
class PreviewShiftRotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'anchor_date' => ['nullable', 'date'],
        ];
    }
}
