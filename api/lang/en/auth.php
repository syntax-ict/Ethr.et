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
];
