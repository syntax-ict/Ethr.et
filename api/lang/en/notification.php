<?php

declare(strict_types=1);

return [
    // Leave notifications
    'leave_requested_subject' => 'New Leave Request — :name',
    'leave_requested_body' => ':name has submitted a leave request that requires your approval.',
    'leave_approved_subject' => 'Leave Request Approved',
    'leave_approved_body' => 'Your leave request has been approved.',
    'leave_rejected_subject' => 'Leave Request Rejected',
    'leave_rejected_body' => 'Your leave request has been rejected.',

    // Payroll notifications
    'payslip_available_subject' => 'Your Payslip Is Ready — :period',
    'payslip_available_body' => 'Your payslip for :period is now available. Log in to view and download it.',
    'view_payslip' => 'View Payslip',

    // Attendance — missing punch
    'missing_check_out_subject' => 'Missing Check-Out — :date',
    'missing_check_out_body' => ':name checked in but did not check out on :date. Please correct the attendance record.',
    'missing_check_in_subject' => 'Missing Check-In — :date',
    'missing_check_in_body' => ':name has a check-out record but no check-in on :date. Please review the attendance record.',

    // Attendance corrections
    'correction_submitted_subject' => 'Attendance Correction Request — :name',
    'correction_submitted_body' => ':name has submitted an attendance correction request for :date.',
    'correction_approved_subject' => 'Attendance Correction Approved',
    'correction_approved_body' => 'Your attendance correction request for :date has been approved.',
    'correction_rejected_subject' => 'Attendance Correction Rejected',
    'correction_rejected_body' => 'Your attendance correction request for :date has been rejected.',

    // Approval routing
    'review_request' => 'Review Request',
];
