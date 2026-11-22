<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Services\Auth\ImpersonationToken;
use App\Support\PayChangeThresholds;
use Illuminate\Support\Collection;

/**
 * What an approver should look at before releasing a run, found by rule
 * (2026-11-22). Four lists beside the register's others (RunRegisterService):
 *
 * - `pay_changes`: net pay that moved by the organisation's threshold since
 *   the person's entry in the last released run (PayChangeThresholds), with
 *   the parts of pay that moved most, so the reason is on the screen.
 * - `shared_bank_accounts`: two or more people of the run paid into one
 *   account, by the account's keyed hash (EmployeeBankDetail).
 * - `missing_tin`: people paid with no TIN on record.
 * - `no_attendance`: people paid who were expected at work and have no
 *   attendance at all in the period, where the organisation records
 *   attendance.
 *
 * None of them blocks approval or changes pay; the approver decides each.
 * Every query states `tenant_id`, and none bypasses the tenant scope.
 */
final class PreApprovalChecks
{
    /**
     * The parts of a payslip whose change explains a change in net pay.
     * Basic is before lateness, which is its own part.
     */
    public const PARTS = [
        'basic_cents', 'overtime_cents', 'allowances_cents', 'lateness_cents',
        'income_tax_cents', 'employee_pension_cents', 'loan_cents', 'cost_sharing_cents',
    ];

    /** How many parts are named for one change. */
    private const REASONS = 3;

    /**
     * @param  bool  $enabled  false gives every list empty and reads nothing: the approval itself, the CSV and the statutory schedules need none of it
     */
    public function __construct(
        private readonly RunRegisterService $register,
        private readonly bool $enabled = true,
    ) {}

    /**
     * @param  Collection<int, PayrollEntry>  $entries
     * @param  list<array<string, mixed>>  $rows
     * @return array{count: int, previous_period: string|null, first_payslip: int, percent: int, min_cents: int, employees: list<array{employee_public_id: string|null, employee_name: string, previous_net_cents: int, net_cents: int, change_cents: int, change_percent: float|null, reasons: list<array{part: string, change_cents: int}>}>}
     */
    public function payChanges(PayrollRun $run, Collection $entries, array $rows): array
    {
        $thresholds = $this->enabled
            ? PayChangeThresholds::forTenant(Tenant::query()->find($run->tenant_id))
            : PayChangeThresholds::forTenant(null);
        $employees = [];
        $firstPayslip = 0;
        $previous = $this->enabled ? $this->previousRun($run) : null;

        if ($previous !== null) {
            /** @var array<int, array<string, mixed>> $before */
            $before = [];
            $previousEntries = PayrollEntry::query()
                ->where('tenant_id', $run->tenant_id)
                ->where('payroll_run_id', $previous->id)
                ->with('employee')
                ->orderBy('id')
                ->get();
            foreach ($previousEntries as $entry) {
                $before[(int) $entry->employee_id] ??= $this->register->row($entry);
            }

            foreach ($entries->values() as $index => $entry) {
                $now = $rows[$index] ?? null;
                $then = $before[(int) $entry->employee_id] ?? null;
                if ($now === null) {
                    continue;
                }
                if ($then === null) {
                    $firstPayslip++;

                    continue;
                }

                $was = (int) $then['net_cents'];
                $is = (int) $now['net_cents'];
                if ($thresholds->flags($was, $is)) {
                    $employees[] = [
                        'employee_public_id' => is_string($now['employee_public_id'] ?? null) ? $now['employee_public_id'] : null,
                        'employee_name' => (string) ($now['employee_name'] ?? ''),
                        'previous_net_cents' => $was,
                        'net_cents' => $is,
                        'change_cents' => $is - $was,
                        'change_percent' => $was === 0 ? null : round(($is - $was) * 100 / abs($was), 1),
                        'reasons' => self::reasons($then, $now),
                    ];
                }
            }
        }

        return [
            'count' => count($employees),
            'previous_period' => $previous?->period_label,
            'first_payslip' => $firstPayslip,
            'percent' => $thresholds->percent,
            'min_cents' => $thresholds->minCents,
            'employees' => $employees,
        ];
    }

    /**
     * The run released last before this one began: approved or paid, never
     * voided (a voided run is neither). Null for an organisation's first.
     */
    private function previousRun(PayrollRun $run): ?PayrollRun
    {
        if ($run->period_start === null) {
            return null;
        }

        return PayrollRun::query()
            ->where('tenant_id', $run->tenant_id)
            ->whereKeyNot($run->id)
            ->whereIn('status', PayrollRun::RELEASED_STATUSES)
            ->whereDate('period_end', '<', $run->period_start->format('Y-m-d'))
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The register row of each person the run pays something, by employee id.
     *
     * @param  Collection<int, PayrollEntry>  $entries
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function paid(Collection $entries, array $rows): array
    {
        $paid = [];
        foreach ($entries->values() as $index => $entry) {
            if ((int) $entry->net_cents > 0) {
                $paid[(int) $entry->employee_id] = $rows[$index] ?? [];
            }
        }

        return $paid;
    }

    /**
     * The parts that moved most between two of a person's payslips, largest
     * first. Basic is compared before lateness, which is compared on its own.
     *
     * @param  array<string, mixed>  $then
     * @param  array<string, mixed>  $now
     * @return list<array{part: string, change_cents: int}>
     */
    public static function reasons(array $then, array $now): array
    {
        $moved = [];
        foreach (self::PARTS as $part) {
            $was = (int) ($then[$part] ?? 0);
            $is = (int) ($now[$part] ?? 0);
            if ($part === 'basic_cents') {
                $was += (int) ($then['lateness_cents'] ?? 0);
                $is += (int) ($now['lateness_cents'] ?? 0);
            }

            if ($is !== $was) {
                $moved[] = ['part' => $part, 'change_cents' => $is - $was];
            }
        }

        usort($moved, fn (array $a, array $b): int => abs($b['change_cents']) <=> abs($a['change_cents']));

        return array_slice($moved, 0, self::REASONS);
    }

    /**
     * People of the run, paid, whose pay goes to one account. The account is
     * the one the bank file pays (the primary, else the first saved), read by
     * its hash only: no number is decrypted or shown. `pending_hashes` counts
     * those people's accounts saved before the hash existed and not yet
     * filled (BackfillBankAccountHashesJob), which this cannot compare yet.
     *
     * Not in a support session: who is paid where is the organisation's, and
     * an operator reads no bank details (ImpersonationRestricted). The list
     * is empty then and `support_session` says why.
     *
     * @param  Collection<int, PayrollEntry>  $entries
     * @param  list<array<string, mixed>>  $rows
     * @return array{count: int, pending_hashes: int, support_session: bool, groups: list<array{banks: list<string>, employees: list<array{employee_public_id: string|null, employee_name: string}>}>}
     */
    public function sharedBankAccounts(PayrollRun $run, Collection $entries, array $rows): array
    {
        $supportSession = ImpersonationToken::sessionActive();
        $paid = $this->enabled && ! $supportSession ? self::paid($entries, $rows) : [];
        $details = $paid === [] ? collect() : EmployeeBankDetail::query()
            ->where('tenant_id', $run->tenant_id)
            ->whereIn('employee_id', array_keys($paid))
            ->orderBy('id')
            ->get(['id', 'employee_id', 'bank_name', 'is_primary', 'account_number_hash'])
            ->groupBy('employee_id');

        $pending = 0;
        /** @var array<string, list<array{employee_id: int, bank: string}>> $byHash */
        $byHash = [];
        foreach ($details as $employeeId => $accounts) {
            /** @var EmployeeBankDetail $account */
            $account = $accounts->firstWhere('is_primary', true) ?? $accounts->first();
            $hash = $account->account_number_hash;
            if ($hash === null) {
                $pending++;

                continue;
            }
            if ($hash === '') {
                continue;
            }

            $byHash[$hash][] = ['employee_id' => (int) $employeeId, 'bank' => (string) $account->bank_name];
        }

        $groups = [];
        foreach ($byHash as $holders) {
            if (count($holders) < 2) {
                continue;
            }

            $groups[] = [
                'banks' => array_values(array_unique(array_filter(array_column($holders, 'bank'), fn (string $bank): bool => $bank !== ''))),
                'employees' => array_map(fn (array $holder): array => [
                    'employee_public_id' => is_string($paid[$holder['employee_id']]['employee_public_id'] ?? null) ? $paid[$holder['employee_id']]['employee_public_id'] : null,
                    'employee_name' => (string) ($paid[$holder['employee_id']]['employee_name'] ?? ''),
                ], $holders),
            ];
        }

        return ['count' => count($groups), 'pending_hashes' => $pending, 'support_session' => $supportSession, 'groups' => $groups];
    }

    /**
     * People paid in the run with no TIN on record. The TIN is encrypted; only
     * whether one is stored is read.
     *
     * @param  Collection<int, PayrollEntry>  $entries
     * @return array{count: int, employees: list<array{employee_public_id: string|null, employee_name: string}>}
     */
    public function missingTin(Collection $entries): array
    {
        $employees = [];
        foreach ($this->enabled ? $entries : [] as $entry) {
            /** @var Employee|null $employee */
            $employee = $entry->getRelation('employee');
            if ($employee === null || (int) $entry->net_cents <= 0) {
                continue;
            }

            $tin = $employee->getRawOriginal('tin');
            if ($tin === null || $tin === '') {
                $employees[] = ['employee_public_id' => $employee->public_id, 'employee_name' => (string) $employee->name];
            }
        }

        return ['count' => count($employees), 'employees' => $employees];
    }

    /**
     * People paid who were expected at work on days of the period and have
     * no attendance at all in it. "Expected" is what the run's absence step
     * already worked out and logged: a workday, employed, not a holiday or a
     * rest day, not on approved leave (PayrollEngine::unexplainedAbsencesFor).
     * That step flags nobody where the organisation records no attendance,
     * so neither does this. An entry from before the step logged its days is
     * left out.
     *
     * @param  Collection<int, PayrollEntry>  $entries
     * @param  list<array<string, mixed>>  $rows
     * @return array{count: int, employees: list<array{employee_public_id: string|null, employee_name: string, days: int}>}
     */
    public function noAttendance(PayrollRun $run, Collection $entries, array $rows): array
    {
        $paid = $this->enabled ? self::paid($entries, $rows) : [];
        $absent = $run->period_start === null || $run->period_end === null ? [] : array_filter(
            $paid,
            fn (array $row): bool => is_array($row['unexplained_absence_days'] ?? null) && $row['unexplained_absence_days'] !== [],
        );

        $attended = $absent === [] ? [] : AttendanceRecord::query()
            ->where('tenant_id', $run->tenant_id)
            ->whereIn('employee_id', array_keys($absent))
            ->whereDate('date', '>=', $run->period_start->format('Y-m-d'))
            ->whereDate('date', '<=', $run->period_end->format('Y-m-d'))
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->where(fn ($q) => $q->whereNotNull('check_in')->orWhereNotNull('check_out'))
            ->distinct()
            ->pluck('employee_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $employees = [];
        foreach ($absent as $employeeId => $row) {
            if (in_array($employeeId, $attended, true)) {
                continue;
            }

            $employees[] = [
                'employee_public_id' => is_string($row['employee_public_id'] ?? null) ? $row['employee_public_id'] : null,
                'employee_name' => (string) ($row['employee_name'] ?? ''),
                'days' => count((array) $row['unexplained_absence_days']),
            ];
        }

        return ['count' => count($employees), 'employees' => $employees];
    }
}
