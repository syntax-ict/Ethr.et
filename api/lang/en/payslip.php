<?php

declare(strict_types=1);

return [
    // The payslip PDF (resources/views/payslip.blade.php), in the reader's language.
    'title' => 'Payslip for :period',
    'voided' => 'VOIDED',
    'employee' => 'Employee',
    'code' => 'Code',
    'department' => 'Department',
    'position' => 'Position',
    'earnings' => 'Earnings',
    'basic_salary' => 'Basic salary',
    'overtime' => 'Overtime',
    'allowance' => 'Allowance',
    'gross' => 'Gross salary',
    'deductions' => 'Deductions',
    'income_tax' => 'Income tax',
    'employee_pension' => 'Employee pension',
    'other_deductions' => 'Other deductions',
    'net' => 'Net pay',
    'employer_pension' => 'Employer pension contribution',
    'currency' => 'ETB',
    'generated' => 'Generated on :date. This is a computer-generated document.',
    'powered_by' => ':organisation, powered by ETHR',
    'deduction_types' => [
        'loan' => 'Loan repayment',
        'cost_sharing' => 'Cost sharing',
    ],
];
