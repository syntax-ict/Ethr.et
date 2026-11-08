<?php

declare(strict_types=1);

return [
    'not_pending' => 'This leave request is no longer pending.',
    'sick_beyond_six_months' => 'Sick leave is at most six months within twelve, counted from the first day of sickness (Labour Proclamation Art. 85). These days go past that.',
    'not_approved' => 'Only approved leave can be cancelled or ended early.',
    'cannot_recall_own' => 'You cannot change your own leave. Another administrator has to.',
    'recall_end_date' => 'The new end date must be on or after the first day and before the current last day.',
    'recall_no_days' => 'Ending the leave on that date leaves no working days. Cancel the leave instead.',
    'recall_period_closed' => 'Some of the days given back fall in a payroll period that is already approved. Void and reprocess that run first, so pay follows the change.',
    'payroll_period_closed' => 'This leave falls in a payroll period that is already approved. Void and reprocess that run first, so the deduction is paid from it.',
    'not_own_request' => 'You can only modify your own leave requests.',
    'overlap' => 'You already have an approved leave request that overlaps with these dates.',
    'no_working_days' => 'The selected dates do not include any working days.',
    'gender_restricted' => 'This leave type is not available for your gender.',
    'insufficient_balance' => 'You do not have enough leave balance for this request.',
    'approved' => 'Leave request approved.',
    'rejected' => 'Leave request rejected.',
    'cancelled' => 'Leave request cancelled.',
    'cannot_approve_own' => 'You cannot approve your own leave request.',
    'cannot_approve_filed' => 'You filed this request, so someone else must approve it.',
    'min_notice' => 'This leave type needs :days days\' notice. Choose a start date at least :days days from today.',
    'max_consecutive' => 'This leave type allows at most :days days in one request.',
];
