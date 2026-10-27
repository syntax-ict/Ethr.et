<?php

declare(strict_types=1);

namespace App\Exceptions;

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
    ) {
        parent::__construct($detail);
    }

    public static function notCompleted(): self
    {
        return new self('https://ethr.et/errors/invalid-state', 'Invalid State', __('payroll.not_completed'));
    }

    public static function cannotVoid(): self
    {
        return new self('https://ethr.et/errors/invalid-state', 'Invalid State', __('payroll.cannot_void'));
    }

    public static function notApprovedForPayment(): self
    {
        return new self('https://ethr.et/errors/invalid-state', 'Invalid State', __('payroll.bank_export_not_approved'));
    }

    public static function selfApproval(): self
    {
        return new self('https://ethr.et/errors/payroll-self-approval', 'Second approver required', __('payroll.self_approval'));
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
