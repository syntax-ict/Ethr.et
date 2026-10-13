<?php

return [
    // Decisions the configuration review step asks the admin to confirm. Each is
    // a default that is legally or financially sensitive, so a template proposes
    // it and says so instead of applying it as if it were settled.
    'review' => [
        'leave_private' => 'Leave types use the values ETHR proposes (annual 16 days, sick 30, maternity 120, paternity 3, marriage and bereavement 3). Labour Proclamation 1156/2019 also adds an annual-leave day for every two years of service and pays sick leave in steps; ETHR does not calculate either automatically. Confirm each value before employees request leave.',
        'leave_public' => 'Civil servants are governed by the Federal Civil Servants Proclamation 1064/2017 or a regional civil service law, not the Labour Proclamation. The leave values proposed are ETHR\'s general defaults, not values taken from those laws. Confirm each one against the law that applies to you.',
        'leave_custom' => 'Only annual, sick and unpaid leave are proposed, with ETHR\'s general default days. Set the entitlements your own policy provides.',
        'payroll_public' => 'Government salary scales and allowances are set by the civil service commission. ETHR does not load them: enter your scale on the grades and allowances before running payroll.',
        'allowances_inactive' => 'Allowances are created switched off with a zero amount. An active allowance is paid to every employee, so set its amount and whether it is taxable (transport and some other allowances have exemption limits) before switching it on.',
        'grades_illustrative' => 'Grade salary bands are illustrative starting points for the sector, not market or legal rates. Review them before linking employees. A template never sets anyone\'s salary.',
        'statutory_engine' => 'Income tax and pension contributions are applied by the payroll engine from its own tables. A template does not change them.',
        'flexible_is_grace' => 'ETHR has no core-hours model. A flexible start is approximated with a two-hour grace period, so arriving by 10:00 is not marked late; hours are still counted from the actual check-in and check-out.',
        'seasonal_contracts' => 'Seasonal workers are usually on fixed-term contracts. Record each worker\'s contract and its end date on their employee record; a template does not create contracts.',
        'rotation_crews' => 'The 2-2-2-2 rotation covers every shift only when four crews are assigned to it with start dates two days apart.',
    ],
];
