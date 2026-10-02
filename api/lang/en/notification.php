<?php

declare(strict_types=1);

return [
    // The leave, payslip, missing-punch and trial e-mails take their subject and
    // body from App\Support\NotificationTemplates, which tenants can edit.

    // Payroll notifications
    'view_payslip' => 'View Payslip',

    // Attendance corrections
    'correction_submitted_subject' => 'Attendance Correction Request — :name',
    'correction_submitted_body' => ':name has submitted an attendance correction request for :date.',
    'correction_approved_subject' => 'Attendance Correction Approved',
    'correction_approved_body' => 'Your attendance correction request for :date has been approved.',
    'correction_rejected_subject' => 'Attendance Correction Rejected',
    'correction_rejected_body' => 'Your attendance correction request for :date has been rejected.',

    // Profile update requests
    'profile_update_requested_subject' => 'Profile Change Awaiting Review',
    'profile_update_requested_body' => ':name has requested a change to protected profile fields.',
    'profile_update_approved_body' => 'Your requested change to :field has been approved.',
    'profile_update_rejected_body' => 'Your requested change to :field has been rejected.',

    // Approval reminders
    'approval_reminder_subject' => 'Approvals Waiting on You',
    'approval_reminder_body' => 'You have :count request(s) that have been waiting more than :hours hours.',

    // Approval routing
    'review_request' => 'Review Request',
    'device_offline_subject' => 'A biometric device has gone offline',
    'device_sync_failed_subject' => 'Biometric device sync failed: :name',
    'device_sync_failed_body' => 'Sync for device ":name" failed after every retry and no attendance events are being collected from it. The device reported: :reason',
    'device_action' => 'View device status',

    // Billing
    'trial_expiring_subject' => 'Your ETHR trial is ending soon',
];
