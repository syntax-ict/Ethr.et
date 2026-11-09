<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\ExpectedDay;
use App\Enums\LeaveStatus;
use App\Enums\OvertimeRequestStatus;
use App\Exceptions\PayrollRunRefused;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Services\Attendance\ExpectedDays;
use App\Services\Attendance\ShiftMatcher;
use App\Services\Calendar\EthiopianCalendar;
use App\Services\CurrentTenant;
use App\Services\Holiday\HolidayService;
use App\Services\Leave\LeaveDayCalculator;
use App\Services\Leave\SickLeaveTiers;
use App\Support\Cents;
use App\Support\TenantTime;
use App\Support\WorkingWeek;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Every statutory value this engine applies comes from a StatutoryRuleSet,
 * resolved once per run for the organisation's type and the period
 * (StatutoryRules): the working days and hours that turn a monthly salary
 * into daily and hourly rates, the Art. 67 overtime limits (used only to
 * flag a run: overtime worked is paid whatever the limit, Art. 68), which
 * overtime counts against them, the night window, the pension scheme, the
 * fiscal year and the Pagume strategy. Until 2026-10-27 the first five were
 * constants here.
 */
final class PayrollEngine
{
    /** @var array<int, int> tenant id => the organisation's daily overtime limit, in minutes */
    private array $dailyOvertimeLimits = [];

    /** @var array<int, bool> tenant id => whether overtime must be approved in advance */
    private array $overtimeApprovalRequired = [];

    /** @var array<int, bool> tenant id => whether approved lateness is deducted */
    private array $latenessDeductions = [];

    public function __construct(
        private readonly TaxCalculator $taxCalculator,
        private readonly PensionCalculator $pensionCalculator,
        private readonly OvertimeCalculator $overtimeCalculator,
        private readonly LoanService $loanService,
        private readonly CostSharingService $costSharingService,
        private readonly AllowanceService $allowanceService,
        private readonly OvertimeClassifier $overtimeClassifier,
        private readonly HolidayService $holidayService,
        private readonly LeaveDayCalculator $leaveDayCalculator,
        private readonly EthiopianCalendar $calendar,
        private readonly ShiftMatcher $shiftMatcher,
        private readonly PayrollBalanceService $balances,
        private readonly ExpectedDays $expectedDays,
        private readonly StatutoryRules $statutoryRules,
        private readonly SickLeaveTiers $sickLeave,
    ) {}

    /** @var array<string, bool> keyed by tenant id and period */
    private array $attendanceInUse = [];

    /**
     * Create the run, then compute it, in one call.
     *
     * Kept as-is so existing synchronous callers and tests keep working. The
     * HTTP path no longer uses it: PayrollController calls begin() and lets
     * ProcessPayrollJob call runEntries(), because computing every employee
     * inline in a request does not fit inside a shared host's
     * max_execution_time. See docs/audit/BASELINE.md §13a.
     *
     * The split is deliberately a split and not a rewrite. Nothing below this
     * line changed; master plan §20 says do not redesign payroll calculation
     * unless tests prove a defect, and none do.
     */
    public function process(
        int $tenantId,
        Carbon $periodStart,
        Carbon $periodEnd,
        int $processedBy,
        ?string $idempotencyKey = null,
    ): PayrollProcessResult {
        $result = $this->begin($tenantId, $periodStart, $periodEnd, $processedBy, $idempotencyKey);

        if ($result->wasDuplicate) {
            return $result;
        }

        return new PayrollProcessResult($this->runEntries($result->run));
    }

    /**
     * Reserve a run without computing it. Fast enough for a request.
     *
     * Returns the existing run with wasDuplicate when the idempotency key has
     * been seen before — convention 10, replay must not double-process.
     */
    public function begin(
        int $tenantId,
        Carbon $periodStart,
        Carbon $periodEnd,
        int $processedBy,
        ?string $idempotencyKey = null,
    ): PayrollProcessResult {
        if ($idempotencyKey) {
            $existing = PayrollRun::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return new PayrollProcessResult($existing, wasDuplicate: true);
            }
        }

        $run = PayrollRun::create([
            'tenant_id' => $tenantId,
            'period_label' => $periodStart->format('F Y'),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'idempotency_key' => $idempotencyKey,
            'status' => 'processing',
            'processed_by' => $processedBy,
            'processed_at' => now(),
        ]);

        return new PayrollProcessResult($run);
    }

    /**
     * Compute every entry for a run that begin() reserved.
     *
     * The slow half — chunked over every active employee, computing tax,
     * pension, overtime, loans, allowances and cost-sharing per row. This is
     * what ProcessPayrollJob runs.
     *
     * It was one transaction for the whole run, inside one web request. The
     * host stops a request after 30 s of CPU, and a run of 2,000 people takes
     * 18 s on a fast machine: a larger or slower one was stopped part-way,
     * again on every resubmission. It now commits in batches of 100 and, once
     * `$deadline` (a `microtime(true)` value) has passed, returns with the run
     * still `processing` and the rest for the next call, which skips whoever
     * already has an entry. At least one batch is written per call, so each
     * call makes progress however short the time it was given. With no
     * deadline the whole run is done in one call, as before.
     *
     * A run that is stopped for good leaves no entries: ProcessPayrollJob::
     * failed() removes the ones written so far, so it can be submitted again.
     * Calculating moves no balance at all (PayrollBalanceService does that on
     * approval), so a failure has nothing else to undo. The run's totals and
     * counts are written once, from what the entries say, when the last batch
     * is in.
     */
    public function runEntries(PayrollRun $run, ?float $deadline = null): PayrollRun
    {
        $tenantId = $run->tenant_id;

        // PayrollRun casts both to `date`, so these are already Carbon at
        // runtime. Carbon::parse() accepts a Carbon instance unchanged, so this
        // is correct either way and does not need an instanceof guard - PHPStan
        // rightly flagged one as dead, because the model has no @property
        // docblock telling it what the cast produces.
        $periodStart = Carbon::parse($run->period_start);
        $periodEnd = Carbon::parse($run->period_end);

        [
            'rules' => $rules,
            'pagumen_strategy' => $pagumenStrategy,
            'fiscal_year_start_month' => $fiscalYearStartMonth,
            'pension_rule' => $pensionRule,
            'tax_basis' => $taxBasis,
            'pagume' => $pagume,
        ] = $this->prepare($tenantId, $periodStart, $periodEnd);

        // Whoever already has an entry, from an earlier call that stopped at
        // its deadline. Empty on a first call.
        $done = PayrollEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('payroll_run_id', $run->id)
            ->pluck('employee_id')
            ->all();
        $visited = [];
        $paused = false;

        $this->payableEmployees($tenantId, $periodStart, $periodEnd)
            ->when($done !== [], fn ($query) => $query->whereNotIn('employees.id', $done))
            // For the contract fallback in processEmployee(): an employee whose
            // salary_cents is unset may still have a contract that states one,
            // and until 2026-10-15 that employee silently vanished from the run
            // (production QA, F1). Loaded here so the fallback costs no query
            // per employee.
            ->with(['contracts' => fn ($q) => $q->where('salary_cents', '>', 0)])
            ->chunkById(100, function ($employees) use (
                $run, $periodStart, $periodEnd,
                $pagumenStrategy, $pagume, $fiscalYearStartMonth, $pensionRule, $taxBasis, $rules,
                $deadline, &$visited, &$paused,
            ) {
                // One batch, one transaction: a batch is whole or absent, so a
                // call that is stopped in the middle of one resumes cleanly.
                DB::transaction(function () use (
                    $employees, $run, $periodStart, $periodEnd,
                    $pagumenStrategy, $pagume, $fiscalYearStartMonth, $pensionRule, $taxBasis, $rules,
                ): void {
                    foreach ($employees as $employee) {
                        $this->processEmployee(
                            $employee,
                            $run,
                            $periodStart,
                            $periodEnd,
                            $pagumenStrategy,
                            $pagume,
                            $fiscalYearStartMonth,
                            $pensionRule,
                            $taxBasis,
                            $rules,
                        );
                    }
                });

                foreach ($employees as $employee) {
                    $visited[] = $employee->id;
                }

                // The deadline is looked at after a batch is written, never
                // before, so every call makes progress.
                if ($deadline !== null && microtime(true) >= $deadline) {
                    $paused = true;

                    return false;
                }

                return true;
            });

        // Stopped at the deadline with people still to pay: the next call
        // carries on. Stopped on the very last batch, there is nothing left,
        // and the run is finished now rather than by a job that would find
        // nothing to do.
        if ($paused && $this->payableEmployees($tenantId, $periodStart, $periodEnd)
            ->whereNotIn('employees.id', [...$done, ...$visited])
            ->exists()) {
            return $run->refresh();
        }

        // The totals are what the entries say, written once, together with
        // the count of people skipped for having no salary: they are the
        // payable people no entry exists for.
        DB::transaction(function () use ($run, $tenantId, $periodStart, $periodEnd): void {
            $totals = PayrollEntry::query()
                ->where('tenant_id', $tenantId)
                ->where('payroll_run_id', $run->id)
                ->selectRaw('count(*) as entries, coalesce(sum(gross_cents), 0) as gross, coalesce(sum(net_cents), 0) as net, coalesce(sum(income_tax_cents), 0) as tax')
                ->first();
            $entries = (int) ($totals->entries ?? 0);

            $run->update([
                'status' => 'completed',
                'employee_count' => $entries,
                'skipped_no_salary' => max(0, $this->payableEmployees($tenantId, $periodStart, $periodEnd)->count() - $entries),
                'gross_total_cents' => (int) ($totals->gross ?? 0),
                'net_total_cents' => (int) ($totals->net ?? 0),
                'tax_total_cents' => (int) ($totals->tax ?? 0),
            ]);
        });

        return $run->refresh();
    }

    /**
     * A dry run: what a run for the period would pay, worked out by the same
     * code a run uses (prepare() and computeEntry()) and written nowhere. No
     * run is reserved, no lock is taken, no entry is created and no balance
     * moves, so it can be asked again as often as needed.
     *
     * Paged by employee, `$limit` at a time after `$afterEmployeeId`, and it
     * stops early once `$deadline` has passed, so a large organisation is
     * previewed in pages that each fit the shared host's request limit.
     * `next_after` names the last employee looked at when there are more.
     *
     * @return array{entries: list<array{employee: Employee, attributes: array<string, mixed>}>, next_after: int|null, rules: StatutoryRuleSet, tax_basis: array<string, mixed>, pension_rule: PensionRuleSnapshot}
     */
    public function preview(int $tenantId, Carbon $periodStart, Carbon $periodEnd, ?int $afterEmployeeId = null, ?float $deadline = null, int $limit = 100): array
    {
        [
            'rules' => $rules,
            'pagumen_strategy' => $pagumenStrategy,
            'fiscal_year_start_month' => $fiscalYearStartMonth,
            'pension_rule' => $pensionRule,
            'tax_basis' => $taxBasis,
            'pagume' => $pagume,
        ] = $this->prepare($tenantId, $periodStart, $periodEnd);

        $employees = $this->payableEmployees($tenantId, $periodStart, $periodEnd)
            ->when($afterEmployeeId !== null, fn ($query) => $query->where('employees.id', '>', $afterEmployeeId))
            ->with(['contracts' => fn ($q) => $q->where('salary_cents', '>', 0)])
            ->orderBy('employees.id')
            ->limit($limit + 1)
            ->get();

        $more = $employees->count() > $limit;
        $entries = [];
        $last = null;

        foreach ($employees->take($limit)->values() as $index => $employee) {
            $attributes = $this->computeEntry($employee, $periodStart, $periodEnd, $pagumenStrategy, $pagume, $fiscalYearStartMonth, $pensionRule, $taxBasis, $rules);
            $last = $employee->id;

            if ($attributes !== null) {
                $entries[] = ['employee' => $employee, 'attributes' => $attributes];
            }

            if ($deadline !== null && microtime(true) >= $deadline && $index < min($limit, $employees->count()) - 1) {
                $more = true;
                break;
            }
        }

        return [
            'entries' => $entries,
            'next_after' => $more ? $last : null,
            'rules' => $rules,
            'tax_basis' => $taxBasis,
            'pension_rule' => $pensionRule,
        ];
    }

    /**
     * The employees a run for the period pays: everyone in a working status,
     * and anyone whose leaving date is in the period or after it. Attendance
     * reconciliation asks the same question, so it reads exactly these people.
     *
     * @return Builder<Employee>
     */
    public function payableEmployees(int $tenantId, Carbon $periodStart, Carbon $periodEnd): Builder
    {
        return Employee::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            // Someone who left during the period is owed the days they
            // worked in it. Until 2026-10-20 only the three working
            // statuses were selected, so a leaver vanished from their
            // last run, though ACCOUNTANT-SIGN-OFF.md §1 says a mid-period
            // termination is prorated (blueprint D3).
            ->where(fn ($q) => $q
                ->whereIn('status', [
                    EmployeeStatus::HIRED,
                    EmployeeStatus::PROBATION,
                    EmployeeStatus::CONFIRMED,
                ])
                // And someone whose leaving is recorded ahead of its date:
                // the transition sets the status at once and the date as
                // given, so a retirement entered on 20 November for 31
                // December dropped that person from November's run, unpaid
                // and unflagged. A termination date after the period pays
                // the full period: proration reads only a date inside it.
                ->orWhere(fn ($left) => $left
                    ->whereIn('status', [
                        EmployeeStatus::RESIGNED,
                        EmployeeStatus::TERMINATED,
                        EmployeeStatus::RETIRED,
                    ])
                    ->whereDate('termination_date', '>=', $periodStart->toDateString())));
    }

    /**
     * Approve a completed run, and only now draw its loan and cost-sharing
     * balances down by what its payslips deducted.
     *
     * The run is re-read under a lock, so two approvals racing past the
     * controller's status check apply the balances once, not twice. The
     * balance movements and the status change commit together or not at all.
     */
    public function approve(PayrollRun $run, int $approvedBy): PayrollRun
    {
        return DB::transaction(function () use ($run, $approvedBy): PayrollRun {
            $locked = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'completed') {
                throw PayrollRunRefused::notCompleted();
            }

            $movements = $this->balances->apply($locked);

            $locked->update([
                'status' => 'approved',
                'approved_by' => $approvedBy,
                'approved_at' => now(),
            ]);

            if ($movements !== []) {
                AuditLog::record('payroll.balances_applied', $locked, ['movements' => $movements]);
            }

            return $locked;
        });
    }

    /**
     * Record that an approved run's money has left: the date, the bank's
     * reference and who confirmed it. The run's last state.
     *
     * A paid run cannot be voided (owner decision Q6): the money is in
     * employees' accounts, so a mistake is corrected in the next run, where it
     * shows, rather than by rewriting this one.
     */
    public function markPaid(PayrollRun $run, int $paidBy, string $paidOn, ?string $reference, ?string $note): PayrollRun
    {
        return DB::transaction(function () use ($run, $paidBy, $paidOn, $reference, $note): PayrollRun {
            $locked = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'approved') {
                throw PayrollRunRefused::notApprovedForPaymentRecord();
            }

            $locked->update([
                'status' => 'paid',
                'paid_on' => $paidOn,
                'paid_at' => now(),
                'paid_by' => $paidBy,
                'payment_reference' => $reference,
                'payment_note' => $note,
            ]);

            return $locked;
        });
    }

    /**
     * Void a completed or approved run.
     *
     * A completed run never moved a balance, so voiding it gives nothing
     * back. An approved run did, and voiding it reverses exactly those
     * movements in the same transaction as the status change: until
     * 2026-10-17 a void restored nothing and the reprocess that follows
     * deducted again.
     */
    public function void(PayrollRun $run, int $voidedBy, string $reason): PayrollRun
    {
        return DB::transaction(function () use ($run, $voidedBy, $reason): PayrollRun {
            $locked = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'paid') {
                throw PayrollRunRefused::paidCannotVoid();
            }

            if (! in_array($locked->status, ['completed', 'approved'], true)) {
                throw PayrollRunRefused::cannotVoid();
            }

            $movements = $locked->status === 'approved' ? $this->balances->reverse($locked) : [];

            $locked->update([
                'status' => 'voided',
                'voided_at' => now(),
                'voided_by' => $voidedBy,
                'void_reason' => $reason,
            ]);

            if ($movements !== []) {
                AuditLog::record('payroll.balances_reversed', $locked, ['movements' => $movements]);
            }

            return $locked->fresh();
        });
    }

    public function reprocess(PayrollRun $voidedRun, int $processedBy, ?string $idempotencyKey = null): PayrollProcessResult
    {
        $begun = $this->reserve(
            $voidedRun->tenant_id,
            Carbon::parse($voidedRun->period_start),
            Carbon::parse($voidedRun->period_end),
            $processedBy,
            $idempotencyKey,
        );

        if (! $begun->wasDuplicate) {
            $begun->run->update(['reprocessed_from_id' => $voidedRun->id]);
        }

        // Reserved only. The caller queues ProcessPayrollJob, as `/process`
        // does: it computes in batches against the request's deadline,
        // carries on where it stopped, and marks a run that dies `failed`.
        // Computed inline here, a large organisation's run outlived the
        // host's time limit, or a missing rule threw, and the run stayed
        // `processing` with nothing able to void, approve or replace it.
        return $begun;
    }

    /**
     * begin(), once no live run covers any of the period: one live run per
     * period. ProcessPayrollRequest says so before a run is asked for, but a
     * check in the request and an insert later let two requests both pass,
     * and reprocessing never asked at all. Here the check and the insert run
     * under one lock per organisation, so the second of two waits and is
     * refused. A replayed idempotency key is answered with its run as before.
     */
    public function reserve(int $tenantId, Carbon $periodStart, Carbon $periodEnd, int $processedBy, ?string $idempotencyKey = null): PayrollProcessResult
    {
        return Cache::lock("payroll-reserve:{$tenantId}", 15)->block(10, function () use ($tenantId, $periodStart, $periodEnd, $processedBy, $idempotencyKey): PayrollProcessResult {
            $replay = $idempotencyKey !== null && PayrollRun::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->exists();

            if (! $replay) {
                $live = PayrollRun::query()
                    ->where('tenant_id', $tenantId)
                    ->whereNotIn('status', ['voided', 'failed'])
                    ->whereDate('period_start', '<=', $periodEnd->toDateString())
                    ->whereDate('period_end', '>=', $periodStart->toDateString())
                    ->orderByDesc('period_start')
                    ->first();

                if ($live !== null) {
                    throw PayrollRunRefused::periodOverlaps($live);
                }
            }

            return $this->begin($tenantId, $periodStart, $periodEnd, $processedBy, $idempotencyKey);
        });
    }

    /**
     * @param  array{days: int, ethiopian_year: int|null, pagume_length: int|null}  $pagume
     */
    /**
     * The salary stated by the contract in force during the period, in cents,
     * or null when no contract states one.
     *
     * A contract's effective window runs from start_date to ended_at (set when
     * it is renewed or terminated early), else end_date, else open. Status is
     * deliberately not consulted: a RENEWED or EXPIRED contract still governed
     * the months it covered, and the window already encodes that. The contract
     * in force at the period's end wins; failing that, the latest one that
     * overlaps the period at all (a contract that ended mid-period still pays
     * that month under the engine's one-salary-per-run model).
     */
    private function contractSalaryFor(Employee $employee, Carbon $periodStart, Carbon $periodEnd): ?int
    {
        $overlapping = $employee->contracts
            ->filter(function ($contract) use ($periodStart, $periodEnd): bool {
                if ((int) $contract->salary_cents <= 0) {
                    return false;
                }
                if ($contract->start_date->gt($periodEnd)) {
                    return false;
                }
                $effectiveEnd = $contract->ended_at ?? $contract->end_date;

                return $effectiveEnd === null || ! $effectiveEnd->lt($periodStart);
            })
            ->sortByDesc(fn ($contract) => $contract->start_date);

        $atPeriodEnd = $overlapping->first(function ($contract) use ($periodEnd): bool {
            $effectiveEnd = $contract->ended_at ?? $contract->end_date;

            return ! $contract->start_date->gt($periodEnd)
                && ($effectiveEnd === null || ! $effectiveEnd->lt($periodEnd));
        });

        $salary = ($atPeriodEnd ?? $overlapping->first())?->salary_cents;

        return $salary !== null ? (int) $salary : null;
    }

    /**
     * The statutory rules in force for a period, resolved once for the whole
     * period for the organisation's type: the parameters, the pension rule for
     * its scheme and the tax ladder with its legal basis. All are written into
     * every entry's calculation_log, so an approved run keeps saying what it
     * applied (convention #11). A rule with no row in force refuses the run by
     * name here, before any entry is written. Shared by a run and a preview,
     * so the two cannot resolve differently.
     *
     * @return array{rules: StatutoryRuleSet, pagumen_strategy: string, fiscal_year_start_month: int, pension_rule: PensionRuleSnapshot, tax_basis: array<string, mixed>, pagume: array{days: int, ethiopian_year: int|null, pagume_length: int|null}}
     */
    private function prepare(int $tenantId, Carbon $periodStart, Carbon $periodEnd): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $rules = $this->statutoryRules->for($tenant, $periodStart);

        return [
            'rules' => $rules,
            'pagumen_strategy' => $rules->string('pagume.proration'),
            'fiscal_year_start_month' => $rules->int('fiscal_year.start_month'),
            'pension_rule' => $this->pensionCalculator->rule($tenantId, $rules->string('pension.default_scheme'), $periodStart),
            // Since 1.4 the ladder itself travels with its basis, so a month can
            // be re-taxed from its own log even if a tenant's rows were replaced.
            'tax_basis' => $this->taxCalculator->basis($tenantId, $periodStart)
                + ['brackets' => $this->taxCalculator->ladder($tenantId, $periodStart)],
            'pagume' => $this->calendar->pagumeDaysInRange($periodStart, $periodEnd),
        ];
    }

    /**
     * Writes one employee's entry for a run: exactly what computeEntry()
     * works out, with the run it belongs to.
     *
     * @param  array<string, mixed>  $taxBasis
     * @param  array{days: int, ethiopian_year: int|null, pagume_length: int|null}  $pagume
     */
    private function processEmployee(
        Employee $employee,
        PayrollRun $run,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $pagumenStrategy,
        array $pagume,
        int $fiscalYearStartMonth,
        PensionRuleSnapshot $pensionRule,
        array $taxBasis,
        StatutoryRuleSet $rules,
    ): ?PayrollEntry {
        $attributes = $this->computeEntry($employee, $periodStart, $periodEnd, $pagumenStrategy, $pagume, $fiscalYearStartMonth, $pensionRule, $taxBasis, $rules);

        return $attributes === null ? null : PayrollEntry::create([...$attributes, 'payroll_run_id' => $run->id]);
    }

    /**
     * One employee's pay for a period, worked out and not written: the
     * attributes of the entry a run would create, `calculation_log` included.
     * Null for someone with no salary to pay. A run writes it; a preview
     * shows it. It reads and writes nothing else: no balance moves until a
     * run is approved (PayrollBalanceService).
     *
     * @param  array<string, mixed>  $taxBasis
     * @param  array{days: int, ethiopian_year: int|null, pagume_length: int|null}  $pagume
     * @return array<string, mixed>|null
     */
    private function computeEntry(
        Employee $employee,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $pagumenStrategy,
        array $pagume,
        int $fiscalYearStartMonth,
        PensionRuleSnapshot $pensionRule,
        array $taxBasis,
        StatutoryRuleSet $rules,
    ): ?array {
        $workingDaysPerMonth = $rules->int('payroll.working_days_per_month');
        $hoursPerDay = $rules->int('payroll.hours_per_day');
        $basicSalary = $employee->salary_cents;

        // An employee whose own salary is unset is paid what the contract in
        // force during this period states. Writes to employees.salary_cents are
        // kept in sync by EmployeeContractService for contracts effective at
        // creation time; this covers the ones that became effective later, and
        // rows from before the sync existed (production QA 2026-10-15, F1).
        if ($basicSalary <= 0) {
            $basicSalary = $this->contractSalaryFor($employee, $periodStart, $periodEnd) ?? 0;
        }

        if ($basicSalary <= 0) {
            return null;
        }

        $log = [
            'version' => '1.8',
            'calculated_at' => now()->toIso8601String(),
            // Since 1.4: every amount is an exact fraction rounded once, half
            // away from zero (App\Support\Cents), not a float passed to round().
            'rounding' => Cents::ROUNDING,
            // Since 1.5: every statutory parameter applied, the organisation
            // type it was resolved for, and the row each value came from.
            'statutory_rules' => $rules->toLog(),
            // Since 1.3: the loan and cost-sharing deductions below are what
            // the payslip withholds, and PayrollBalanceService draws the
            // balances down by exactly those figures when the run is approved.
            // Nothing in this method moves a balance.
            PayrollBalanceService::LOG_KEY => PayrollBalanceService::MOVE_ON_APPROVAL,
            'inputs' => [
                'employee_id' => $employee->public_id,
                'basic_salary_cents' => $basicSalary,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'hire_date' => Carbon::parse($employee->hire_date)->toDateString(),
                'termination_date' => $employee->termination_date?->toDateString(),
                'status' => $employee->status,
                'pagumen_proration_strategy' => $pagumenStrategy,
                'fiscal_year_start_month' => $fiscalYearStartMonth,
            ],
            'steps' => [],
        ];

        // Mid-period proration: an employee who joined after the period began,
        // or left before it ended, is paid only for the days of the period
        // they were employed. A termination date outside the period changes
        // nothing.
        $totalPeriodDays = (int) $periodStart->diffInDays($periodEnd) + 1;
        $hireDate = $employee->hire_date;
        $terminationDate = $employee->termination_date;
        $firstDay = $hireDate && $hireDate->gt($periodStart) ? $hireDate->copy()->startOfDay() : $periodStart->copy()->startOfDay();
        $lastDay = $terminationDate && $terminationDate->between($periodStart, $periodEnd)
            ? $terminationDate->copy()->startOfDay()
            : $periodEnd->copy()->startOfDay();
        $hireProrationFactor = 1.0;
        $workedDays = $totalPeriodDays;
        if ($firstDay->gt($periodStart) || $lastDay->lt($periodEnd->copy()->startOfDay())) {
            $workedDays = $lastDay->lt($firstDay) ? 0 : (int) $firstDay->diffInDays($lastDay) + 1;
            // For the log only; the amount below is the exact fraction.
            $hireProrationFactor = $workedDays / $totalPeriodDays;
        }

        // Pagumen (13th month) proration. Under `daily_rate` the days of the
        // period that fall in Pagume are paid at annual/365 per day instead of
        // the full monthly salary; under `full_month` (or when the period does
        // not touch Pagume) the monthly salary applies unchanged. The hire
        // factor composes multiplicatively on top of whichever base applies,
        // so an employee hired mid-Pagume is prorated on both axes.
        $pagumeDaysInPeriod = $pagume['days'];
        $pagumenApplied = $pagumenStrategy === 'daily_rate' && $pagumeDaysInPeriod > 0;

        if ($pagumenApplied) {
            $nonPagumeDays = $totalPeriodDays - $pagumeDaysInPeriod;
            // Pagume days: annual (12 x monthly) / 365, per actual Pagume day.
            $pagumeBasic = Cents::mulDiv($basicSalary * 12, $pagumeDaysInPeriod, 365);
            // Any non-Pagume days in the same run (uncommon — Pagume normally
            // has its own boundaried run) keep the monthly basis, scaled to
            // their share of the period.
            $nonPagumeBasic = $nonPagumeDays > 0
                ? Cents::mulDiv($basicSalary, $nonPagumeDays, $totalPeriodDays)
                : 0;
            $periodBasic = $pagumeBasic + $nonPagumeBasic;
        } else {
            $periodBasic = $basicSalary;
        }

        $proratedBasic = Cents::mulDiv($periodBasic, $workedDays, $totalPeriodDays);

        $log['steps'][] = [
            'step' => 'proration',
            'proration_factor' => $hireProrationFactor,
            'original_basic_cents' => $basicSalary,
            'period_basic_cents' => $periodBasic,
            'prorated_basic_cents' => $proratedBasic,
        ];

        $log['steps'][] = [
            'step' => 'pagumen_proration',
            'strategy' => $pagumenStrategy,
            'applied' => $pagumenApplied,
            'pagume_days_in_period' => $pagumeDaysInPeriod,
            'pagume_total_days_in_year' => $pagume['pagume_length'],
            'ethiopian_year' => $pagume['ethiopian_year'],
            'period_basic_cents' => $periodBasic,
        ];

        // Unpaid leave: approved leave of non-paid types reduces earned basic by
        // the contractual daily rate (full monthly basic / working days) for each
        // unpaid working day in the period. Paid leave has no effect on pay.
        $unpaidLeaveDays = $this->gatherUnpaidLeaveDays($employee, $periodStart, $periodEnd);
        $dailyRate = Cents::mulDiv($basicSalary, 1, $workingDaysPerMonth);
        // Leave days are counted in halves and occasionally finer; thousandths
        // carry any of them exactly.
        $unpaidLeaveDeduction = $unpaidLeaveDays > 0
            ? min(Cents::mulDiv($dailyRate, (int) round($unpaidLeaveDays * 1000), 1000), $proratedBasic)
            : 0;
        $proratedBasic -= $unpaidLeaveDeduction;

        $log['steps'][] = [
            'step' => 'unpaid_leave',
            'unpaid_leave_days' => $unpaidLeaveDays,
            'daily_rate_cents' => $dailyRate,
            'deduction_cents' => $unpaidLeaveDeduction,
            'basic_after_unpaid_leave_cents' => $proratedBasic,
        ];

        // Sick leave under Art. 85 (A-Z review E-08): within twelve months of
        // the first day of sickness, the first month is paid in full, the
        // next two at half the daily rate and the last three not at all. The
        // tier of each day is its place in that period, so a long illness
        // moves from full to half pay across payslips without anyone acting.
        $sick = $this->sickLeave->inPeriod($employee, $periodStart, $periodEnd, $workingDaysPerMonth);
        // In thousandths of a day, as for unpaid leave. A half-pay day keeps
        // `leave.sick.half_pay_ratio` of the daily rate, so withholds the rest:
        // 500 at the law's one half.
        $halfPay = $rules->list('leave.sick.half_pay_ratio');
        $halfPayWithheld = 1000 * ((int) $halfPay['denominator'] - (int) $halfPay['numerator']) / (int) $halfPay['denominator'];
        $sickThousandths = (int) round($sick['unpaid'] * 1000 + $sick['half'] * $halfPayWithheld);
        $sickDeduction = $sickThousandths > 0
            ? min(Cents::mulDiv($dailyRate, $sickThousandths, 1000), $proratedBasic)
            : 0;
        $proratedBasic -= $sickDeduction;

        $log['steps'][] = [
            'step' => 'sick_leave',
            'half_pay_days' => $sick['half'],
            'unpaid_days' => $sick['unpaid'],
            'daily_rate_cents' => $dailyRate,
            'deduction_cents' => $sickDeduction,
            'basic_after_sick_leave_cents' => $proratedBasic,
        ];

        // Lateness: only what was decided. A late arrival deducts nothing until
        // a lateness correction for it is approved by someone who processes
        // payroll, and only in an organisation that switched deductions on
        // with a stated basis (Proclamation 1156/2019 Art. 59). Logged only
        // then, so an organisation that never did gets the run it always got.
        if ($this->latenessDeductionEnabled($employee->tenant_id)) {
            $lateness = $this->decidedLateness($employee, $periodStart, $periodEnd);
            $minutesPerDay = $rules->int('payroll.hours_per_day') * 60;
            $minuteRate = $dailyRate / $minutesPerDay;
            $uncapped = Cents::mulDiv($lateness['minutes'], $dailyRate, $minutesPerDay);
            // Art. 59 limits what may be deducted from a month's wage to a third,
            // and reads as one total, not a third per kind (decided 2026-10-28,
            // docs/APPROVAL-ENGINE-DESIGN.md). Loans and cost sharing were
            // deducted before lateness existed and are left exactly as they
            // are; lateness takes only the headroom they leave. Loan instalments
            // are fixed. Cost sharing is a share of gross, not known yet, so it
            // is estimated on basic here, and the run register flags any payslip
            // whose actual total still passes the third.
            // The share is `payroll.deduction_cap_ratio`, one third in law.
            $capRatio = $rules->list('payroll.deduction_cap_ratio');
            $third = intdiv($proratedBasic * (int) $capRatio['numerator'], (int) $capRatio['denominator']);
            $otherDeductions = $this->loanService->calculateMonthlyDeduction($employee)
                + $this->costSharingService->calculateDeduction(
                    $this->costSharingService->getActiveObligation($employee),
                    $proratedBasic,
                );
            $cap = max(0, $third - $otherDeductions);
            $latenessDeduction = min($uncapped, $cap);
            $proratedBasic -= $latenessDeduction;

            $log['steps'][] = [
                'step' => 'lateness',
                'deducted_minutes' => $lateness['minutes'],
                'minute_rate_cents' => round($minuteRate, 4),
                'uncapped_cents' => $uncapped,
                'third_of_basic_cents' => $third,
                'other_deductions_cents' => $otherDeductions,
                'cap_cents' => $cap,
                'capped' => $uncapped > $cap,
                'deduction_cents' => $latenessDeduction,
                'basic_after_lateness_cents' => $proratedBasic,
                'corrections' => $lateness['corrections'],
            ];
        }

        $log['steps'][] = [
            'step' => 'absences',
            // Flagged for review, never deducted: pay is unchanged.
            'unexplained_absence_days' => $this->unexplainedAbsences($employee, $periodStart, $periodEnd),
        ];

        ['buckets' => $overtimeByType, 'by_day' => $overtimeByDay] = $this->gatherOvertimeByType($employee, $periodStart, $periodEnd, $rules);
        $overtimeRates = $this->overtimeCalculator->ratesFor($employee->tenant_id, $periodStart);

        $overtimeAmount = 0;
        $overtimeMinutes = 0;
        $overtimeBreakdown = [];
        // The hourly rate is the contract's: proration, unpaid leave and
        // lateness change how many days are paid, not what an hour is worth
        // (Art. 68). Until 2026-11-03 this read the prorated basic, so a
        // mid-month hire's overtime was paid at a fraction of the law's rate.
        foreach ($overtimeByType as $type => $minutes) {
            $amount = $this->overtimeCalculator->calculate(
                $basicSalary,
                $workingDaysPerMonth,
                $hoursPerDay,
                $minutes,
                $type,
                $employee->tenant_id,
                $periodStart,
            );
            $overtimeAmount += $amount;
            $overtimeMinutes += $minutes;
            $overtimeBreakdown[$type] = [
                'minutes' => $minutes,
                'amount_cents' => $amount,
                'rate' => $overtimeRates[$type] ?? null,
            ];
        }

        $log['steps'][] = [
            'step' => 'overtime',
            // Since 1.6: the monthly basic the hourly rate is taken from.
            'hourly_rate_base_cents' => $basicSalary,
            'overtime_minutes' => $overtimeMinutes,
            'overtime_amount_cents' => $overtimeAmount,
            'by_type' => $overtimeBreakdown,
            // Flagged for review, never capped: every minute above is paid.
            'limits' => $this->overtimeLimits($employee, $overtimeByDay, $periodStart, $periodEnd, $rules),
        ];

        $allowanceResult = $this->allowanceService->resolve($employee, $proratedBasic, $workedDays, $totalPeriodDays);
        $allowances = $allowanceResult['items'];
        $totalAllowances = $allowanceResult['total_cents'];
        $nonTaxableAllowances = $allowanceResult['non_taxable_cents'];

        $log['steps'][] = [
            'step' => 'allowances',
            'items' => $allowances,
            'total_allowances_cents' => $totalAllowances,
            'taxable_allowances_cents' => $allowanceResult['taxable_cents'],
            'non_taxable_allowances_cents' => $nonTaxableAllowances,
        ];

        $grossSalary = $proratedBasic + $overtimeAmount + $totalAllowances;

        $log['steps'][] = [
            'step' => 'gross',
            'prorated_basic_cents' => $proratedBasic,
            'overtime_cents' => $overtimeAmount,
            'allowances_cents' => $totalAllowances,
            'gross_cents' => $grossSalary,
        ];

        // Taxable income excludes non-taxable allowances (spec S23 step 8).
        $taxableAmount = $grossSalary - $nonTaxableAllowances;
        // The period start, not the run date. Payroll is re-runnable — `void()`
        // reprocesses a past period — and since Proclamation 1395/2025 there are
        // two tax ladders in force at different times. Resolving against "now"
        // would retax a June-2025 period at July-2025 rates and silently produce
        // a different payslip from the one the employee was actually paid on.
        $incomeTax = $this->taxCalculator->calculate($taxableAmount, $employee->tenant_id, $periodStart);

        $log['steps'][] = [
            'step' => 'income_tax',
            'gross_cents' => $grossSalary,
            'non_taxable_allowances_cents' => $nonTaxableAllowances,
            'taxable_amount_cents' => $taxableAmount,
            'income_tax_cents' => $incomeTax,
            'rule' => $taxBasis,
        ];

        $pension = $this->pensionCalculator->apply($pensionRule, $employee, $proratedBasic, $totalAllowances, $periodEnd, $rules->list('pension.applies_to'));

        $log['steps'][] = [
            'step' => 'pension',
            // Kept under its old name: readers before 2026-10-14 used it.
            'pensionable_basic_cents' => $proratedBasic,
            'pensionable_cents' => $pension['pensionable_cents'],
            'contributory_cents' => $pension['contributory_cents'],
            'employee_rate' => $pensionRule->employeeRate,
            'employer_rate' => $pensionRule->employerRate,
            'exempt' => $pension['exempt'],
            'exempt_reason' => $pension['exempt_reason'],
            'employee_pension_cents' => $pension['employee_cents'],
            'employer_pension_cents' => $pension['employer_cents'],
            'rule' => $pensionRule->toLog(),
        ];

        // Each loan is charged its instalment or what it still owes, whichever
        // is less, and itemised so approval can draw each balance down by
        // exactly what this payslip withheld.
        $loanDeduction = 0;
        $loanLines = [];
        foreach ($this->loanService->getActiveLoans($employee) as $loan) {
            $deduction = $this->loanService->deductionFor($loan);
            if ($deduction <= 0) {
                continue;
            }

            $loanDeduction += $deduction;
            $loanLines[] = [
                'loan_id' => $loan->public_id,
                'monthly_deduction_cents' => $loan->monthly_deduction_cents,
                'remaining_before_cents' => $loan->remaining_cents,
                'deduction_cents' => $deduction,
                'remaining_after_cents' => $loan->remaining_cents - $deduction,
            ];
        }

        $log['steps'][] = [
            'step' => 'loan_deductions',
            'loan_deduction_cents' => $loanDeduction,
            'loans' => $loanLines,
        ];

        // Cost sharing is a percentage of prorated gross, so it is computed after
        // gross is final and recorded before the net roll-up. The log carries the
        // rate and both balances, not just the amount: convention #11 requires the
        // trace to explain *why* a figure is what it is, and a bare deduction
        // cannot be re-derived once the row's balance has moved on.
        $costSharing = $this->costSharingService->getActiveObligation($employee);
        $costSharingDeduction = $this->costSharingService->calculateDeduction($costSharing, $grossSalary);
        $costSharingOutstandingBefore = $costSharing?->outstanding_cents;

        $log['steps'][] = [
            'step' => 'cost_sharing',
            'obligation_id' => $costSharing?->public_id,
            'gross_cents' => $grossSalary,
            'rate_percent' => $costSharing?->deduction_rate_percent,
            'cost_sharing_cents' => $costSharingDeduction,
            'outstanding_before_cents' => $costSharingOutstandingBefore,
            // What approving this run leaves owing; the row itself moves only then.
            'outstanding_after_cents' => $costSharingOutstandingBefore !== null
                ? $costSharingOutstandingBefore - $costSharingDeduction
                : null,
        ];

        $totalDeductions = $incomeTax + $pension['employee_cents'] + $loanDeduction + $costSharingDeduction;
        $netSalary = $grossSalary - $totalDeductions;

        $log['steps'][] = [
            'step' => 'net_calculation',
            'gross_cents' => $grossSalary,
            'total_deductions_cents' => $totalDeductions,
            'net_cents' => $netSalary,
        ];

        $log['outputs'] = [
            'gross_cents' => $grossSalary,
            'income_tax_cents' => $incomeTax,
            'employee_pension_cents' => $pension['employee_cents'],
            'employer_pension_cents' => $pension['employer_cents'],
            'loan_deduction_cents' => $loanDeduction,
            'cost_sharing_cents' => $costSharingDeduction,
            'net_cents' => $netSalary,
        ];

        $deductions = [];
        if ($loanDeduction > 0) {
            $deductions[] = ['type' => 'loan', 'amount_cents' => $loanDeduction];
        }
        if ($costSharingDeduction > 0) {
            $deductions[] = ['type' => 'cost_sharing', 'amount_cents' => $costSharingDeduction];
        }

        return [
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'basic_salary_cents' => $proratedBasic,
            'allowances' => $allowances,
            'deductions' => $deductions,
            'gross_cents' => $grossSalary,
            'income_tax_cents' => $incomeTax,
            'employee_pension_cents' => $pension['employee_cents'],
            'employer_pension_cents' => $pension['employer_cents'],
            // The generic "everything that isn't tax or pension" bucket: the
            // accounting export sums it into a single journal line and the
            // payslip prints it, so cost sharing has to land here or net stops
            // reconciling against gross − tax − pension − other.
            'other_deductions_cents' => $loanDeduction + $costSharingDeduction,
            'net_cents' => $netSalary,
            'calculation_log' => $log,
        ];
    }

    /**
     * Working days of approved, unpaid-type leave that fall within the pay
     * period. When a request lies entirely inside the period its stored `days`
     * (half-day aware) is used; a request spanning the period boundary is
     * clipped and recounted against holidays/weekends.
     */
    private function gatherUnpaidLeaveDays(Employee $employee, Carbon $periodStart, Carbon $periodEnd): float
    {
        $requests = LeaveRequest::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('status', LeaveStatus::APPROVED)
            ->whereDate('start_date', '<=', $periodEnd->format('Y-m-d'))
            ->whereDate('end_date', '>=', $periodStart->format('Y-m-d'))
            ->with(['leaveType' => fn ($q) => $q->withoutGlobalScope('tenant')])
            ->get();

        $days = 0.0;

        foreach ($requests as $request) {
            // Only unpaid leave reduces pay; skip paid leave and orphaned rows.
            // Art. 85 sick leave has its own step, whatever its paid flag.
            $leaveType = $request->leaveType;
            if (! $leaveType instanceof LeaveType || $leaveType->is_paid || $leaveType->follows_art85) {
                continue;
            }

            $start = Carbon::parse($request->start_date);
            $end = Carbon::parse($request->end_date);
            $clipStart = $start->lt($periodStart) ? $periodStart->copy() : $start;
            $clipEnd = $end->gt($periodEnd) ? $periodEnd->copy() : $end;

            if ($clipStart->equalTo($start) && $clipEnd->equalTo($end)) {
                $days += (float) $request->days;
            } else {
                $days += $this->leaveDayCalculator->calculateDays(
                    $clipStart, $clipEnd, $employee->tenant_id, $employee->branch_id, $employee,
                );
            }
        }

        return $days;
    }

    /**
     * The period's workdays on which this person was expected at work and
     * has no attendance: not approved leave, not a holiday, not a rest day,
     * inside their employment (ExpectedDays). Flagged on the run before
     * approval and never deducted. A day the system cannot explain may be
     * leave nobody recorded, a punch a device missed, or work away from site,
     * and docking pay for it is a decision for a person, not a count.
     *
     * Only days already over count. An organisation that recorded no
     * attendance at all in the period is not using attendance, and every
     * workday would read as absent, so nothing is flagged for it.
     *
     * @return list<string> Y-m-d
     */
    public function unexplainedAbsences(Employee $employee, Carbon $periodStart, Carbon $periodEnd): array
    {
        $yesterday = TenantTime::today(app(CurrentTenant::class)->tenantFor($employee->tenant_id))->subDay()->format('Y-m-d');
        $first = $periodStart->format('Y-m-d');
        $last = min($periodEnd->format('Y-m-d'), $yesterday);

        if ($last < $first || ! $this->attendanceInUse($employee->tenant_id, $first, $last)) {
            return [];
        }

        $attended = AttendanceRecord::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $first)
            ->whereDate('date', '<=', $last)
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->where(fn ($q) => $q->whereNotNull('check_in')->orWhereNotNull('check_out'))
            ->pluck('date')
            ->mapWithKeys(fn ($date): array => [Carbon::parse($date)->format('Y-m-d') => true])
            ->all();

        $absent = [];
        foreach ($this->expectedDays->forEmployee($employee, Carbon::parse($first), Carbon::parse($last)) as $day => $kind) {
            if ($kind === ExpectedDay::WORKDAY && ! isset($attended[$day])) {
                $absent[] = $day;
            }
        }

        return $absent;
    }

    /**
     * unexplainedAbsences() for many employees of one organisation at once,
     * by the same rule and the same two guards (only days already over; an
     * organisation recording no attendance flags nothing). Each day is asked
     * of ExpectedDays::onDate for everyone together, so the cost grows with
     * the period's days rather than its people: attendance reconciliation
     * reads a whole organisation inside one request. A run still asks per
     * employee, as it always has.
     *
     * @param  Collection<int, Employee>  $employees  all of one tenant
     * @return array<int, list<string>> employee id => Y-m-d
     */
    public function unexplainedAbsencesFor(Collection $employees, Carbon $periodStart, Carbon $periodEnd): array
    {
        $first = $employees->first();

        if ($first === null) {
            return [];
        }

        $tenantId = $first->tenant_id;
        $yesterday = TenantTime::today(app(CurrentTenant::class)->tenantFor($tenantId))->subDay()->format('Y-m-d');
        $from = $periodStart->format('Y-m-d');
        $last = min($periodEnd->format('Y-m-d'), $yesterday);

        if ($last < $from || ! $this->attendanceInUse($tenantId, $from, $last)) {
            return [];
        }

        $attended = [];
        AttendanceRecord::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $employees->pluck('id')->all())
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $last)
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->where(fn ($q) => $q->whereNotNull('check_in')->orWhereNotNull('check_out'))
            ->get(['employee_id', 'date'])
            ->each(function (AttendanceRecord $record) use (&$attended): void {
                $attended[$record->employee_id][Carbon::parse($record->date)->format('Y-m-d')] = true;
            });

        $absent = [];
        for ($day = Carbon::parse($from); $day->format('Y-m-d') <= $last; $day->addDay()) {
            $key = $day->format('Y-m-d');
            foreach ($this->expectedDays->onDate($employees, $day) as $employeeId => $kind) {
                if ($kind === ExpectedDay::WORKDAY && ! isset($attended[$employeeId][$key])) {
                    $absent[$employeeId][] = $key;
                }
            }
        }

        return $absent;
    }

    private function attendanceInUse(int $tenantId, string $first, string $last): bool
    {
        return $this->attendanceInUse["{$tenantId}|{$first}|{$last}"] ??= AttendanceRecord::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('date', '>=', $first)
            ->whereDate('date', '<=', $last)
            ->exists();
    }

    /**
     * Sum the period's overtime minutes for an employee, split by rate type
     * (normal / night / rest_day / holiday / holiday_night). Holidays are
     * pre-fetched once for the period so classification is a memory lookup per
     * record. A weekly rest day is a day the record's shift does not work; with
     * no shift it is Sunday, Art. 69's default.
     *
     * Also each day's limited overtime (the `overtime.limited_types` parameter), over whole
     * Monday-to-Sunday weeks: a week that starts in the previous period or
     * ends in the next is read whole, or twelve hours split across two runs
     * would never be flagged. Only the period's own days are paid.
     *
     * @return array{buckets: array{normal: int, night: int, rest_day: int, holiday: int, holiday_night: int}, by_day: array<string, int>}
     */
    public function gatherOvertimeByType(Employee $employee, Carbon $periodStart, Carbon $periodEnd, StatutoryRuleSet $rules): array
    {
        $limitedTypes = $rules->list('overtime.limited_types');
        $nightWindow = $rules->list('overtime.night_window');
        $buckets = ['normal' => 0, 'night' => 0, 'rest_day' => 0, 'holiday' => 0, 'holiday_night' => 0];
        $byDay = [];

        $windowStart = $periodStart->copy()->startOfWeek(Carbon::MONDAY);
        $windowEnd = $periodEnd->copy()->endOfWeek(Carbon::SUNDAY);
        $firstPaid = $periodStart->format('Y-m-d');
        $lastPaid = $periodEnd->format('Y-m-d');

        $holidayDates = $this->holidayService->getHolidayDates(
            $employee->tenant_id,
            $windowStart,
            $windowEnd,
            $employee->branch_id,
        );

        // A voided record is the loser of a merged duplicate: ConflictResolver
        // sets its status and leaves its punches in place, so without the
        // status predicate one shift's overtime was paid twice.
        $records = AttendanceRecord::query()
            ->withoutGlobalScope('tenant')
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $windowStart->format('Y-m-d'))
            ->whereDate('date', '<=', $windowEnd->format('Y-m-d'))
            ->whereNotNull('check_in')
            ->whereNotNull('check_out')
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->with('shift')
            ->get();

        // A rotation decides its own rest days: it can put a Monday-Friday
        // shift on a Saturday, and its rest day matches no shift at all.
        // Asked once for the period, not per record.
        $rotationRestDays = $this->shiftMatcher->rotationRestDays($employee, $windowStart, $windowEnd);

        // A record with no shift rests on the organisation's own week, not on
        // Sunday: a Saturday at a Monday-Friday organisation was paid as a
        // normal day, and a Sunday at a six-day one as a rest day.
        $workingWeek = WorkingWeek::of(app(CurrentTenant::class)->tenantFor($employee->tenant_id));

        foreach ($records as $record) {
            $date = Carbon::parse($record->date);
            $day = $date->format('Y-m-d');
            $paid = $day >= $firstPaid && $day <= $lastPaid;
            $isHoliday = isset($holidayDates[$date->format('Y-m-d')]);
            $isRestDay = $rotationRestDays[$date->format('Y-m-d')]
                ?? ($record->shift !== null
                    ? ! $record->shift->isWorkingDay($date)
                    : ! in_array($date->dayOfWeekIso, $workingWeek, true));

            foreach ($this->overtimeClassifier->classify($record, $isHoliday, $isRestDay, $nightWindow) as $type => $minutes) {
                if ($paid) {
                    $buckets[$type] += $minutes;
                }
                if (in_array($type, $limitedTypes, true)) {
                    $byDay[$day] = ($byDay[$day] ?? 0) + $minutes;
                }
            }
        }

        ksort($byDay);

        return ['buckets' => $buckets, 'by_day' => $byDay];
    }

    /**
     * The days and weeks whose overtime passed a limit: the organisation's
     * own daily limit and the legal daily one on the period's days, and the
     * legal weekly one on every week that touches the period, counted whole.
     * With the organisation requiring overtime to be approved in advance,
     * also the days whose overtime went past what was approved for them (no
     * approved request counts as nothing approved).
     *
     * @param  array<string, int>  $byDay  limited overtime minutes by date, whole weeks
     * @return array{daily_limit_minutes: int, days_over_daily_limit: list<string>, legal_daily_limit_minutes: int, days_over_legal_daily_limit: list<string>, legal_weekly_limit_minutes: int, weeks_over_legal_weekly_limit: list<array{week_start: string, minutes: int}>, requires_approval: bool, days_over_approved: list<array{date: string, worked_minutes: int, approved_minutes: int}>}
     */
    public function overtimeLimits(Employee $employee, array $byDay, Carbon $periodStart, Carbon $periodEnd, StatutoryRuleSet $rules): array
    {
        $legalDaily = $rules->int('overtime.legal_daily_minutes');
        $legalWeekly = $rules->int('overtime.legal_weekly_minutes');
        $tenantId = $employee->tenant_id;
        $dailyLimit = $this->dailyOvertimeLimit($tenantId);
        $requiresApproval = $this->overtimeRequiresApproval($tenantId);
        $approved = $requiresApproval ? $this->approvedOvertimeByDay($employee, $periodStart, $periodEnd) : [];
        $overApproved = [];
        $firstPaid = $periodStart->format('Y-m-d');
        $lastPaid = $periodEnd->format('Y-m-d');

        $overOwn = [];
        $overLegal = [];
        $weeks = [];
        foreach ($byDay as $day => $minutes) {
            if ($day >= $firstPaid && $day <= $lastPaid) {
                if ($dailyLimit > 0 && $minutes > $dailyLimit) {
                    $overOwn[] = $day;
                }
                if ($minutes > $legalDaily) {
                    $overLegal[] = $day;
                }
                if ($requiresApproval && $minutes > ($approved[$day] ?? 0)) {
                    $overApproved[] = ['date' => $day, 'worked_minutes' => $minutes, 'approved_minutes' => $approved[$day] ?? 0];
                }
            }

            $weekStart = Carbon::parse($day)->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
            $weeks[$weekStart] = ($weeks[$weekStart] ?? 0) + $minutes;
        }

        $weeksOver = [];
        foreach ($weeks as $weekStart => $minutes) {
            if ($minutes > $legalWeekly) {
                $weeksOver[] = ['week_start' => $weekStart, 'minutes' => $minutes];
            }
        }

        return [
            'daily_limit_minutes' => $dailyLimit,
            'days_over_daily_limit' => $overOwn,
            'legal_daily_limit_minutes' => $legalDaily,
            'days_over_legal_daily_limit' => $overLegal,
            'legal_weekly_limit_minutes' => $legalWeekly,
            'weeks_over_legal_weekly_limit' => $weeksOver,
            'requires_approval' => $requiresApproval,
            'days_over_approved' => $overApproved,
        ];
    }

    /**
     * Minutes of overtime approved in advance for each of the period's days.
     *
     * @return array<string, int>
     */
    private function approvedOvertimeByDay(Employee $employee, Carbon $periodStart, Carbon $periodEnd): array
    {
        $byDay = [];

        $requests = OvertimeRequest::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('status', OvertimeRequestStatus::APPROVED)
            ->whereDate('date', '>=', $periodStart->format('Y-m-d'))
            ->whereDate('date', '<=', $periodEnd->format('Y-m-d'))
            ->get(['date', 'planned_minutes']);

        foreach ($requests as $request) {
            $day = $request->date->format('Y-m-d');
            $byDay[$day] = ($byDay[$day] ?? 0) + $request->planned_minutes;
        }

        return $byDay;
    }

    /**
     * The late or early minutes approved for deduction on the period's
     * records (an approved lateness correction writes them on the record),
     * and the corrections that decided them.
     *
     * @return array{minutes: int, corrections: list<string>}
     */
    private function decidedLateness(Employee $employee, Carbon $periodStart, Carbon $periodEnd): array
    {
        $records = AttendanceRecord::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $periodStart->format('Y-m-d'))
            ->whereDate('date', '<=', $periodEnd->format('Y-m-d'))
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->whereNotNull('metadata->pay_deduct_minutes')
            ->get(['metadata']);

        $minutes = 0;
        $corrections = [];
        foreach ($records as $record) {
            $minutes += (int) ($record->metadata['pay_deduct_minutes'] ?? 0);
            if (is_string($record->metadata['lateness_correction_id'] ?? null)) {
                $corrections[] = $record->metadata['lateness_correction_id'];
            }
        }

        return ['minutes' => $minutes, 'corrections' => $corrections];
    }

    /** Whether the organisation switched lateness deductions on. Read once per run. */
    private function latenessDeductionEnabled(int $tenantId): bool
    {
        return $this->latenessDeductions[$tenantId] ??= (bool) AttendanceSetting::query()
            ->where('tenant_id', $tenantId)
            ->value('lateness_deduction_enabled');
    }

    /** Whether the organisation asks for overtime to be approved before it is worked. Read once per run. */
    private function overtimeRequiresApproval(int $tenantId): bool
    {
        return $this->overtimeApprovalRequired[$tenantId] ??= (bool) AttendanceSetting::query()
            ->where('tenant_id', $tenantId)
            ->value('overtime_requires_approval');
    }

    /**
     * The organisation's own daily overtime limit (Attendance rules), or the
     * default when it has never saved its rules. Read once per run.
     */
    private function dailyOvertimeLimit(int $tenantId): int
    {
        return $this->dailyOvertimeLimits[$tenantId] ??= (int) (
            AttendanceSetting::query()->where('tenant_id', $tenantId)->value('ot_daily_cap_minutes')
            ?? AttendanceSetting::defaults()['ot_daily_cap_minutes']
        );
    }
}
