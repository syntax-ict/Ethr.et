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
use App\Support\Letterhead;
use App\Support\Locales;
use App\Support\PdfPages;
use App\Support\ReaderDates;
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
 * when the employee asked for it to be included.
 *
 * An issued letter never changes: what it says is fixed as an encrypted
 * snapshot when HR issues it, and every download prints that snapshot, never
 * the employee record as it stands later. A letter issued before snapshots
 * existed has none, so it is frozen from the live record at its first
 * download after that release (audited as `letter.frozen`, `late`); until
 * then it could not have been frozen. The logo is the one exception, read
 * live on purpose: it is an image of up to 1 MB, the same organisation's mark.
 */
final class EmploymentLetters
{
    use SendsNotifications;

    /** Bump when the snapshot's keys change, so old snapshots can be told apart. */
    public const SNAPSHOT_VERSION = 1;

    /** @param array{purpose: string, addressed_to?: string|null, include_salary?: bool, language?: string} $data */
    public function request(Employee $employee, array $data): EmploymentLetterRequest
    {
        $letter = EmploymentLetterRequest::create([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'purpose' => $data['purpose'],
            'addressed_to' => $data['addressed_to'] ?? null,
            'include_salary' => (bool) ($data['include_salary'] ?? false),
            'language' => Locales::orDefault($data['language'] ?? null),
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

            $letter->update(['snapshot' => $this->facts($letter), 'snapshot_at' => now()]);

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
        $data = $this->data($letter);

        File::ensureDirectoryExists(storage_path('fonts'));
        $pdf = Pdf::loadView('letters.employment', $data);
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isFontSubsettingEnabled', true);

        return PdfPages::render($pdf);
    }

    /**
     * What the letter prints: the frozen facts with the organisation's
     * current logo. A letter with no snapshot is frozen here, once.
     *
     * @return array<string, mixed>
     */
    public function data(EmploymentLetterRequest $letter): array
    {
        $facts = $letter->snapshot;
        if (! is_array($facts)) {
            $facts = $this->freeze($letter);
        }

        $tenant = Tenant::query()->findOrFail($letter->tenant_id);
        $letterhead = is_array($facts['letterhead'] ?? null) ? $facts['letterhead'] : ['name' => (string) $tenant->name, 'lines' => []];
        $letterhead['logo'] = Letterhead::for($tenant, Locales::orDefault($letter->language))['logo'];
        $facts['letterhead'] = $letterhead;

        return $facts;
    }

    /**
     * Freeze a letter issued before snapshots existed.
     *
     * @return array<string, mixed>
     */
    private function freeze(EmploymentLetterRequest $letter): array
    {
        $facts = $this->facts($letter);
        $letter->update(['snapshot' => $facts, 'snapshot_at' => now()]);
        AuditLog::record('letter.frozen', $letter, ['reference' => $letter->reference, 'late' => true]);

        return $facts;
    }

    /**
     * Everything the letter says, as text, from the record as it stands now,
     * without the logo. Stored as the snapshot when the letter is issued.
     *
     * @return array<string, mixed>
     */
    public function facts(EmploymentLetterRequest $letter): array
    {
        $employee = Employee::query()
            ->with(['position:id,title', 'department:id,name'])
            ->findOrFail($letter->employee_id);
        $tenant = Tenant::query()->findOrFail($letter->tenant_id);
        $letter->loadMissing('decider.employee.position');
        $locale = Locales::orDefault($letter->language);
        // Dates in the organisation's calendar. An Ethiopian date is followed
        // by the Gregorian one, since a bank or an embassy reads the letter.
        $reader = ReaderDates::forTenant($tenant, $locale);
        $gregorian = $reader->withCalendar('gregorian');
        $date = fn (Carbon|string|null $d): string => $d === null ? '' : ($reader->calendar === 'ethiopian'
            ? $reader->date($d).' ('.$gregorian->date($d).')'
            : $reader->date($d));

        $letterhead = Letterhead::for($tenant, $locale);
        unset($letterhead['logo']);

        return [
            'version' => self::SNAPSHOT_VERSION,
            'locale' => $locale,
            'letterhead' => $letterhead,
            'organisation' => (string) $tenant->name,
            'reference' => (string) $letter->reference,
            'issued_on' => $date($letter->decided_at),
            'addressed_to' => $letter->addressed_to,
            'name' => (string) $employee->name,
            'code' => (string) $employee->employee_code,
            'hired_on' => $date($employee->hire_date?->toDateString()),
            'left_on' => $employee->termination_date !== null && $employee->termination_date->isPast() ? $date($employee->termination_date->toDateString()) : null,
            'position' => (string) data_get($employee, 'position.title', ''),
            'department' => (string) data_get($employee, 'department.name', ''),
            'salary' => $letter->include_salary && $employee->salary_cents > 0 ? number_format($employee->salary_cents / 100, 2) : null,
            'purpose' => $letter->purpose,
            'issuer' => (string) data_get($letter, 'decider.name', ''),
            'issuer_position' => (string) data_get($letter, 'decider.employee.position.title', ''),
        ];
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
