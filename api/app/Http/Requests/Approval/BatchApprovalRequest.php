<?php

declare(strict_types=1);

namespace App\Http\Requests\Approval;

use Illuminate\Foundation\Http\FormRequest;

class BatchApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'actions' => ['required', 'array', 'min:1'],
            'actions.*.type' => ['required', 'string', 'in:leave,correction'],
            'actions.*.public_id' => ['required', 'string'],
            'actions.*.action' => ['required', 'string', 'in:approve,reject'],
            'actions.*.reason' => ['nullable', 'string'],
        ];
    }
}
