<?php

declare(strict_types=1);

/*
 * Invitations to sign in (InvitationDispatcher, SendInvitationJob).
 *
 * The queue is drained by the cron caller for a few seconds at a time, so a
 * bulk send goes out over minutes, not in the request that asked for it. The
 * per-minute limits are per organisation: one large import must not hold up
 * every other organisation's invitations, or its mail server.
 *
 * Whether texts are used at all is the platform's SMS switch
 * (sms_settings.invitations_enabled, in the console), not this file.
 */
return [
    'per_minute' => (int) env('INVITATIONS_PER_MINUTE', 30),
    'sms_per_minute' => (int) env('INVITATIONS_SMS_PER_MINUTE', 10),

    // Email invitations share one mailbox with every other message, whose day
    // is `mail.daily_cap`. These are percentages of that cap, counted per day in
    // Addis Ababa: what ONE organisation's queued invitations may use, and what
    // ALL organisations' together may use. An invitation over either waits for
    // the next day (08:00 Addis Ababa) instead of using up the platform's mail.
    // A cap of 0 turns the ceiling off. A share of 0 does not: it still lets one
    // invitation a day through. A share of 100 is how to lift one ceiling while
    // keeping the mail budget. Texts have their own share (the console).
    'email_org_share' => (int) env('INVITATIONS_EMAIL_ORG_SHARE', 20),
    'email_share' => (int) env('INVITATIONS_EMAIL_SHARE', 40),

    // A second request for the same person inside this window sends nothing.
    'repeat_after_minutes' => 10,

    // Reminders (invitations:send-reminders): days after the last invitation,
    // how many at most, and how old an invitation may be and still be chased.
    'reminder_after_days' => 3,
    'max_reminders' => 2,
    'reminder_window_days' => 30,
];
