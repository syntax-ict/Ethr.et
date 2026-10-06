<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Support\CalendarPreference;
use App\Support\TenantSecurityPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $timeout = 'between:'.TenantSecurityPolicy::TIMEOUT_MIN_MINUTES.','.TenantSecurityPolicy::TIMEOUT_MAX_MINUTES;

        return [
            'settings' => ['required', 'array', 'min:1'],
            // Ethiopian month 1-13 (Meskerem = 1 ... Pagume = 13). Fiscal years
            // in practice start at Meskerem 1 (private) or Hamle 1 = month 7
            // (government), but any Ethiopian month is accepted.
            'settings.fiscal_year_start_month' => ['sometimes', 'integer', 'between:1,13'],
            'settings.pagumen_proration_strategy' => ['sometimes', 'string', 'in:full_month,daily_rate'],
            'settings.retirement_age' => ['sometimes', 'integer', 'between:45,75'],
            'settings.run_day' => ['sometimes', 'integer', 'between:1,28'],
            'settings.working_days' => ['sometimes', 'array', 'min:1'],
            'settings.working_days.*' => ['integer', 'distinct', 'between:1,7'],
            'settings.mfa_policy' => ['sometimes', 'string', Rule::in(TenantSecurityPolicy::MFA_POLICIES)],
            'settings.session_timeout_minutes' => ['sometimes', 'integer', $timeout],
            'settings.calendar' => ['sometimes', 'string', Rule::in(CalendarPreference::ORGANISATION_CHOICES)],
        ];
    }

    /**
     * Names for the messages. Without them Laravel spells the rule path out,
     * and the page showed "The settings.session timeout minutes field must be
     * between 5 and 480".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $names = [];
        foreach (self::writableKeys() as $key) {
            $names["settings.{$key}"] = __("settings.attributes.{$key}");
        }

        return $names;
    }

    /**
     * The keys `PUT /settings` may write — the ones `rules()` validates.
     *
     * @return list<string>
     */
    public static function writableKeys(): array
    {
        $keys = [];
        foreach (array_keys((new self)->rules()) as $rule) {
            if (preg_match('/^settings\.([a-z_]+)$/', $rule, $m) === 1) {
                $keys[] = $m[1];
            }
        }

        return $keys;
    }

    /**
     * Refuse any key `rules()` does not name.
     *
     * The settings JSON is merged wholesale, and other code reads keys from it
     * that this endpoint was never meant to write: `login_identifiers` decides
     * which identifiers sign a user in (its own endpoint validates it),
     * `password_policy` sets password rules, `notification_templates` is
     * written by its own controller. Every one of those was writable here,
     * unvalidated, by anyone holding `settings.manage` — and a misspelt key was
     * stored and answered 200 (audit N6). An unknown key is now a 422 naming it.
     *
     * A hook rather than the `array:` rule, so the error names the key instead
     * of saying the whole object "must be an array".
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $settings = $this->input('settings');
            if (! is_array($settings)) {
                return;
            }

            $allowed = self::writableKeys();

            foreach (array_keys($settings) as $key) {
                if (! in_array($key, $allowed, true)) {
                    $validator->errors()->add("settings.{$key}", __('validation.prohibited', [
                        'attribute' => "settings.{$key}",
                    ]));
                }
            }
        });
    }
}
