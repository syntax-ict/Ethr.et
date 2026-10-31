<?php

declare(strict_types=1);

return [
    'pull_dispatched' => 'Device event pull has been dispatched to the queue.',
    'token_regenerated' => 'Device webhook token regenerated.',
    'serial_in_use' => 'This serial number is already registered for this device type.',
    'host_malformed' => 'The device address must be a bare IP address or hostname, with no scheme, port or path.',
    'host_unresolved' => 'The device address does not resolve to any IP address.',
    'host_internal' => 'The device address points to a private or internal network, which this server does not connect to.',
    'path_invalid' => 'The :key must be a path starting with a single slash.',
    'read_failed' => 'The device could not be read. Check that it is switched on and reachable from this server, then try again.',
    'simulator_unavailable' => 'The device simulator is only available on development servers. Choose the brand of the real device.',
    'roster' => [
        'read_directly' => 'This device is read directly, so there is nothing to ask it for. Open the list again to read it now.',
    ],
];
