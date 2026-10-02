<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Support\EmployeeImportRow;
use Illuminate\Foundation\Http\FormRequest;

class ImportCommitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'import_key' => ['required', 'string', 'max:50'],
            // When true, a login account is provisioned for every imported row
            // that has an email (each receives an activation link).
            'create_logins' => ['sometimes', 'boolean'],
            'rows' => ['required', 'array', 'min:1'],
            ...EmployeeImportRow::rules('rows.*.'),
        ];
    }
}
