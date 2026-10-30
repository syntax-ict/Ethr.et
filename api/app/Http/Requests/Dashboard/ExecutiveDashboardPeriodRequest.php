<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The period an executive dashboard figure covers, this month by default.
 *
 * Until 2026-10-30 any text went to Carbon::parse, so a malformed date was a
 * 500 and a range of decades was accepted. The dates must now be real days,
 * in order, and at most MAX_DAYS apart, which covers the Analytics page's
 * longest choice (twelve months, in either calendar) with room to spare.
 *
 * Who may read the figures is the controller's check: it depends on which of
 * two permissions the caller holds, and decides their branch scope too.
 */
class ExecutiveDashboardPeriodRequest extends FormRequest
{
    public const MAX_DAYS = 400;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Stated here rather than as `after_or_equal:from`, which compares with
     * the literal word when `from` is left to its default.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $to] = $this->period();

            if ($from->greaterThan($to)) {
                $validator->errors()->add('to', __('validation.after_or_equal', [
                    'attribute' => 'to',
                    'date' => $from->toDateString(),
                ]));
            } elseif ($from->diffInDays($to) > self::MAX_DAYS) {
                $validator->errors()->add('to', __('validation.before_or_equal', [
                    'attribute' => 'to',
                    'date' => $from->copy()->addDays(self::MAX_DAYS)->toDateString(),
                ]));
            }
        });
    }

    /** @return array{Carbon, Carbon} */
    public function period(): array
    {
        $from = $this->filled('from')
            ? Carbon::parse($this->string('from')->toString())->startOfDay()
            : Carbon::now()->startOfMonth();

        $to = $this->filled('to')
            ? Carbon::parse($this->string('to')->toString())->endOfDay()
            : Carbon::now()->endOfMonth();

        return [$from, $to];
    }
}
