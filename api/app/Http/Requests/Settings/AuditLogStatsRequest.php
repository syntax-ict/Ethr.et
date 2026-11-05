<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\CurrentTenant;
use App\Support\TenantTime;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AuditLogStatsRequest extends FormRequest
{
    // The range GET /audit-logs/stats counts over, in the organisation's
    // zone: the last MAX_DAYS days by default, and at most MAX_DAYS when
    // given. The same `filter[...]` names as the list, so the page sends one
    // set of filters to both. A line comment, not a class docblock: Scramble
    // publishes a FormRequest's docblock into the contract.
    public const MAX_DAYS = 92;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.action' => ['sometimes', 'nullable', 'string', 'max:100'],
            'filter.from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'filter.to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $to] = $this->range();

            if ($from->greaterThan($to)) {
                $validator->errors()->add('filter.to', __('validation.after_or_equal', [
                    'attribute' => 'filter.to',
                    'date' => $from->toDateString(),
                ]));
            } elseif ($from->diffInDays($to) >= self::MAX_DAYS) {
                $validator->errors()->add('filter.to', __('validation.before_or_equal', [
                    'attribute' => 'filter.to',
                    'date' => $from->copy()->addDays(self::MAX_DAYS - 1)->toDateString(),
                ]));
            }
        });
    }

    /** @return array{Carbon, Carbon} calendar days, no time of day */
    public function range(): array
    {
        $today = Carbon::parse(TenantTime::today(app(CurrentTenant::class)->get())->format('Y-m-d'));

        $to = $this->filled('filter.to')
            ? Carbon::parse((string) $this->input('filter.to'))
            : $today;

        $from = $this->filled('filter.from')
            ? Carbon::parse((string) $this->input('filter.from'))
            : $to->copy()->subDays(29);

        return [$from, $to];
    }

    public function action(): ?string
    {
        $action = trim((string) $this->input('filter.action', ''));

        return $action === '' ? null : $action;
    }
}
