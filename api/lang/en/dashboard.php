<?php

declare(strict_types=1);

return [
    'digest_subject' => 'Your :frequency dashboard digest',
    'digest_failed_subject' => 'Your :frequency dashboard digest FAILED',
    'digest_failed_body' => 'The digest could not be generated and no figures were sent. Its next run is still scheduled as normal. If it keeps failing, contact an administrator.',
    'digest_greeting' => 'Here is your :frequency summary as of :date.',
    'digest_headcount' => 'Headcount: :active active / :total total',
    'digest_attendance' => 'Attendance rate today: :rate%',
    'digest_payroll' => 'Payroll (last period, net): :amount ETB',
    'digest_turnover' => 'Turnover rate: :rate%',
    'digest_compliance_documents' => ':count document(s) expiring within 30 days',
    'digest_compliance_probation' => ':count probation period(s) overdue for a decision',
    'digest_compliance_leave' => ':count employee(s) with zero leave taken this year',
    'digest_view_dashboard' => 'View the full dashboard',
    'digest_alert_triggered' => 'Alert: :label is :current (threshold: :operator :threshold)',
    'alert_metric_turnover_rate' => 'Turnover rate',
    'alert_metric_attendance_rate' => "Today's attendance rate",
    'alert_metric_expiring_documents' => 'Documents expiring within 30 days',
    'alert_metric_probation_overdue' => 'Probation periods overdue for a decision',
    'alert_metric_unused_leave' => 'Employees with zero leave taken this year',
];
