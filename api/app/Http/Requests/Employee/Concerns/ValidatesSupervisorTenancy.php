<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee\Concerns;

use App\Models\Employee;
use Illuminate\Validation\Validator;

/**
 * Reject a `supervisor_id` that does not belong to the current tenant.
 *
 * `docs/audit/BASELINE.md` §11d site 5. The `exists:employees,public_id` rule in
 * `rules()` goes through Laravel's DatabasePresenceVerifier, which does **not**
 * apply Eloquent global scopes — so another tenant's `public_id` passed
 * validation. It never became a cross-tenant read:
 * `EmployeeController::resolveRelationIds()` resolves with a *scoped* query, so
 * the value turned into `null`. The effect was a supervisor that silently
 * vanished — 201 Created, `supervisor_id` empty, nothing said. Two layers
 * disagreed about what a valid supervisor is and only one of them spoke up.
 *
 * This closes that by running the resolver's own query. It is shared rather than
 * written twice because `StoreEmployeeRequest` and `UpdateEmployeeRequest` must
 * not drift apart — the same asymmetry root `CLAUDE.md` records inside
 * `DispatchWebhookJob`, where `handle()` and `failed()` disagree about how much
 * to state.
 *
 * **Why the check lives here and not in `rules()`.** Expressing it as a rule
 * needs `Rule::exists(...)->where('tenant_id', …)`, and `ChangePlanRequest`
 * already records why this repository does not use that builder: Scramble
 * derives the public schema from `rules()`, `src/src/api/generated.ts` carries
 * what the string form emits, and the contract gate fails on drift. A
 * `withValidator()` hook is invisible to Scramble, so `rules()` stays byte
 * -identical and the contract cannot move.
 *
 * @mixin \Illuminate\Foundation\Http\FormRequest
 */
trait ValidatesSupervisorTenancy
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $supervisorId = $this->input('supervisor_id');

            // Absent or null is allowed — the field is `nullable`. A value the
            // `exists` rule already rejected is left alone, so the response
            // carries one message rather than two.
            if (! is_string($supervisorId) || $supervisorId === '') {
                return;
            }

            if ($validator->errors()->has('supervisor_id')) {
                return;
            }

            // Deliberately the query `resolveRelationIds()` runs, not a
            // restatement of it: the tenant global scope and the soft-delete
            // scope both apply here for the same reason they apply there. A
            // hand-written `where('tenant_id', …)` could drift from the
            // resolver; this cannot, because it is the resolver's lookup.
            if (Employee::where('public_id', $supervisorId)->exists()) {
                return;
            }

            // Laravel's own `validation.exists` line, with the attribute name
            // Laravel would derive (`validation.attributes` is empty, so it
            // snake-to-space-cases the field). Byte-identical to the message a
            // genuinely nonexistent public_id produces, and that is required
            // rather than tidy: a distinguishable message would confirm that a
            // public_id exists in some other tenant, which is precisely what
            // the scope is here to hide. `SupervisorScopedValidationTest` pins
            // the two messages as equal so this cannot rot.
            $validator->errors()->add('supervisor_id', __('validation.exists', [
                'attribute' => 'supervisor id',
            ]));
        });
    }
}
