<?php

declare(strict_types=1);

return [
    'failed' => 'These credentials do not match our records.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'logout_success' => 'Logged out successfully.',
    'mfa_required' => 'Multi-factor authentication is required.',
    'mfa_invalid' => 'Invalid verification code.',
    'mfa_enabled' => 'Two-factor authentication has been enabled.',
    'mfa_disabled' => 'Two-factor authentication has been disabled.',
    'account_inactive' => 'Your account is not active.',
    'account_suspended' => 'Your account has been suspended.',
    'canonical_address' => 'Your organisation signs in at its own address. Taking you there.',
    'impersonation_restricted' => 'This action is not allowed while impersonating a tenant.',
    'not_impersonating' => 'No active impersonation session.',
    'impersonation_ended' => 'Impersonation session ended.',
    'sso_not_configured' => 'Single sign-on is not configured for this organization.',

    // Account lockout alerting
    'lockout_alert_title' => 'Account Locked After Repeated Failed Sign-Ins',
    'lockout_alert_body' => 'The account ":identifier" was locked for :minutes minutes after repeated failed sign-in attempts from IP :ip.',

    // Session management
    'session_revoked' => 'Session revoked.',
    'sessions_revoked' => 'All other sessions have been signed out.',
    'session_not_found' => 'That session no longer exists.',

    // Password policy
    'password_too_short' => 'The password must be at least :min characters.',
    'password_needs_uppercase' => 'The password must contain at least one uppercase letter.',
    'password_needs_lowercase' => 'The password must contain at least one lowercase letter.',
    'password_needs_number' => 'The password must contain at least one number.',
    'password_needs_symbol' => 'The password must contain at least one symbol.',

    // OTP
    'otp_sent' => 'If the account exists, a verification code has been sent.',
    'otp_invalid' => 'That verification code is invalid or has expired.',
    'otp_unavailable' => 'Verification codes cannot be sent — no SMS gateway is configured.',
    'otp_message' => 'Your ETHR verification code is :code. It expires in :minutes minutes.',
    'otp_email_unavailable' => 'Sign-in codes cannot be emailed — no mail server is configured.',
    'sms_mobile_only' => 'Text-message codes go only to mobile numbers (starting 9 or 7). Use your mobile number, or get the code by email.',
    'otp_email_subject' => 'Your ETHR sign-in code: :code',
    'otp_email_intro' => 'Use this code to sign in to :organisation on ETHR:',
    'otp_email_expiry' => 'It expires in :minutes minutes and works once.',
    'otp_email_ignore' => 'If you did not ask to sign in, ignore this email. Nobody can use the code without access to your inbox.',

    // Trusted devices

    'mfa_incomplete' => 'Finish two-factor authentication before using this account.',

    // Platform console
    'platform_mfa_required' => 'Multi-factor authentication must be enabled on your account before you can make changes in the platform console. Set it up under Profile > Security.',

    // Tenant security policy (audit N6)
    'mfa_enrolment_required' => 'Your organization requires two-factor authentication. Set it up under Profile > Security to continue.',
    'mfa_disabled_by_policy' => 'Your organization does not offer two-factor authentication.',
    'session_idle_expired' => 'Your session ended after a period of inactivity. Sign in again.',

    // "Find my organisation" on the apex login (2026-10-04)
    'find_organisation' => [
        'sent' => "If that address belongs to an organisation, we've emailed you its sign-in link.",
        'too_many' => 'Too many requests for this address. Try again in a few minutes.',
        'mail_subject' => 'Your ETHR sign-in link',
        'mail_intro' => 'You asked which organisations this address can sign in to on ETHR. Use the link for the one you want:',
        'mail_action' => 'Sign in',
        'mail_ignore' => 'If you did not ask for this, you can ignore this email; nothing has changed.',
    ],

    // Sent when an organisation registers (production QA 2026-10-11, P7)
    'registered' => [
        'mail_subject' => ':org is ready on ETHR',
        'mail_greeting' => 'Welcome to ETHR',
        'mail_intro' => 'An ETHR organisation, :org, was created with this email address as its administrator.',
        'mail_address' => 'Your organisation signs in at :url — bookmark it.',
        'mail_action' => 'Sign in to :org',
        'mail_not_you' => 'If you did not create this organisation, tell us at :contact and we will remove it.',
    ],
];
