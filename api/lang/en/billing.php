<?php

declare(strict_types=1);

return [
    'plan_changed' => 'Subscription plan changed to :plan.',
    'invoice_paid' => 'Invoice marked as paid.',
    'employee_limit_reached' => 'You have reached the employee limit for your plan.',
    'branch_limit_reached' => 'You have reached the branch limit for your plan.',
    'device_limit_reached' => 'You have reached the device limit for your plan.',

    'feature_not_in_plan' => 'Your plan does not include :feature. Upgrade to use it.',
    'feature_unavailable' => 'Your organisation cannot use :feature right now.',
    'feature_paused' => 'ETHR has switched off :feature for now. Try again later.',

    'feature_not_overridable' => 'This feature is decided for every organisation at once, and cannot be granted or revoked for one.',
    'feature_no_rollout' => 'This feature has no organisations to roll out to. Choose Enabled or Disabled.',
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
        'custom_domain' => 'a custom domain',
        'webhooks_outbound' => 'webhook delivery',
        'web_push' => 'phone notifications',
        'email_sign_in_codes' => 'sign-in codes by email',
        'attendance_selfies' => 'attendance selfies',
        'kiosk_check_in' => 'kiosk check-in',
        'self_registration' => 'new organisation sign-up',
        'scim' => 'SCIM provisioning',
        'device_webhooks_inbound' => 'attendance terminals',
        'public_news' => 'the public news page',
        'public_vacancies' => 'the public vacancies',
    ],
];
