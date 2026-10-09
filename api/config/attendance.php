<?php

return [

    /*
    | How far from "now" an offline punch may be when it is synced.
    |
    | A phone queues punches while it has no signal and sends them later with
    | the time they were captured, which AttendanceEngine records as the punch
    | time. Without a bound the device's clock decided a punch's time outright,
    | so a punch could be backdated by months or dated in the future (audit
    | N50). Seven days covers a week in the field without signal; five minutes
    | forward covers ordinary clock skew between a phone and the server.
    |
    | Deliberately not read from .env: the shared-hosting template's key set is
    | pinned, and nothing about the hosting changes these values.
    */

    'offline_max_age_days' => 7,

    'offline_future_skew_minutes' => 5,

];
