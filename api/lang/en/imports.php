<?php

declare(strict_types=1);

return [
    // BalanceImporter: opening leave and loan balances.
    'balances' => [
        'empty_file' => 'The file needs a header row and at least one row of data.',
        'missing_columns' => 'Missing columns: :columns',
        'employee_code_required' => 'employee_code is required.',
        'employee_not_found' => 'No employee has the code :code.',
        'leave_type_not_found' => 'No leave type has the code :code.',
        'invalid_year' => 'year must be a four-digit year.',
        'invalid_days' => ':field must be a number of days, with at most one decimal.',
        'used_exceeds' => 'used_days is more than entitled_days and carried_days together.',
        'duplicate_row' => 'The same employee, leave type and year is already on line :line.',
        'invalid_amount' => ':field must be an amount in birr, such as 12500 or 12500.50.',
        'principal_positive' => 'principal_etb must be more than zero.',
        'remaining_exceeds' => 'remaining_etb is more than principal_etb.',
        'deduction_positive' => 'monthly_deduction_etb must be more than zero while a balance remains.',
        'invalid_date' => 'start_date must be a date written YYYY-MM-DD.',
        'loan_reason' => 'Opening balance brought over',
    ],
];
