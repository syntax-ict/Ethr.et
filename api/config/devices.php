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

];
