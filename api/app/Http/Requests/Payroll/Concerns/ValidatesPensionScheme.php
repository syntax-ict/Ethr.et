<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll\Concerns;

use App\Services\Payroll\StatutoryRules;
use Illuminate\Validation\Validator;

/**
 * A pension scheme must be one the `pension.schemes` statutory parameter
 * lists today.
 *
 * A hook, not `Rule::in()` in rules(): the list is a database row since
 * 2026-10-27, and Scramble evaluates rules() to write the API contract where
 * no database may be reachable. rules() keeps a plain string.
 */
trait ValidatesPensionScheme
{
    protected function checkPensionScheme(Validator $validator, string $field): void
    {
        $scheme = data_get($this->all(), $field);

        if (is_string($scheme) && ! in_array($scheme, app(StatutoryRules::class)->pensionSchemes(), true)) {
            $validator->errors()->add($field, __('validation.in', ['attribute' => str_replace(['settings.', '_'], ['', ' '], $field)]));
        }
    }
}
