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
    'approval_escalated_subject' => 'An approval is overdue',
    'approval_escalated_hint' => 'Please follow up with them, or ask HR to decide it if they are away.',
    'approval_escalated_action' => 'Open approvals',

    // Approval routing
    'review_request' => 'Review Request',
    'device_offline_subject' => 'A biometric device has gone offline',
    'device_sync_failed_subject' => 'Biometric device sync failed: :name',
    'device_sync_failed_body' => 'Sync for device ":name" failed after every retry and no attendance events are being collected from it. The device reported: :reason',
    'device_action' => 'View device status',

    // Billing
    // In-app notifications, rendered in the recipient's language when sent
    // (User::preferredLocale). Each carries a `url` the notification opens.
    'inapp' => [
        'attendance_high_risk' => ':name punched with a risk score of :score. Review it before payroll.',
        'approval_reminder' => 'You have :count approvals waiting. The oldest has waited :hours hours.',
        'approval_escalated' => ':name\'s :type has waited :hours hours for :approver to decide it.',
        'attendance_anomaly' => 'Attendance anomaly for :name: :type',
        'correction_approved' => 'Your attendance correction for :date was approved.',
        'correction_rejected' => 'Your attendance correction for :date was rejected.',
        'correction_requested' => ':name asked for an attendance correction.',
        'overtime_requested' => ':name asked to work :hours hours of overtime on :date.',
        'overtime_approved' => 'Your overtime for :date was approved.',
        'overtime_rejected' => 'Your overtime for :date was not approved.',
        'device_offline' => 'Device ":name" has gone offline.',
        'leave_approved' => 'Your leave request was approved.',
        'leave_rejected' => 'Your leave request was rejected.',
        'leave_requested' => ':name asked for leave.',
        'missing_check_in' => ':name did not check in on :date.',
        'missing_check_out' => ':name did not check out on :date.',
        'payroll_processed' => 'Payroll for :period has been calculated.',
        'payslip_available' => 'Your payslip for :period is ready.',
        'trial_expiring' => 'Your trial ends in :days day. Choose a plan to keep using ETHR.|Your trial ends in :days days. Choose a plan to keep using ETHR.',
    ],
    'push_unavailable' => 'Phone notifications are not set up on this server yet.',
];
