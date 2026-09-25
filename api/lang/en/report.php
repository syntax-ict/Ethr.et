<?php

declare(strict_types=1);

return [
    'generated' => 'Report generated successfully.',
    'generating' => 'Report is being generated. You will be notified when ready.',
    'downloaded' => 'Report downloaded.',
    'no_data' => 'No data available for the selected criteria.',
    'export_started' => 'Export started. You will receive a notification when complete.',
    'export_completed' => 'Export completed. :filename is ready for download.',
    'export_failed' => 'Export failed. Please try again.',
    'invalid_date_range' => 'Invalid date range. Start date must be before end date.',
    'period_too_long' => 'Report period cannot exceed :max months.',

    // Scheduled report delivery
    'scheduled_subject' => 'Scheduled report: :name',
    'scheduled_body' => 'Your scheduled report ":name" has been generated with :rows row(s).',
    'scheduled_failed_subject' => 'Scheduled report FAILED: :name',
    'scheduled_failed_body' => 'Your scheduled report ":name" could not be generated and no data was sent. Its next run is still scheduled as normal. If it keeps failing, contact an administrator.',
];
