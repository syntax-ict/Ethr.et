<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ExtendTrialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'min:1', 'max:180'],
        ];
    }

    /**
     * Only an organisation on a trial has one to extend: on any other the
     * date was rewritten and nothing changed (A-Z review H-05). A hook, so
     * the published contract is unchanged.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $tenant = Tenant::query()->where('public_id', (string) $this->route('publicId'))->first();

            if ($tenant !== null && $tenant->status !== TenantStatus::TRIAL) {
                $v->errors()->add('days', __('billing.trial_not_running'));
            }
        });
    }
}
