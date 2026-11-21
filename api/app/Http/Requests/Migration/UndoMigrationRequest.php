<?php

declare(strict_types=1);

namespace App\Http\Requests\Migration;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Undoing what a list added: the second, explicit call after the preview.
 * Archiving people is not done by a request that does not say so.
 */
class UndoMigrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'accepted'],
        ];
    }
}
