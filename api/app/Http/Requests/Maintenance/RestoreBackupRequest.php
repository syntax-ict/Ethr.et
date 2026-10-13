<?php

declare(strict_types=1);

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Which backup to restore, typed twice.
 *
 * A name, never a path: it must look like a directory BackupService::create()
 * makes, and MaintenanceController checks it exists under the backup root, so
 * a request cannot point the restore at any other file on the host. `confirm`
 * must repeat it exactly — a restore drops every table, and copying one field
 * into another is a deliberate act a mistyped request is not.
 */
class RestoreBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        // VerifyMaintenanceToken on the route is the authority.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'regex:/^ethr-\d{8}-\d{6}(-[A-Za-z0-9_-]+)?$/'],
            'confirm' => ['required', 'string', 'same:name'],
        ];
    }
}
