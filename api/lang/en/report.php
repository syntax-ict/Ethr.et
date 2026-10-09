<?php

declare(strict_types=1);

return [

    // Scheduled report delivery
    'scheduled_subject' => 'Scheduled report: :name',
    'scheduled_body' => 'Your scheduled report ":name" has been generated with :rows row(s).',
    'scheduled_failed_subject' => 'Scheduled report FAILED: :name',
    'scheduled_failed_body' => 'Your scheduled report ":name" could not be generated and no data was sent. Its next run is still scheduled as normal. If it keeps failing, contact an administrator.',
];
