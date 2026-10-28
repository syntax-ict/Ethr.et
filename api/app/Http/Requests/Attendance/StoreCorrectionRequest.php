<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\CorrectionKind;
use App\Enums\CorrectionStatus;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Services\Attendance\AttendanceEngine;
use App\Services\CurrentTenant;
use App\Support\TenantTime;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['sometimes', 'string', 'in:correction,missed_punch,lateness'],
            'attendance_record_public_id' => ['required_unless:kind,missed_punch', 'nullable', 'string', 'exists:attendance_records,public_id'],
            'employee_public_id' => ['nullable', 'string'],
            'date' => ['required_if:kind,missed_punch', 'nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:1000'],
            'proposed_check_in' => ['required_if:kind,missed_punch', 'nullable', 'date'],
            'proposed_check_out' => ['nullable', 'date'],
            'deduct_minutes' => ['required_if:kind,lateness', 'nullable', 'integer', 'min:0', 'max:1440'],
        ];
    }

    public function kind(): CorrectionKind
    {
        return CorrectionKind::tryFrom((string) $this->input('kind', CorrectionKind::CORRECTION->value))
            ?? CorrectionKind::CORRECTION;
    }

    /**
     * Whose attendance a missed-punch claim is: the named employee when HR
     * files on someone's behalf, else the filer's own. Null when neither
     * resolves inside the tenant; the hook below has already refused that.
     */
    public function claimant(): ?Employee
    {
        $publicId = $this->input('employee_public_id');

        if (is_string($publicId) && $publicId !== '') {
            return Employee::where('public_id', $publicId)->first();
        }

        $own = $this->user()?->employee_id;

        return $own === null ? null : Employee::find($own);
    }

    /**
     * The `exists` rule above goes through Laravel's presence verifier, which
     * applies no global scope, so another tenant's record id passed it and
     * reached the controller's scoped lookup as a 404 — while a nonexistent id
     * was a 422. The difference confirmed that the id exists somewhere. This
     * re-runs the lookup under the tenant scope and answers with the very
     * message a nonexistent id gets, the `ValidatesRelationTenancy` pattern.
     * It is a hook rather than a rule so `rules()`, which Scramble publishes,
     * stays unchanged.
     *
     * The per-kind checks are hooks for the same reason, and because each
     * needs the record, the claimant or the organisation's settings.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $field = 'attendance_record_public_id';
            $publicId = $this->input($field);

            if (is_string($publicId) && ! $validator->errors()->has($field)
                && ! AttendanceRecord::where('public_id', $publicId)->exists()) {
                $validator->errors()->add($field, __('validation.exists', [
                    'attribute' => str_replace('_', ' ', $field),
                ]));

                return;
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            match ($this->kind()) {
                CorrectionKind::MISSED_PUNCH => $this->checkMissedPunch($validator),
                CorrectionKind::LATENESS => $this->checkLateness($validator),
                CorrectionKind::CORRECTION => null,
            };
        });
    }

    private function checkMissedPunch(Validator $validator): void
    {
        if ($this->filled('attendance_record_public_id')) {
            $validator->errors()->add('attendance_record_public_id', __('correction.missed_punch_has_record'));

            return;
        }

        $publicId = $this->input('employee_public_id');
        $employee = $this->claimant();

        if ($employee === null) {
            $validator->errors()->add('employee_public_id', is_string($publicId) && $publicId !== ''
                ? __('validation.exists', ['attribute' => 'employee public id'])
                : __('correction.missed_punch_no_employee'));

            return;
        }

        $date = (string) $this->input('date');
        $today = TenantTime::today(app(CurrentTenant::class)->get())->format('Y-m-d');

        if ($date >= $today) {
            $validator->errors()->add('date', __('correction.missed_punch_future'));

            return;
        }

        $recorded = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->where('status', '!=', AttendanceStatus::VOIDED->value)
            ->exists();

        if ($recorded) {
            $validator->errors()->add('date', __('correction.missed_punch_has_record'));

            return;
        }

        $pending = AttendanceCorrection::query()
            ->where('employee_id', $employee->id)
            ->where('kind', CorrectionKind::MISSED_PUNCH->value)
            ->whereDate('date', $date)
            ->where('status', CorrectionStatus::PENDING->value)
            ->exists();

        if ($pending) {
            $validator->errors()->add('date', __('correction.missed_punch_pending'));

            return;
        }

        $checkOut = $this->input('proposed_check_out');
        if (is_string($checkOut) && $checkOut !== ''
            && Carbon::parse($checkOut)->lte(Carbon::parse((string) $this->input('proposed_check_in')))) {
            $validator->errors()->add('proposed_check_out', __('correction.check_out_before_check_in'));
        }
    }

    private function checkLateness(Validator $validator): void
    {
        $setting = AttendanceSetting::query()->first();

        if ($setting === null || ! $setting->lateness_deduction_enabled) {
            $validator->errors()->add('kind', __('correction.lateness_disabled'));

            return;
        }

        $record = AttendanceRecord::with('shift')
            ->where('public_id', (string) $this->input('attendance_record_public_id'))
            ->first();

        if ($record === null || ! in_array($record->status, [AttendanceStatus::LATE, AttendanceStatus::EARLY_LEAVE], true)) {
            $validator->errors()->add('attendance_record_public_id', __('correction.lateness_not_late'));

            return;
        }

        $measured = app(AttendanceEngine::class)->missedMinutes($record);

        if ((int) $this->input('deduct_minutes') > $measured) {
            $validator->errors()->add('deduct_minutes', __('correction.lateness_too_many_minutes', ['minutes' => $measured]));

            return;
        }

        $pending = AttendanceCorrection::query()
            ->where('attendance_record_id', $record->id)
            ->where('kind', CorrectionKind::LATENESS->value)
            ->where('status', CorrectionStatus::PENDING->value)
            ->exists();

        if ($pending) {
            $validator->errors()->add('attendance_record_public_id', __('correction.lateness_pending'));
        }
    }
}
