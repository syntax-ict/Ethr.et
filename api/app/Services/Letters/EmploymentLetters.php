<?php

declare(strict_types=1);

namespace App\Services\Letters;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmploymentLetterRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LetterDecidedNotification;
use App\Notifications\LetterRequestedNotification;
use App\Traits\SendsNotifications;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Employment letters: an employee asks, HR issues or declines, and an issued
 * letter is a PDF in the language asked for (blueprint assessment ESS7).
 *
 * The letter states only what the record says: name, employee number,
 * employment dates, position and department, and the basic monthly salary
 * when the employee asked for it to be included. It is generated when
 * downloaded, from the record as it stands then, and carries the reference
 * and issue date fixed when HR issued it.
 */
final class EmploymentLetters
{
    use SendsNotifications;

    /** @param array{purpose: string, addressed_to?: string|null, include_salary?: bool, language?: string} $data */
    public function request(Employee $employee, array $data): EmploymentLetterRequest
    {
        $letter = EmploymentLetterRequest::create([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'purpose' => $data['purpose'],
            'addressed_to' => $data['addressed_to'] ?? null,
            'include_salary' => (bool) ($data['include_salary'] ?? false),
            'language' => ($data['language'] ?? 'en') === 'am' ? 'am' : 'en',
            'status' => EmploymentLetterRequest::PENDING,
        ]);

        AuditLog::record('letter.requested', $letter, ['include_salary' => $letter->include_salary]);

        User::query()
            ->where('status', 'active')
            ->get()
            ->filter(fn (User $user) => $user->hasPermission('employee.update')
                && $user->canAccessEmployee($employee)
                && (int) $user->employee_id !== (int) $employee->id)
            ->each(fn (User $user) => $this->notify($user, new LetterRequestedNotification($letter, (string) $employee->name)));

        return $letter;
    }

    public function issue(EmploymentLetterRequest $letter, User $by): EmploymentLetterRequest
    {
        return DB::transaction(function () use ($letter, $by) {
            $year = now()->year;
            $count = EmploymentLetterRequest::query()
                ->where('reference', 'like', "EL-{$year}-%")
                ->lockForUpdate()
                ->count();

            $letter->update([
                'status' => EmploymentLetterRequest::ISSUED,
                'reference' => sprintf('EL-%d-%04d', $year, $count + 1),
                'decided_by' => $by->id,
                'decided_at' => now(),
            ]);

            AuditLog::record('letter.issued', $letter, ['reference' => $letter->reference]);
            $this->notifyEmployee($letter);

            return $letter;
        });
    }

    public function reject(EmploymentLetterRequest $letter, User $by, string $reason): EmploymentLetterRequest
    {
        $letter->update([
            'status' => EmploymentLetterRequest::REJECTED,
            'decided_by' => $by->id,
            'decided_at' => now(),
            'rejection_reason' => $reason,
        ]);

        AuditLog::record('letter.rejected', $letter, ['reason' => $reason]);
        $this->notifyEmployee($letter);

        return $letter;
    }

    /** The issued letter as a PDF, in the language it was asked for. */
    public function pdf(EmploymentLetterRequest $letter): string
    {
        $employee = Employee::query()
            ->with(['position:id,title', 'department:id,name'])
            ->findOrFail($letter->employee_id);
        $tenant = Tenant::query()->findOrFail($letter->tenant_id);
        $letter->loadMissing('decider');
        $locale = $letter->language === 'am' ? 'am' : 'en';
        $date = fn (?Carbon $d): string => $d === null ? '' : $d->locale($locale)->translatedFormat('j F Y');

        $data = [
            'locale' => $locale,
            'organisation' => (string) $tenant->name,
            'reference' => (string) $letter->reference,
            'issued_on' => $date($letter->decided_at),
            'addressed_to' => $letter->addressed_to,
            'name' => (string) $employee->name,
            'code' => (string) $employee->employee_code,
            'hired_on' => $date($employee->hire_date),
            'left_on' => $employee->termination_date !== null && $employee->termination_date->isPast() ? $date($employee->termination_date) : null,
            'position' => (string) data_get($employee, 'position.title', ''),
            'department' => (string) data_get($employee, 'department.name', ''),
            'salary' => $letter->include_salary && $employee->salary_cents > 0 ? number_format($employee->salary_cents / 100, 2) : null,
            'purpose' => $letter->purpose,
            'issuer' => (string) data_get($letter, 'decider.name', ''),
        ];

        File::ensureDirectoryExists(storage_path('fonts'));
        $pdf = Pdf::loadView('letters.employment', $data);
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isFontSubsettingEnabled', true);

        return $pdf->output();
    }

    /** @return array<string, mixed> */
    public static function present(EmploymentLetterRequest $letter): array
    {
        return [
            'public_id' => $letter->public_id,
            'employee' => $letter->relationLoaded('employee') && $letter->employee !== null
                ? ['public_id' => $letter->employee->public_id, 'name' => $letter->employee->name]
                : null,
            'purpose' => $letter->purpose,
            'addressed_to' => $letter->addressed_to,
            'include_salary' => $letter->include_salary,
            'language' => $letter->language,
            'status' => $letter->status,
            'reference' => $letter->reference,
            'rejection_reason' => $letter->rejection_reason,
            'decided_at' => $letter->decided_at?->toIso8601String(),
            'created_at' => $letter->created_at?->toIso8601String(),
        ];
    }

    private function notifyEmployee(EmploymentLetterRequest $letter): void
    {
        $user = User::query()->where('employee_id', $letter->employee_id)->where('status', 'active')->first();
        if ($user !== null) {
            $this->notify($user, new LetterDecidedNotification($letter));
        }
    }
}
