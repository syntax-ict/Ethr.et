<?php

declare(strict_types=1);

namespace App\Http\Requests\Migration;

use App\Models\MigrationStagingRow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStagingRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in([
                MigrationStagingRow::ACTION_MERGE,
                MigrationStagingRow::ACTION_CREATE,
                MigrationStagingRow::ACTION_SKIP,
                MigrationStagingRow::ACTION_DEFER,
            ])],
            // Lets a reviewer pick which candidate an "ambiguous" row merges
            // with — resolved tenant-scoped in the controller, same as every
            // other `exists:employees,public_id` rule in this codebase (the
            // raw validation rule does not itself see the tenant scope).
            'employee_public_id' => ['nullable', 'string', 'exists:employees,public_id'],
        ];
    }
}
