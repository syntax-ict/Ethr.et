<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee\Concerns;

use App\Support\EmployeeRelations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Reject an employee relation `public_id` that does not belong to the current
 * tenant.
 *
 * `docs/audit/BASELINE.md` §11d site 5, extended in §11f to all seven fields.
 * Each is validated with `exists:<table>,public_id`, which goes through
 * Laravel's DatabasePresenceVerifier — and that verifier does **not** apply
 * Eloquent global scopes, so another tenant's `public_id` passed. It never
 * became a cross-tenant read: `EmployeeController::resolveRelationIds()`
 * resolves with a *scoped* query, so the value turned into `null`. The effect
 * was a department, branch, position, grade, team, cost centre or supervisor
 * that silently vanished — 201 Created with the field empty and nothing said.
 * Two layers disagreed about what a valid relation is and only one of them
 * spoke up.
 *
 * **Why this is a hook and not a validation rule.** Expressing it in `rules()`
 * needs `Rule::exists(...)->where('tenant_id', …)`, and
 * `Http/Requests/Billing/ChangePlanRequest.php` already records why this
 * repository does not use that builder: Scramble derives the public schema from
 * `rules()`, `src/src/api/generated.ts` carries what the string form emits, and
 * the contract gate fails on drift. Scramble also publishes comments written
 * above an array key as an OpenAPI `description` — visible at
 * `generated.ts:6904`, where `create_login`'s PHP comment appears verbatim — so
 * an explanation next to these rules would be published to API clients. A
 * `withValidator()` hook is invisible to Scramble, so `rules()` stays
 * byte-identical and the contract cannot move.
 *
 * Shared by `StoreEmployeeRequest` and `UpdateEmployeeRequest` rather than
 * copied into each, so the two cannot drift apart.
 *
 * @mixin FormRequest
 */
trait ValidatesRelationTenancy
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (EmployeeRelations::MAP as $field => $modelClass) {
                $publicId = $this->input($field);

                // Absent or null is allowed — every one of these fields is
                // `nullable`.
                if (! is_string($publicId) || $publicId === '') {
                    continue;
                }

                // Already rejected by the unscoped `exists` rule, so the
                // response carries one message for this field rather than two.
                if ($validator->errors()->has($field)) {
                    continue;
                }

                // Deliberately the query `resolveRelationIds()` runs, not a
                // restatement of it: the tenant global scope applies here for
                // the same reason it applies there, and so does the soft-delete
                // scope on the six models that have one. A hand-written
                // `where('tenant_id', …)` could drift from the resolver; this
                // cannot, because it is the resolver's lookup against the
                // resolver's own map.
                if ($modelClass::where('public_id', $publicId)->exists()) {
                    continue;
                }

                // Laravel's own `validation.exists` line, with the attribute
                // name Laravel derives itself — `validation.attributes` is
                // empty, so it snake-to-space-cases the field, and this
                // reproduces that exactly.
                //
                // Byte-identical to the message a genuinely nonexistent
                // public_id produces, and that is required rather than tidy: a
                // distinguishable message would confirm that a public_id exists
                // in some other tenant, which is precisely what the scope is
                // here to hide. `EmployeeRelationScopedValidationTest` asserts
                // the two messages are equal, per field, so this cannot rot.
                $validator->errors()->add($field, __('validation.exists', [
                    'attribute' => str_replace('_', ' ', $field),
                ]));
            }
        });
    }
}
