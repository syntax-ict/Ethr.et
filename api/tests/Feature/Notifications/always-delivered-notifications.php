<?php

declare(strict_types=1);

/**
 * Every notification class in `app/Notifications` that deliberately does NOT use
 * `RespectsNotificationPreferences`, with the reason it is always delivered.
 *
 * Pinned so that a new notification has to choose: use the trait (and so honour
 * the per-user opt-outs, with a `NotificationType`), or be named here with a
 * reason. `NotificationSeamTest` fails on a class that is in neither, and on one
 * that is in both.
 *
 * Failure notices are never mutable on purpose: muting the good news must not
 * mute the bad (docs/CLAUDE.md, Queue Failure Recovery).
 */
return [
    // Account and security mail
    'AccountActivationNotification' => 'account setup mail; the person cannot sign in without it',
    'MfaResetNotification' => 'security notice that a second factor was turned off; the owner must be told',
    'PasswordResetLinkNotification' => 'account recovery mail; the person cannot sign in without it',
    'SignInCodeNotification' => 'one-time sign-in code; a muted code is a locked-out user',
    'OrganisationRegisteredNotification' => 'tells an address an organisation was registered with it; sent to an address, not a user',
    'OrganisationSignInLinksNotification' => 'find-my-organisation sign-in links, sent to an address, not a user',

    // Platform notices, sent to the platform or to an inbox rather than to an opted-in user
    'NewLeadNotification' => 'mail to the sales inbox, not to a user',
    'SmsGatewayPausedNotification' => 'platform alert; a toggle that silenced it would hide that sign-in codes are email-only',
    'SupportRequestNotification' => 'answers something the person asked, or asks them to answer; muting it leaves a request waiting',
    'SystemAlertNotification' => 'platform alert sent by the system itself',
    'TrialExpiringNotification' => 'billing notice to the organisation admin that the trial is ending',
    'TenantExportFinishedNotification' => 'the admin asked for the export, and the archive is deleted whether or not they come back',

    // Failure and operational notices: muting the good news must not mute the bad
    'AttendancePhotosDueForDeletionNotification' => 'the notice the retention policy promises before a photo is deleted',
    'DashboardDigestFailedNotification' => 'failure notice; a digest that silently stopped looks like one nobody opened',
    'DeviceOfflineNotification' => 'monitoring alert to admins; always delivered',
    'DeviceSyncFailedNotification' => 'monitoring alert to admins; always delivered',
    'PayrollRunFailedNotification' => 'a crashed payroll run must reach a human; muting the success notice must not mute it',
    'ScheduledReportFailedNotification' => 'failure notice to free-text addresses, not users with preferences',

    // Scheduled deliveries to free-text addresses, which have no preference matrix
    'DashboardDigestNotification' => 'scheduled digest to the digest\'s own recipient list',
    'ScheduledReportNotification' => 'scheduled report to the schedule\'s own recipient list, free-text addresses',

    // Employee-facing, waiting for a preference type
    'LetterDecidedNotification' => 'no preference type yet; planned',
    'LetterRequestedNotification' => 'no preference type yet; planned',
];
