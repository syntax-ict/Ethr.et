<?php

declare(strict_types=1);

return [
    'plan_changed' => 'Subscription plan changed to :plan.',
    'plan_cancelled' => 'Subscription cancelled. Access continues until :date.',
    'invoice_generated' => 'Invoice generated.',
    'invoice_paid' => 'Invoice marked as paid.',
    'invoice_overdue' => 'Invoice is overdue.',
    'trial_days_remaining' => ':count days remaining in your trial.',
    'upgrade_required' => 'Please upgrade your plan to access this feature.',
    'employee_limit_reached' => 'You have reached the employee limit for your plan.',
    'branch_limit_reached' => 'You have reached the branch limit for your plan.',
    'device_limit_reached' => 'You have reached the device limit for your plan.',

    'feature_not_in_plan' => 'Your plan does not include :feature. Upgrade to use it.',

    'features' => [
        'attendance' => 'attendance tracking',
        'leave' => 'leave management',
        'employee_management' => 'employee management',
        'payroll' => 'payroll',
        'reports' => 'reports',
        'notifications' => 'notifications',
        'api_access' => 'API access',
        'webhooks' => 'webhooks',
        'custom_reports' => 'custom reports',
        'audit_log' => 'the audit log',
    ],
    'payment_received' => 'Payment received. Thank you.',
    'payment_failed' => 'Payment processing failed. Please try again.',
];
