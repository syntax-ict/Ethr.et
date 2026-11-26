<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\PayrollRun;
use RuntimeException;

/**
 * A payroll state change the engine will not make: the run is no longer in a
 * state that allows it, or the loan and cost-sharing balances it was
 * calculated from have moved since.
 *
 * Thrown from inside PayrollEngine's locked transactions, where the state is
 * re-read under the lock, so a concurrent approval or void is refused rather
 * than applied twice. bootstrap/app.php renders it as an RFC-7807 422; the
 * detail is already translated.
 */
class PayrollRunRefused extends RuntimeException
{
    /** @param  list<string>  $employees  public ids of the employees whose balances moved */
    private function __construct(
        public readonly string $type,
        public readonly string $title,
        string $detail,
        public readonly array $employees = [],
        /** @var array<string, int> */
        public readonly array $extra = [],
    ) {
        parent::__construct($detail);
    }

    public static function notCompleted(): self
    {
        return new self('https://ethr.et/errors/invalid-state', 'Invalid State', __('payroll.not_completed'));
    }

    /** Another run that is not voided or failed already covers part of the period. */
    public static function periodOverlaps(PayrollRun $run): self
    {
        return new self('https://ethr.et/errors/period-overlaps', 'Period already processed', __('payroll.period_overlaps', [
            'label' => $run->period_label,
            'start' => $run->period_start?->toDateString(),
            'end' => $run->period_end?->toDateString(),
        ]));
    }

    public static function cannotVoid(): self
    {
        return new self('https://ethr.et/errors/invalid-state', 'Invalid State', __('payroll.cannot_void'));
    }

    public static function notApprovedForPayment(): self
    {
        return new self('https://ethr.et/errors/invalid-state', 'Invalid State', __('payroll.bank_export_not_approved'));
    }

    public static function notApprovedForPaymentRecord(): self
    {
        return new self('https://ethr.et/errors/invalid-state', 'Invalid State', __('payroll.payment_not_approved'));
    }

    /** A paid run is corrected in the next run, never voided (owner decision Q6). */
    public static function paidCannotVoid(): self
    {
        return new self('https://ethr.et/errors/payroll-run-paid', 'Payroll run paid', __('payroll.paid_cannot_void'));
    }

    public static function selfApproval(): self
    {
        return new self('https://ethr.et/errors/payroll-self-approval', 'Second approver required', __('payroll.self_approval'));
    }

    /** Attendance reconciliation is required and the period still has exceptions open or pending. */
    public static function reconciliationOpen(int $count): self
    {
        return new self(
            'https://ethr.et/errors/reconciliation-open',
            'Attendance not reconciled',
            __('payroll.reconciliation_open', ['count' => $count]),
        );
    }

    /** A decision about the period's attendance was made after the run was calculated. */
    public static function reconciliationMoved(): self
    {
        return new self(
            'https://ethr.et/errors/reconciliation-moved',
            'Attendance changed since processing',
            __('payroll.reconciliation_moved'),
        );
    }

    /** A salary dated inside the period was recorded after the run was calculated. */
    public static function inputsChangedSinceProcessed(): self
    {
        return new self(
            'https://ethr.et/errors/inputs-changed-since-processed',
            'Salary changed since processing',
            __('payroll.inputs_changed_since_processed'),
        );
    }

    /** Overtime, lateness or leave moved for some people after the run was calculated, whatever the reconciliation mode. */
    public static function attendanceChangedSinceProcessed(int $people): self
    {
        return new self(
            'https://ethr.et/errors/attendance-changed-since-processed',
            'Attendance changed since processing',
            __('payroll.attendance_changed_since_processed', ['count' => $people]),
        );
    }

    /** The request ran out of time before every changed person was checked, so nothing is approved unchecked. */
    public static function attendanceCheckTimedOut(): self
    {
        return new self(
            'https://ethr.et/errors/attendance-check-timed-out',
            'Attendance check not finished',
            __('payroll.attendance_check_timed_out'),
        );
    }

    /** Pay differences are still being worked out, so approval cannot count them. */
    public static function payDifferencesPending(): self
    {
        return new self(
            'https://ethr.et/errors/pay-differences-pending',
            'Pay differences still being worked out',
            __('payroll.pay_differences_pending'),
        );
    }

    /** The organisation has open pay differences the approver has not acknowledged by count. */
    public static function payDifferencesUnacknowledged(int $count, int $failed = 0): self
    {
        return new self(
            'https://ethr.et/errors/pay-differences-unacknowledged',
            'Pay differences not acknowledged',
            __('payroll.pay_differences_unacknowledged', ['count' => $count])
                .($failed > 0 ? ' '.__('payroll.pay_differences_failed_note', ['failed' => $failed]) : ''),
            [],
            ['pay_differences' => $count, 'pay_differences_failed' => $failed],
        );
    }

    /** Voiding would reprocess a month whose difference finance has already settled by hand. */
    public static function settledPayDifferencesUnacknowledged(int $count): self
    {
        return new self(
            'https://ethr.et/errors/settled-pay-differences-unacknowledged',
            'Settled pay differences not acknowledged',
            __('payroll.settled_pay_differences_unacknowledged', ['count' => $count]),
            [],
            ['settled_pay_differences' => $count],
        );
    }

    /** No statutory row is in force for a code the run needs; the code names which. */
    public static function noRuleInForce(string $code): self
    {
        return new self('https://ethr.et/errors/payroll-no-rule-in-force', 'Statutory rule missing', __('payroll.no_rule_in_force', ['code' => $code]));
    }

    /** @param  list<string>  $employees */
    public static function balancesChanged(array $employees): self
    {
        return new self(
            'https://ethr.et/errors/payroll-balances-changed',
            'Balances changed',
            __('payroll.balances_changed'),
            $employees,
        );
    }
}
