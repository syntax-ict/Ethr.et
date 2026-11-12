<?php

declare(strict_types=1);

namespace App\Http\Requests\Migration;

use App\Support\EmployeeImportRow;

/**
 * A saved source renamed, or its column names matched again. Either may be
 * sent without the other; what is sent is held to what saving one is.
 */
class UpdateImportMappingRequest extends SaveImportMappingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:80'],
            'calendar' => ['sometimes', 'nullable', 'string', 'in:gregorian,ethiopian'],
            ...EmployeeImportRow::mappingRules('sometimes'),
        ];
    }
}
