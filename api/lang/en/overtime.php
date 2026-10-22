<?php

declare(strict_types=1);

// Overtime asked for in advance (OvertimeRequestController, OvertimeDecisionService).
return [
    'not_pending' => 'This overtime request has already been decided.',
    'cannot_approve_own' => 'You cannot approve your own overtime.',
    'cannot_approve_filed' => 'You cannot approve overtime you asked for on someone\'s behalf.',
    'already_requested' => 'Overtime is already requested for this day. Cancel that request first to ask again.',
    'no_employee_record' => 'Your account is not linked to an employee record, so overtime cannot be requested for you.',
];
