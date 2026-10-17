<?php

declare(strict_types=1);

return [
    'created' => 'Employee created successfully.',
    'updated' => 'Employee profile updated.',
    'deleted' => 'Employee record deleted.',
    'supervisor_self' => 'An employee cannot be their own supervisor.',
    'import_relation_not_found' => 'Row :row: :field ":value" was not found. Use its code or its name as it appears in ETHR.',
    'import_relation_ambiguous' => 'Row :row: more than one :field is called ":value". Use its code instead.',
    'import_duplicate_in_file' => 'Row :row: :field ":value" is already used on row :first of this file.',
    'import_already_taken' => 'Row :row: the employee code or badge number is already used by another employee.',
    'import_field_department_code' => 'department',
    'import_field_branch_code' => 'branch',
    'import_field_position_code' => 'position',
    'import_field_employee_code' => 'employee code',
    'import_field_email' => 'email',
    'import_field_badge_number' => 'badge number',
    'number_format_invalid' => 'Use one {SEQ:n} token, n from 1 to 8, with letters, digits, - _ . or / around it. For example EMP-{SEQ:4}.',
    'invalid_transition' => 'Cannot change status from :from to :to.',
    'bulk_update_failed' => 'This employee could not be updated. Try again, or change them from their profile.',
];
