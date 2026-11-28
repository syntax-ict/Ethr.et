<?php

declare(strict_types=1);

/**
 * What tests/Feature/Idempotency and tests/Concurrency/IdempotencyRaceTest.php
 * found about what the product does today when the same thing is sent twice or
 * two things race (accuracy session, section 8). Data, not a test: Pest loads
 * only *Test.php files. Nothing here is fixed in the accuracy session; each
 * item is its own pull request.
 *
 * `severity`: money-or-balance (a figure is wrong), record (a row is doubled),
 * client (a client is misled, the data is right), noise (a repeated message or
 * log entry).
 *
 * @return list<array{id: string, severity: string, where: string, what_happens: string, why_it_matters: string, read_back_in: string}>
 */
return [
    [
        'id' => 'IDEM-01',
        'severity' => 'money-or-balance',
        'where' => 'ProcessPayrollJob::handle / PayrollEngine::runEntries (two handles of one run)',
        'what_happens' => 'Two handles released together on one processing run, 40 employees: 80 payroll_entries (40 distinct employees, each twice), run status completed, employee_count 80, gross_total_cents 64,000,000 where one set is 32,000,000. The status check at ProcessPayrollJob.php line 120 passes for both and the done-list in runEntries is read before either has written.',
        'why_it_matters' => 'The run totals, payslips and the loan and cost-sharing deductions drawn at approval all read the entries, so a doubled run pays twice. The job has one try, which makes two handles unlikely and not impossible: a continuation job dispatched at the deadline can overlap the original, and the cron queue endpoint can be hit twice. No unique index on (payroll_run_id, employee_id) stops the second row. Whether approving such a run draws the balances twice was not tested.',
        'read_back_in' => 'tests/Concurrency/IdempotencyRaceTest.php: "it writes one set of entries when two ProcessPayrollJob handles of the same run reach the database together" (incomplete, with the figures)',
    ],
    [
        'id' => 'IDEM-02',
        'severity' => 'money-or-balance',
        'where' => 'LeaveBalanceService::accrueMonthly (two runs for one tenant)',
        'what_happens' => 'Two accrual runs released together over 30 people on a 24-day monthly type: entitled_days sums to 1232 where one run credits 660; 26 of 30 balances credited twice (56 x 22 days against 30 x 22). Each run reads entitled_days, works out the gap to the target and adds it with increment().',
        'why_it_matters' => 'A person is credited twice the leave they have earned, and nothing corrects it later because the next run sees the target already exceeded. Sequential re-runs are safe and tested (LeaveBalanceJobsTest); the job has three tries and the scheduler can start a second while the first runs.',
        'read_back_in' => 'tests/Concurrency/IdempotencyRaceTest.php: "it credits the month once when two accrual runs for the same tenant reach the database together" (incomplete, with the figures)',
    ],
    [
        'id' => 'IDEM-03',
        'severity' => 'client',
        'where' => 'PUT payroll/runs/{run}/approve, PUT .../paid, POST .../void',
        'what_happens' => 'A repeat after a success is answered 422 (type invalid-state; for a void of a paid run, payroll-run-paid), whatever Idempotency-Key header it carries. Money and balances move once; the first approver, approved_at, paid_on and payment_reference stand.',
        'why_it_matters' => 'A client that lost the first response cannot tell its request worked; it has to read the run to find out. The response is not the first one replayed.',
        'read_back_in' => 'tests/Feature/Idempotency/PayrollRepeatTest.php (approve, mark paid, void) and LostResponseResendTest.php',
    ],
    [
        'id' => 'IDEM-04',
        'severity' => 'client',
        'where' => 'POST leave/request',
        'what_happens' => 'The identical request sent twice: one row, pending_days raised once, and the second answered 422 overlap, not the first request. An Idempotency-Key header changes nothing.',
        'why_it_matters' => 'An offline queue or a retry after a lost response sees an error for a request that exists. It recovers by listing its own requests.',
        'read_back_in' => 'tests/Feature/Idempotency/LeaveRepeatTest.php and LostResponseResendTest.php',
    ],
    [
        'id' => 'IDEM-05',
        'severity' => 'client',
        'where' => 'PUT leave/{request}/approve, reject, cancel, recall',
        'what_happens' => 'A repeat after a success is answered 422 (invalid-state, or a validation error on end_date for the same early-return recall). Balances move once in every case, including a recall whose give-back would be visible (three days of other used leave stay).',
        'why_it_matters' => 'Same as IDEM-03 for leave: safe data, misleading answer.',
        'read_back_in' => 'tests/Feature/Idempotency/LeaveRepeatTest.php',
    ],
    [
        'id' => 'IDEM-06',
        'severity' => 'client',
        'where' => 'LeaveRequestController::approve and reject, racing',
        'what_happens' => 'Two decisions released together: the balance moves once and one audit row is written, but the loser is not always refused. Depending on timing it is answered 422, 403, or 200 with the winner\'s outcome (an approve that lost to an approve was seen answered 200 with the request as approved). The no-op under the lock returns without a refusal.',
        'why_it_matters' => 'The loser is told 200 although it did nothing, so a second approver is not told someone else decided first (a reject losing to an approve would read the same way, by the code, but was not seen). The data is right and the answer is not. Seen as 200 and 200, and with a 403 in one earlier run.',
        'read_back_in' => 'tests/Concurrency/IdempotencyRaceTest.php: "it takes the days once when two decisions on the same leave request reach the server together" (accepts 200, 403, 422 and says why)',
    ],
    [
        'id' => 'IDEM-07',
        'severity' => 'noise',
        'where' => 'ScanMissingPunchesJob, ScanAttendanceAnomaliesJob',
        'what_happens' => 'Each handled twice for the same day: every recipient holds two in-app notifications of the same kind for the same day (the employee and the supervisor for a missing punch; the supervisor for an anomaly). No record, pay or balance changes.',
        'why_it_matters' => 'A scheduler retry or a missed-tick catch-up repeats the message. The job keeps no record of what it sent.',
        'read_back_in' => 'tests/Feature/Idempotency/ScheduledJobRepeatTest.php',
    ],
    [
        'id' => 'IDEM-08',
        'severity' => 'noise',
        'where' => 'PullDeviceEventsJob after a push of the same events (same terminal)',
        'what_happens' => 'One live record is kept: the pulled check-in is written under its own key (device:...) and merged into the live record as a voided twin (merged_into set); the pulled check-out is answered duplicate. Each further pull appends another duplicate entry to the live record\'s punch log. Live records stay at one.',
        'why_it_matters' => 'The push and pull keys differ, so the day rules do the merging, and a voided row stays behind. It matters only for a terminal that both pushes and is pulled.',
        'read_back_in' => 'tests/Feature/Idempotency/AttendanceRepeatTest.php: push then pull',
    ],
    [
        'id' => 'IDEM-09',
        'severity' => 'noise',
        'where' => 'POST attendance/import/commit, committed again',
        'what_happens' => 'Answered 201 with created 0 and skipped 1; no record changes; a second attendance.import.committed audit row is written.',
        'why_it_matters' => 'Safe. The audit log says the import was committed twice.',
        'read_back_in' => 'tests/Feature/Idempotency/AttendanceRepeatTest.php',
    ],
];
