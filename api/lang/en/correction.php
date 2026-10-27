<?php

declare(strict_types=1);

return [
    'not_pending' => 'This correction request is no longer pending.',
    'submitted' => 'Correction request submitted successfully.',
    'approved' => 'Attendance correction approved.',
    'rejected' => 'Attendance correction rejected.',
    'cannot_approve_own' => 'You cannot approve a correction to your own attendance.',
    'cannot_approve_filed' => 'You filed this correction, so someone else must approve it.',
    'missed_punch_has_record' => 'There is already attendance on record for that day. Correct that record instead.',
    'missed_punch_future' => 'A missed punch can only be claimed for a day that has passed.',
    'missed_punch_pending' => 'A claim for that day is already waiting for a decision.',
    'missed_punch_no_employee' => 'Choose whose attendance this is.',
    'check_out_before_check_in' => 'The check-out must come after the check-in.',
    'lateness_disabled' => 'Your organisation has not switched on lateness deductions.',
    'lateness_not_late' => 'This record is not a late arrival or an early departure.',
    'lateness_too_many_minutes' => 'At most :minutes minutes can be deducted for this record.',
    'lateness_pending' => 'A lateness decision for this record is already waiting.',
    'lateness_needs_payroll' => 'A lateness deduction changes pay, so it must be approved by someone who processes payroll.',
];
