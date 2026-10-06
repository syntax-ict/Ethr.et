<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Enums\ProfileUpdateStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\ProfileUpdateRequest;
use App\Models\User;
use App\Notifications\ProfileUpdateApprovedNotification;
use App\Notifications\ProfileUpdateRequestedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Staging and review of the profile fields an employee may propose but not apply.
 *
 * See the `profile_update_requests` migration for why this exists: before it, the
 * API claimed these changes were queued for HR and then threw them away.
 */
class ProfileUpdateRequestService
{
    /**
     * Stage proposed values for gated fields.
     *
     * A field the employee submits twice does not queue twice — the earlier pending
     * row is superseded, so HR reviews the employee's current intent rather than a
     * backlog of drafts. A value equal to what is already on record is dropped
     * entirely; queuing a no-op change for human review is pure noise.
     *
     * @param  array<string, mixed>  $changes  keyed by field name
     * @return Collection<int, ProfileUpdateRequest>
     */
    public function stage(Employee $employee, ?User $requester, array $changes): Collection
    {
        $staged = collect();

        foreach ($changes as $field => $newValue) {
            if (! array_key_exists($field, ProfileUpdateRequest::GATED_FIELDS) || $newValue === null) {
                continue;
            }

            $oldValue = $this->currentValue($employee, $field);
            if ((string) $oldValue === (string) $newValue) {
                continue;
            }

            DB::transaction(function () use ($employee, $requester, $field, $oldValue, $newValue, $staged) {
                ProfileUpdateRequest::where('employee_id', $employee->id)
                    ->where('field_name', $field)
                    ->where('status', ProfileUpdateStatus::PENDING)
                    ->update([
                        'status' => ProfileUpdateStatus::REJECTED->value,
                        'review_notes' => 'Superseded by a newer request from the employee.',
                        'reviewed_at' => now(),
                    ]);

                $staged->push(ProfileUpdateRequest::create([
                    'tenant_id' => $employee->tenant_id,
                    'employee_id' => $employee->id,
                    'requested_by' => $requester?->id,
                    'field_name' => $field,
                    'old_value' => $oldValue === null ? null : (string) $oldValue,
                    'new_value' => (string) $newValue,
                    'status' => ProfileUpdateStatus::PENDING->value,
                ]));
            });
        }

        if ($staged->isNotEmpty()) {
            AuditLog::record('profile.sensitive_change_requested', $employee, [
                'fields' => $staged->pluck('field_name')->all(),
            ]);

            $this->notifyReviewers($employee, $staged);
        }

        return $staged;
    }

    /**
     * Apply the staged value to its real destination and close the request.
     */
    public function approve(ProfileUpdateRequest $request, User $reviewer, ?string $notes = null): ProfileUpdateRequest
    {
        if ($request->status !== ProfileUpdateStatus::PENDING) {
            return $request;
        }

        DB::transaction(function () use ($request, $reviewer, $notes) {
            $employee = $request->employee;

            if ($employee instanceof Employee) {
                $this->applyValue($employee, $request->field_name, $request->new_value);
            }

            $request->update([
                'status' => ProfileUpdateStatus::APPROVED->value,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);
        });

        AuditLog::record('profile.update_request_approved', $request, [
            'field' => $request->field_name,
        ]);

        $this->notifyEmployee($request, true);

        return $request->refresh();
    }

    /**
     * Retract a pending request at the employee's own initiative.
     *
     * Kept apart from reject() because no reviewer decided anything here — the
     * employee changed their mind, and the HR queue should not read as if it was
     * turned down.
     */
    public function withdraw(ProfileUpdateRequest $request, User $employee): ProfileUpdateRequest
    {
        if ($request->status !== ProfileUpdateStatus::PENDING) {
            return $request;
        }

        $request->update([
            'status' => ProfileUpdateStatus::WITHDRAWN->value,
            'reviewed_by' => $employee->id,
            'reviewed_at' => now(),
            'review_notes' => 'Withdrawn by the employee.',
        ]);

        AuditLog::record('profile.update_request_withdrawn', $request, [
            'field' => $request->field_name,
        ]);

        return $request->refresh();
    }

    public function reject(ProfileUpdateRequest $request, User $reviewer, ?string $notes = null): ProfileUpdateRequest
    {
        if ($request->status !== ProfileUpdateStatus::PENDING) {
            return $request;
        }

        $request->update([
            'status' => ProfileUpdateStatus::REJECTED->value,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        AuditLog::record('profile.update_request_rejected', $request, [
            'field' => $request->field_name,
        ]);

        $this->notifyEmployee($request, false);

        return $request->refresh();
    }

    /**
     * The value currently on record, read from wherever the field actually lives.
     */
    private function currentValue(Employee $employee, string $field): ?string
    {
        if (ProfileUpdateRequest::GATED_FIELDS[$field] === 'bank_detail') {
            $detail = $this->primaryBankDetail($employee);

            $value = match ($field) {
                'bank_name' => $detail?->bank_name,
                'bank_account_number' => $detail?->account_number,
                default => null,
            };

            return $value === null ? null : (string) $value;
        }

        $value = $employee->getAttribute($field);

        // Date-cast columns come back as Carbon, and "2000-01-01 00:00:00" never
        // equals the "2000-01-01" the employee submitted — without this the no-op
        // guard above misses and every save re-queues the same birth date.
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value === null ? null : (string) $value;
    }

    private function applyValue(Employee $employee, string $field, ?string $value): void
    {
        // `users` carries no display name — every name in the product is read
        // through the linked Employee — so updating the employee row is the whole
        // job for the non-bank fields.
        if (ProfileUpdateRequest::GATED_FIELDS[$field] !== 'bank_detail') {
            $employee->update([$field => $value]);

            return;
        }

        $column = $field === 'bank_name' ? 'bank_name' : 'account_number';
        $detail = $this->primaryBankDetail($employee);

        if ($detail instanceof EmployeeBankDetail) {
            $detail->update([$column => $value]);

            return;
        }

        $employee->bankDetails()->create([
            'tenant_id' => $employee->tenant_id,
            'bank_name' => $field === 'bank_name' ? $value : '',
            'account_number' => $field === 'bank_account_number' ? $value : '',
            'is_primary' => true,
        ]);
    }

    private function primaryBankDetail(Employee $employee): ?EmployeeBankDetail
    {
        $detail = $employee->bankDetails()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        return $detail instanceof EmployeeBankDetail ? $detail : null;
    }

    /**
     * @param  Collection<int, ProfileUpdateRequest>  $staged
     */
    private function notifyReviewers(Employee $employee, Collection $staged): void
    {
        $reviewers = User::where('tenant_id', $employee->tenant_id)
            ->get()
            ->filter(fn (User $u) => $u->hasPermission('employee.update'));

        if ($reviewers->isEmpty()) {
            return;
        }

        $this->deliver(fn () => Notification::send(
            $reviewers,
            new ProfileUpdateRequestedNotification($employee, $staged->pluck('field_name')->all()),
        ));
    }

    private function notifyEmployee(ProfileUpdateRequest $request, bool $approved): void
    {
        $user = $request->employee?->user;

        if ($user instanceof User) {
            $this->deliver(fn () => $user->notify(
                new ProfileUpdateApprovedNotification($request->field_name, $approved),
            ));
        }
    }

    /**
     * Notifications are best-effort; the staged row is the record.
     *
     * These fire inline from an HTTP request, and the broadcast channel talks to
     * Reverb over cURL. With Reverb down, an unguarded send throws
     * BroadcastException *after* the row is committed — so the employee gets a 500
     * and believes the change was rejected, while it is actually sitting in the HR
     * queue. That is the same "the API says one thing and the data says another"
     * failure this whole slice exists to remove, so a delivery problem is logged
     * rather than allowed to misreport the write.
     */
    private function deliver(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::warning('Profile update notification could not be delivered', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
