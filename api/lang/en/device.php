<?php

declare(strict_types=1);

return [
    'pull_dispatched' => 'Device event pull has been dispatched to the queue.',
    'not_found' => 'Device not found.',
    'offline' => 'Device is offline and cannot be reached.',
    'sync_complete' => 'Device sync completed successfully.',
    'webhook_received' => 'Webhook event received and processed.',
    'token_regenerated' => 'Device webhook token regenerated.',
    'serial_in_use' => 'This serial number is already registered for this device type.',
    'host_malformed' => 'The device address must be a bare IP address or hostname, with no scheme, port or path.',
    'host_internal' => 'The device address points to a private or internal network, which this server does not connect to.',
    'path_invalid' => 'The :key must be a path starting with a single slash.',
];
