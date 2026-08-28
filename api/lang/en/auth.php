<?php

declare(strict_types=1);

return [
    'failed' => 'These credentials do not match our records.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'login_success' => 'Login successful.',
    'logout_success' => 'Logged out successfully.',
    'mfa_required' => 'Multi-factor authentication is required.',
    'mfa_invalid' => 'Invalid verification code.',
    'mfa_enabled' => 'Two-factor authentication has been enabled.',
    'mfa_disabled' => 'Two-factor authentication has been disabled.',
    'token_refreshed' => 'Token refreshed successfully.',
    'unauthorized' => 'You are not authorized to perform this action.',
    'tenant_inactive' => 'Your organization account is not active.',
    'account_suspended' => 'Your account has been suspended.',
    'impersonation_restricted' => 'This action is not allowed while impersonating a tenant.',
    'not_impersonating' => 'No active impersonation session.',
    'impersonation_ended' => 'Impersonation session ended.',
    'sso_not_configured' => 'Single sign-on is not configured for this organization.',
    'sso_failed' => 'SSO authentication failed. Please try again or contact your administrator.',
    'sso_no_account' => 'No account found for this SSO identity. Contact your administrator.',

    // Account lockout alerting
    'lockout_alert_title' => 'Account Locked After Repeated Failed Sign-Ins',
    'lockout_alert_body' => 'The account ":identifier" was locked for :minutes minutes after repeated failed sign-in attempts from IP :ip.',

    // Session management
    'session_revoked' => 'Session revoked.',
    'sessions_revoked' => 'All other sessions have been signed out.',
    'session_not_found' => 'That session no longer exists.',
    'session_current' => 'This device',

    // Password policy
    'password_too_short' => 'The password must be at least :min characters.',
    'password_needs_uppercase' => 'The password must contain at least one uppercase letter.',
    'password_needs_lowercase' => 'The password must contain at least one lowercase letter.',
    'password_needs_number' => 'The password must contain at least one number.',
    'password_needs_symbol' => 'The password must contain at least one symbol.',
    'password_expired' => 'Your password has expired. Please set a new one.',

    // OTP
    'otp_sent' => 'If the account exists, a verification code has been sent.',
    'otp_invalid' => 'That verification code is invalid or has expired.',
    'otp_unavailable' => 'Verification codes cannot be sent — no SMS gateway is configured.',
    'otp_message' => 'Your ETHR verification code is :code. It expires in :minutes minutes.',

    // Trusted devices
    'device_trusted' => 'This device will be remembered for :days days.',

    'mfa_incomplete' => 'Finish two-factor authentication before using this account.',

    // Platform console
    'platform_mfa_required' => 'Multi-factor authentication must be enabled on your account before you can make changes in the platform console. Set it up under Profile > Security.',
];
