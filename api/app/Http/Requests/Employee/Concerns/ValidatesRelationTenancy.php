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
 * copied into each, so the two cannot drift apart — and so are the seven
 * unscoped `exists` rules this hook backs, in `relationRules()`. The rest of
 * the two requests' rules stay written out in each: they differ in six places
 * interleaved through the array, and Store's `national_id` comment is
 * published to the contract while Update's key has none.
 *
 * @mixin FormRequest
 */
trait ValidatesRelationTenancy
{
    /**
     * The seven relation fields, keyed as in `EmployeeRelations::MAP`.
     *
     * Scramble evaluates `rules()` at runtime, so spreading this array into it
     * publishes the same contract as spelling it out — `api/openapi.json` and
     * `src/src/api/generated.ts` were regenerated and are byte-identical. Keep
     * explanatory comments off these keys: Scramble publishes them as OpenAPI
     * descriptions.
     *
     * @return array<string, list<string>>
     */
    protected function relationRules(): array
    {
        return [
            'department_id' => ['nullable', 'exists:departments,public_id'],
            'branch_id' => ['nullable', 'exists:branches,public_id'],
            'position_id' => ['nullable', 'exists:positions,public_id'],
            'grade_id' => ['nullable', 'exists:grades,public_id'],
            'team_id' => ['nullable', 'exists:teams,public_id'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,public_id'],
            'supervisor_id' => ['nullable', 'exists:employees,public_id'],
        ];
    }

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
