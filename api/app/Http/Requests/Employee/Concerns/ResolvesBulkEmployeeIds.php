<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee\Concerns;

use App\Models\Employee;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * What every request that names a selection of employees by `public_id` shares:
 * an id of another organisation, an id that exists nowhere and an id outside the
 * requester's org scope all get the one message, so the response cannot be used
 * to learn that someone exists.
 */
trait ResolvesBulkEmployeeIds
{
    /** The most people one request may name. */
    public const MAX_EMPLOYEES = 200;

    /**
     * The `exists` rules go through Laravel's presence verifier, which applies
     * no tenant scope, so another tenant's `public_id` passes them. This
     * resolves the ids through the scoped model and refuses what it does not
     * find (audit N11). `$withArchived` counts a person who has been archived
     * as found, for the requests that name them on purpose.
     */
    protected function refuseUnknownEmployeeIds(Validator $validator, bool $withArchived = false): void
    {
        $validator->after(function (Validator $validator) use ($withArchived): void {
            $ids = $this->input('employee_ids');
            if (! is_array($ids)) {
                return;
            }

            $strings = array_filter($ids, 'is_string');

            // Scoped: the tenant global scope applies, as it does to the update.
            $query = $withArchived ? Employee::withTrashed() : Employee::query();
            $found = $query->whereIn('public_id', array_values($strings))->pluck('public_id')->all();

            foreach ($strings as $index => $publicId) {
                $key = "employee_ids.{$index}";

                if ($validator->errors()->has($key) || in_array($publicId, $found, true)) {
                    continue;
                }

                $validator->errors()->add($key, $this->missingEmployeeMessage($validator, $key));
            }
        });
    }

    /**
     * Refuse the whole request when any employee named is outside the
     * requester's org scope, before anything is written.
     *
     * Runs from the controller, after its permission check, so a caller with
     * no permission still gets 403 rather than a 422 naming ids. Each refused
     * id gets the message an id that exists nowhere gets, so a scoped manager
     * cannot use the response to learn that someone outside their scope exists.
     *
     * @param  list<string>  $reachable  the public ids the requester may change
     *
     * @throws ValidationException
     */
    public function refuseEmployeesOutOfReach(array $reachable): void
    {
        $validator = $this->getValidatorInstance();
        $messages = [];

        foreach ((array) $this->validated('employee_ids') as $index => $publicId) {
            if (in_array($publicId, $reachable, true)) {
                continue;
            }

            $key = "employee_ids.{$index}";
            $messages[$key] = $this->missingEmployeeMessage($validator, $key);
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * The message the unscoped `exists` rule gives a public_id that exists
     * nowhere, so the response cannot confirm that it exists in another
     * tenant, or outside the requester's scope.
     */
    private function missingEmployeeMessage(ValidatorContract $validator, string $key): string
    {
        // The contract has no getDisplayableAttribute(); the framework's
        // validator, which is what a FormRequest builds, does.
        $attribute = $validator instanceof Validator ? $validator->getDisplayableAttribute($key) : $key;

        return __('validation.exists', ['attribute' => $attribute]);
    }
}
