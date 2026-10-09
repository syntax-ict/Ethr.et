<?php

declare(strict_types=1);

return [
    // The leave, payslip, missing-punch and trial e-mails take their subject and
    // body from App\Support\NotificationTemplates, which tenants can edit.

    // Payroll notifications
    'view_payslip' => 'View Payslip',

    // Attendance corrections

    // Profile update requests
    'profile_update_requested_body' => ':name has requested a change to protected profile fields.',
    'profile_update_approved_body' => 'Your requested change to :field has been approved.',
    'profile_update_rejected_body' => 'Your requested change to :field has been rejected.',

    // Approval reminders
    'approval_reminder_subject' => 'Approvals Waiting on You',

    // Approval routing
    'review_request' => 'Review Request',
    'device_offline_subject' => 'A biometric device has gone offline',
    'device_sync_failed_subject' => 'Biometric device sync failed: :name',
    'device_sync_failed_body' => 'Sync for device ":name" failed after every retry and no attendance events are being collected from it. The device reported: :reason',
    'device_action' => 'View device status',

    // Billing
];
