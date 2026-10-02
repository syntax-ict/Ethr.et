<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The events the application actually sends to a tenant's webhooks.
 *
 * One entry per `DispatchesWebhooks::webhook()` call site — EmployeeController,
 * LeaveRequestController, LeaveDecisionService, PayrollController and
 * ProcessPayrollJob. `WebhookEventsTest` scans `app/` for those calls and fails
 * if this list and the call sites disagree, in either direction.
 *
 * `events.*` used to accept any string, so a webhook subscribed to a misspelt
 * or invented event saved, was listed as Active, and never fired (audit N11).
 * `test` is not here: `WebhookDispatcher::sendTestEvent()` sends it to the one
 * webhook asked, whatever that webhook subscribes to.
 *
 * The settings page offers the same nine (`src/src/features/webhooks/api.ts`).
 */
final class WebhookEvents
{
    /** @var list<string> */
    public const ALL = [
        'employee.created',
        'employee.updated',
        'leave.requested',
        'leave.approved',
        'leave.rejected',
        'payroll.processed',
        'payroll.approved',
        'payroll.voided',
        'payroll.reprocessed',
    ];
}
