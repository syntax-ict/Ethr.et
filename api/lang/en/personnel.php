<?php

declare(strict_types=1);

return [
    'no_effective_change' => 'This action changes nothing — set at least one new value that differs from the current record.',
    'unknown_reference' => 'The selected :field does not exist in this organization.',
    'end_date_required_for_temporary' => 'Acting, delegation and secondment actions must have an end date.',
    'salary_step_out_of_range' => "This step's salary must fall within the grade's minimum and maximum.",
    'salary_step_not_monotonic' => 'Each step must pay at least as much as the step below it and no more than the step above it.',
    'salary_step_mismatch' => "This grade's salary scale defines step :step at :expected — provide that amount, or leave it blank to use it automatically.",
];
