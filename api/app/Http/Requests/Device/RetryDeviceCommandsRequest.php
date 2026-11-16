<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

use App\Services\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RetryDeviceCommandsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'batch_public_id' => [
                'nullable',
                'string',
                'size:26',
                Rule::exists('migration_batches', 'public_id')->where('tenant_id', $tenantId ?? 0),
            ],
        ];
    }
}
