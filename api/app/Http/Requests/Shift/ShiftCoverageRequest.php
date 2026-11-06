<?php

declare(strict_types=1);

namespace App\Http\Requests\Shift;

use App\Services\CurrentTenant;
use App\Support\TenantTime;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShiftCoverageRequest extends FormRequest
{
    // The range GET /shifts/coverage covers: this week (Monday to Sunday in
    // the organisation's zone) by default, at most MAX_DAYS when given, since
    // every day is resolved for every employee. A line comment, not a class
    // docblock: Scramble publishes a FormRequest's docblock into the contract.
    public const MAX_DAYS = 31;

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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $to] = $this->range();

            if ($from->greaterThan($to)) {
                $validator->errors()->add('to', __('validation.after_or_equal', [
                    'attribute' => 'to',
                    'date' => $from->toDateString(),
                ]));
            } elseif ($from->diffInDays($to) >= self::MAX_DAYS) {
                $validator->errors()->add('to', __('validation.before_or_equal', [
                    'attribute' => 'to',
                    'date' => $from->copy()->addDays(self::MAX_DAYS - 1)->toDateString(),
                ]));
            }
        });
    }

    /** @return array{Carbon, Carbon} */
    public function range(): array
    {
        $today = Carbon::parse(TenantTime::today(app(CurrentTenant::class)->get())->format('Y-m-d'));

        $from = $this->filled('from')
            ? Carbon::parse($this->string('from')->toString())
            : $today->copy()->startOfWeek(Carbon::MONDAY);

        $to = $this->filled('to')
            ? Carbon::parse($this->string('to')->toString())
            : $from->copy()->addDays(6);

        return [$from, $to];
    }
}
