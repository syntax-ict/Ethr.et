<?php

declare(strict_types=1);

namespace App\Http\Requests\Leave\Concerns;

use App\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Two checks a rule array cannot express, shared by the store and update
 * requests. A `withValidator()` hook rather than rules, so `rules()` — which
 * Scramble publishes as the API contract — stays the shape it was.
 *
 *  - **Code uniqueness.** `(tenant_id, code)` is a unique index that also
 *    covers soft-deleted rows, so a duplicate code used to reach the database
 *    and come back as a 500. Now it is a 422 on `code`.
 *  - **Zero days only when unpaid.** Unpaid leave has no entitlement to count
 *    down — the provisioned `unpaid` type is 0 days — so `default_days` may be
 *    0, but a *paid* type with no days is a type no one can take.
 *
 * @mixin FormRequest
 */
trait ValidatesLeaveTypeIntegrity
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // `apiResource('leave-types')` names the parameter `leave_type`.
            $current = $this->route('leave_type');
            $current = $current instanceof LeaveType ? $current : null;

            $code = $this->input('code');
            if (is_string($code) && $code !== '') {
                $taken = LeaveType::withTrashed()
                    ->where('code', $code)
                    ->when($current !== null, fn ($query) => $query->whereKeyNot($current->getKey()))
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('code', __('validation.unique', ['attribute' => 'code']));
                }
            }

            $days = $this->input('default_days', $current?->default_days);
            $paid = $this->has('is_paid') ? $this->boolean('is_paid') : ($current === null ? true : (bool) $current->is_paid);

            if (is_numeric($days) && (float) $days < 0.5 && $paid) {
                $validator->errors()->add('default_days', __('validation.leave_paid_needs_days'));
            }
        });
    }
}
