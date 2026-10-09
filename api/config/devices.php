<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Private device addresses
    |--------------------------------------------------------------------------
    |
    | Whether a device may be registered at a loopback, private or link-local
    | address. Off by default: on multi-tenant hosting, letting a tenant point
    | the server at 127.0.0.1 or 10.0.0.0/8 lets it probe the host's own network,
    | and the server cannot reach a customer's LAN from there in any case.
    |
    | Turn it on for an on-premise install, where the server shares a network
    | with the biometric devices, and for local development against real
    | hardware. See App\Services\Device\DeviceHost.
    |
    */

    'allow_private_hosts' => (bool) env('DEVICE_ALLOW_PRIVATE_HOSTS', false),

    /*
    |--------------------------------------------------------------------------
    | Event pull time budget
    |--------------------------------------------------------------------------
    |
    | Seconds one PullDeviceEventsJob spends paging through a device before it
    | stops and queues a continuation from where it got to. Device APIs return
    | about 100 events a call, so a long history or a backlog after an outage
    | takes many calls.
    |
    | Production has no worker process: the cron caller runs `queue:work` for at
    | most `cron.queue_max_seconds` (50) inside an HTTP request, under stock PHP
    | time limits. A job that pages until the device is empty would be killed
    | part-way on a large import. Keep this well under both.
    |
    */

    'pull_time_budget_seconds' => (int) env('DEVICE_PULL_TIME_BUDGET', 20),

];
