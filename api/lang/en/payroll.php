<?php

declare(strict_types=1);

return [
    'not_completed' => 'Payroll run has not been completed yet.',
    'already_finalized' => 'This payroll run has already been finalized.',
    'no_employees' => 'No eligible employees found for this payroll run.',
    'calculation_failed' => 'Payroll calculation failed for one or more employees.',
    'completed' => 'Payroll run completed successfully.',
    'finalized' => 'Payroll run finalized and locked.',
    'cannot_void' => 'Only a completed or approved payroll run can be voided.',
    'cannot_reprocess' => 'Only a voided payroll run can be reprocessed.',
    'fiscal_year_start_month' => 'Fiscal Year Start Month',
    'pagumen_strategy' => 'Pagumen Proration Strategy',
    'pagumen_full_month' => 'Full month salary',
    'pagumen_daily_rate' => 'Daily rate (annual ÷ 365 × Pagumen days)',
    'tax_bracket_must_start_at_zero' => 'The first tax bracket must start at 0.',
    'tax_bracket_only_last_open_ended' => 'Only the final tax bracket may be open-ended.',
    'tax_bracket_max_after_min' => 'A tax bracket maximum must be greater than its minimum.',
    'tax_bracket_not_contiguous' => 'Tax brackets must be contiguous — each bracket must start one cent after the previous one ends.',
    'tax_brackets_replaced' => 'Tax brackets updated.',
    'overtime_rates_updated' => 'Overtime rates updated.',
    'loan_not_active' => 'Only an active loan can be modified.',
    'cost_sharing_already_active' => 'This employee already has an active cost-sharing obligation.',
    'cost_sharing_invalid_transition' => 'That cost-sharing status change is not allowed.',
];
