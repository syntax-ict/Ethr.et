<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How long a piece of payroll work may run inside one web request on the
 * shared host, which stops a request after `max_execution_time` seconds of
 * CPU (30 on the account; docs/DEPLOYMENT.md). Shared by the queued run and
 * the dry-run preview, so both stop by the same rule.
 */
final class RequestBudget
{
    /**
     * The `microtime(true)` to stop at, or null to run to the end.
     *
     * Measured from the start of the request: work done earlier in it has
     * used some of the time already, and the host's limit is on the request.
     * `payroll.request_budget_seconds` sets the budget; unset, it is half of
     * `max_execution_time`, and a command line (limit 0) has no deadline.
     */
    public static function deadline(): ?float
    {
        $budget = config('payroll.request_budget_seconds');

        if ($budget === null) {
            $limit = (int) ini_get('max_execution_time');

            if ($limit <= 0) {
                return null;
            }

            $budget = $limit * 0.5;
        }

        return (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)) + (float) $budget;
    }
}
