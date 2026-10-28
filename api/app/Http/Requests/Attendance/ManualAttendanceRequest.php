<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Support\AttendanceRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ManualAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:64'],
            'employee_public_id' => ['required', 'string', 'exists:employees,public_id'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'check_in' => ['required', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i', 'different:check_in'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * A check-out earlier on the clock than the check-in is the next morning:
     * a night shift entered as 21:58 to 07:30 (ManualAttendanceController).
     * `after:check_in` refused every such entry until 2026-10-27, so a night
     * worker's day could be punched live but not entered by hand. It is
     * accepted while it is shorter than the time a live check-out the next
     * morning may still close an open day (AttendanceRules::MAX_OPEN_HOURS);
     * anything longer is a mistyped time, refused as before. A hook rather
     * than a rule, so the published contract is unchanged.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $in = $this->input('check_in');
            $out = $this->input('check_out');

            if ($v->errors()->hasAny(['check_in', 'check_out']) || ! is_string($in) || ! is_string($out) || $out > $in) {
                return;
            }

            [$inHour, $inMinute] = array_map(intval(...), explode(':', $in));
            [$outHour, $outMinute] = array_map(intval(...), explode(':', $out));
            $overnight = 24 * 60 - ($inHour * 60 + $inMinute) + ($outHour * 60 + $outMinute);

            if ($overnight >= AttendanceRules::MAX_OPEN_HOURS * 60) {
                $v->errors()->add('check_out', __('validation.after', ['attribute' => 'check out', 'date' => 'check in']));
            }
        });
    }
}
