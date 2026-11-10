<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\OrganizationType;
use App\Services\CurrentTenant;
use App\Services\Tenancy\OrganisationProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'organization_type' => ['sometimes', 'string', 'max:40'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'timezone' => ['sometimes', 'string', 'max:50', 'timezone:all'],
            'locale' => ['sometimes', 'string', 'in:en,am,om,ti,so'],
        ];
    }

    /**
     * The organisation type must be an active row of the catalogue. Checked
     * here rather than with `Rule::in()` because the catalogue is a table,
     * and Scramble evaluates rules() where no database may be reachable.
     *
     * Once a payroll run has been approved, a change of type is never
     * retroactive: approved pay was worked out under the old type's rules, so
     * the new type applies from a future date, the first day of an
     * organisation month after the last approved period
     * (OrganisationProfile::earliestChange).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $type = $this->input('organization_type');

            if (is_string($type) && ! in_array($type, OrganizationType::activeCodes(), true)) {
                $v->errors()->add('organization_type', __('validation.in', ['attribute' => 'organization type']));

                return;
            }

            $tenant = app(CurrentTenant::class)->get();
            if (! is_string($type) || $tenant === null || $type === $tenant->organization_type) {
                return;
            }

            $profile = app(OrganisationProfile::class);
            $earliest = $profile->earliestChange($tenant);
            if ($earliest === null) {
                return;
            }

            $from = $this->input('effective_from');
            $date = is_string($from) && $from !== '' ? Carbon::parse($from)->startOfDay() : null;
            if ($date === null || $date->lt($earliest)) {
                $v->errors()->add('effective_from', __('validation.organization_type_change_future', [
                    'date' => $earliest->toDateString(),
                ]));

                return;
            }

            if (! $profile->isChangeDate($tenant, $date)) {
                $v->errors()->add('effective_from', __('validation.organization_type_change_month_start'));
            }
        });
    }
}
