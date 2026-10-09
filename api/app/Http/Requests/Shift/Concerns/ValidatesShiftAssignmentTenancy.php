<?php

declare(strict_types=1);

namespace App\Http\Requests\Shift\Concerns;

use App\Support\ShiftAssignables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Reject a shift, rotation or assignee `public_id` that the current tenant
 * cannot see, with the same 422 a nonexistent one gets.
 *
 * Two defects, one shape (audit N9):
 *
 *   - `AssignShiftRotationRequest` checked `rotation_id` with
 *     `exists:shift_rotations,public_id`. Laravel's presence verifier does not
 *     apply Eloquent global scopes, so another tenant's rotation passed
 *     validation (and a soft-deleted one did too) and the controller's scoped
 *     `firstOrFail()` then answered 404 — a different answer from the 422 a
 *     made-up id got, which confirms the id exists somewhere.
 *   - Neither assign request checked the assignee at all; an unknown
 *     `assignable_id` / `assignable_public_id` fell through to `firstOrFail()`
 *     and came back 404 instead of a field error.
 *
 * Same pattern as `ValidatesRelationTenancy`, and for the same reasons: a
 * `withValidator()` hook is invisible to Scramble, so the published contract
 * does not move; the lookup is the controller's own scoped query (tenant scope
 * and, where the model has one, the soft-delete scope), so validation and
 * resolution cannot disagree; and the message is Laravel's generic
 * `validation.exists` line, identical for a foreign and a nonexistent id.
 *
 * @mixin FormRequest
 */
trait ValidatesShiftAssignmentTenancy
{
    /** The field naming the shift or rotation, and the model it belongs to. */
    abstract protected function scheduleField(): string;

    /** @return class-string<Model> */
    abstract protected function scheduleModel(): string;

    /** The field naming the employee, department or branch. */
    abstract protected function assignableField(): string;

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->rejectUnlessVisible($validator, $this->scheduleField(), $this->scheduleModel());

            // An unknown `assignable_type` has already failed its `in:` rule,
            // and there is no model to look the id up in.
            $assignableModel = ShiftAssignables::modelFor($this->input('assignable_type'));

            if ($assignableModel !== null) {
                $this->rejectUnlessVisible($validator, $this->assignableField(), $assignableModel);
            }
        });
    }

    /** @param  class-string<Model>  $modelClass */
    private function rejectUnlessVisible(Validator $validator, string $field, string $modelClass): void
    {
        $publicId = $this->input($field);

        // Required / string failures are already reported; one message per field.
        if (! is_string($publicId) || $publicId === '' || $validator->errors()->has($field)) {
            return;
        }

        if ($modelClass::query()->where('public_id', $publicId)->exists()) {
            return;
        }

        $validator->errors()->add($field, __('validation.exists', [
            'attribute' => str_replace('_', ' ', $field),
        ]));
    }
}
