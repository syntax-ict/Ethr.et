<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Exceptions\PayrollRunRefused;
use App\Models\EmployeeCostSharing;
use App\Models\EmployeeLoan;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use LogicException;

/**
 * Moves loan and cost-sharing balances for a payroll run: forward when the run
 * is approved, back when an approved run is voided.
 *
 * Calculating a run used to draw both balances down as it went, so a void
 * restored nothing, a reprocess deducted a second time, and a job that died
 * part-way left some balances reduced under a run marked `failed`. The engine
 * now only records each deduction in the entry's calculation_log; this class
 * reads that record back and is the one place a balance changes because of
 * payroll. What approval applies is exactly what the payslip deducted, so what
 * a void reverses is exactly what approval applied, and neither needs a ledger
 * of its own.
 *
 * Both callers run inside PayrollEngine's transaction with the run locked, and
 * every balance row is locked here before it is read.
 */
final class PayrollBalanceService
{
    /**
     * The calculation_log key that marks an entry whose balances move on
     * approval. Entries calculated before 2026-10-17 do not carry it: their
     * balances moved while they were calculated, so approving or voiding them
     * moves nothing here, exactly as before.
     */
    public const LOG_KEY = 'balances';

    public const MOVE_ON_APPROVAL = 'move_on_approval';

    public function __construct(
        private readonly LoanService $loans,
        private readonly CostSharingService $costSharing,
    ) {}

    /**
     * Draw every balance down by what the run's payslips deducted.
     *
     * Refused, with nothing written, when any balance can no longer bear its
     * deduction: a loan repaid or cancelled off payroll, or an obligation
     * suspended, since the run was calculated. Applying it anyway would leave
     * a payslip that withheld more than was owed, which is the defect this
     * class exists to end. Voiding and reprocessing recalculates from the
     * balances as they now stand.
     *
     * @return list<array<string, int|string|null>> the movements applied, for the audit log
     */
    public function apply(PayrollRun $run): array
    {
        $planned = [];
        $changed = [];

        foreach ($this->planned($run) as [$entry, $type, $id, $cents]) {
            $subject = $this->lock($entry, $type, $id);

            $bears = match (true) {
                $subject instanceof EmployeeLoan => $subject->status === 'active' && $subject->remaining_cents >= $cents,
                $subject instanceof EmployeeCostSharing => $subject->status->isDeductible() && $subject->outstanding_cents >= $cents,
                default => false,
            };

            if (! $bears) {
                $changed[] = (string) $entry->employee?->public_id;

                continue;
            }

            $planned[] = [$entry, $type, $subject, $cents];
        }

        if ($changed !== []) {
            throw PayrollRunRefused::balancesChanged(array_values(array_unique($changed)));
        }

        $movements = [];
        foreach ($planned as [$entry, $type, $subject, $cents]) {
            $before = $this->balance($subject);
            $subject instanceof EmployeeLoan
                ? $this->loans->applyDeduction($subject, $cents)
                : $this->costSharing->applyDeduction($subject, $cents);
            $movements[] = $this->movement($entry, $type, $subject, $cents, $before);
        }

        return $movements;
    }

    /**
     * Give back exactly what approving the run took.
     *
     * Loans and obligations are never deleted (LoanController cancels,
     * CostSharingController has no destroy), so a row that cannot be found is
     * a fault, and the void fails rather than completing with a balance
     * silently left short.
     *
     * @return list<array<string, int|string|null>> the movements reversed, for the audit log
     */
    public function reverse(PayrollRun $run): array
    {
        $movements = [];

        foreach ($this->planned($run) as [$entry, $type, $id, $cents]) {
            $subject = $this->lock($entry, $type, $id)
                ?? throw new LogicException("Payroll entry {$entry->public_id} deducted from {$type} {$id}, which no longer exists.");

            $before = $this->balance($subject);
            $subject instanceof EmployeeLoan
                ? $this->loans->restoreDeduction($subject, $cents)
                : $this->costSharing->restoreDeduction($subject, $cents);
            $movements[] = $this->movement($entry, $type, $subject, $cents, $before);
        }

        return $movements;
    }

    /**
     * Every deduction the run's entries recorded against a balance.
     *
     * @return list<array{0: PayrollEntry, 1: 'loan'|'cost_sharing', 2: string, 3: int}>
     */
    private function planned(PayrollRun $run): array
    {
        $planned = [];

        $entries = PayrollEntry::query()
            ->where('payroll_run_id', $run->id)
            ->with('employee')
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $log = $entry->calculation_log ?? [];
            if (($log[self::LOG_KEY] ?? null) !== self::MOVE_ON_APPROVAL) {
                continue;
            }

            $steps = is_array($log['steps'] ?? null) ? $log['steps'] : [];

            foreach ($this->step($steps, 'loan_deductions')['loans'] ?? [] as $line) {
                if ((int) $line['deduction_cents'] > 0) {
                    $planned[] = [$entry, 'loan', (string) $line['loan_id'], (int) $line['deduction_cents']];
                }
            }

            $costSharing = $this->step($steps, 'cost_sharing');
            if (($costSharing['obligation_id'] ?? null) !== null && (int) $costSharing['cost_sharing_cents'] > 0) {
                $planned[] = [$entry, 'cost_sharing', (string) $costSharing['obligation_id'], (int) $costSharing['cost_sharing_cents']];
            }
        }

        return $planned;
    }

    /**
     * One named step of a calculation_log, or an empty array.
     *
     * @param  array<mixed>  $steps
     * @return array<string, mixed>
     */
    private function step(array $steps, string $name): array
    {
        foreach ($steps as $step) {
            if (is_array($step) && ($step['step'] ?? null) === $name) {
                return $step;
            }
        }

        return [];
    }

    /**
     * The balance row, locked, and only if it belongs to the entry's employee.
     * Both models carry the tenant scope, and the employee predicate keeps an
     * id in a log from reaching anyone else's balance.
     */
    private function lock(PayrollEntry $entry, string $type, string $id): EmployeeLoan|EmployeeCostSharing|null
    {
        $query = $type === 'loan' ? EmployeeLoan::query() : EmployeeCostSharing::query();

        return $query
            ->where('public_id', $id)
            ->where('employee_id', $entry->employee_id)
            ->lockForUpdate()
            ->first();
    }

    private function balance(EmployeeLoan|EmployeeCostSharing $subject): int
    {
        return $subject instanceof EmployeeLoan ? $subject->remaining_cents : $subject->outstanding_cents;
    }

    /** @return array<string, int|string|null> */
    private function movement(PayrollEntry $entry, string $type, EmployeeLoan|EmployeeCostSharing $subject, int $cents, int $before): array
    {
        return [
            'type' => $type,
            'id' => $subject->public_id,
            'employee_id' => $entry->employee?->public_id,
            'payroll_entry_id' => $entry->public_id,
            'deduction_cents' => $cents,
            'balance_before_cents' => $before,
            'balance_after_cents' => $this->balance($subject),
        ];
    }
}
