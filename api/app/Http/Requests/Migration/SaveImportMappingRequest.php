<?php

declare(strict_types=1);

namespace App\Http\Requests\Migration;

use App\Models\ImportMapping;
use App\Support\EmployeeImportRow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A source of lists and how it names its columns, saved or changed.
 *
 * `mapping` is a list of {header, column}: a column name as the source's
 * file writes it, and the ETHR column it is, or null for one that is left
 * out. One of them has to be the name, or nothing the source sends could
 * ever be read.
 *
 * A source's name is its own within the organisation. Two names that differ
 * only in case or punctuation are the same source: ids from the other system
 * are kept against the name's slug, and two sources sharing one would share
 * each other's people.
 */
class SaveImportMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'calendar' => ['sometimes', 'nullable', 'string', 'in:gregorian,ethiopian'],
            ...EmployeeImportRow::mappingRules('required'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $current = $this->route('importMapping');
            $current = $current instanceof ImportMapping ? $current : null;

            $mapping = $this->input('mapping');
            if (is_array($mapping) && ! in_array('name', ImportMapping::mapOf($mapping), true)) {
                $validator->errors()->add('mapping', __('onboarding.migration.mapping_needs_name'));
            }

            $name = $this->input('name');
            if (! is_string($name)) {
                return;
            }

            $taken = ImportMapping::query()
                ->when($current !== null, fn ($query) => $query->whereKeyNot($current?->getKey()))
                ->get(['id', 'name', 'slug'])
                ->contains(fn (ImportMapping $other): bool => $other->slug === ImportMapping::slugFor($name)
                    || mb_strtolower($other->name) === mb_strtolower(trim($name)));
            if ($taken) {
                $validator->errors()->add('name', __('onboarding.migration.source_name_taken'));

                return;
            }

            if ($current === null && ImportMapping::query()->count() >= ImportMapping::LIMIT) {
                $validator->errors()->add('name', __('onboarding.migration.source_limit', ['max' => ImportMapping::LIMIT]));
            }
        });
    }
}
