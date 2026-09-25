<?php

declare(strict_types=1);

/**
 * Routes that reach a controller action containing no authorization construct.
 *
 * `ControllerAuthorizationInventoryTest` builds this set from the live router
 * and fails when it moves. Keyed by the route's action name, because a URI can
 * be re-prefixed without the authorization question changing, and because two
 * routes pointing at one action are one question, not two.
 *
 * Read `docs/CLAUDE.md` Convention #7 before adding an entry. Be exact about
 * what this file buys: it makes an unguarded endpoint a deliberate act. It does
 * not audit the ones already listed — a pin cannot. The `authenticated` set
 * below was read individually on 2026-09-25 and each reason is a measurement;
 * the `public` set was not, and says so.
 */
return [
    /*
     * Inside the `auth:sanctum` group. Sanctum plus `EnsureUserBelongsToTenant`
     * establishes *who* and *which tenant*; every entry here narrows to the
     * caller's own rows from `$request->user()`, or is tenant-wide on purpose.
     *
     * Each reason below names the expression that does the scoping. If you
     * change one of these actions and that expression is no longer there, the
     * entry is wrong even though this file still passes.
     */
    'authenticated' => [
        'App\Http\Controllers\Api\V1\Auth\LogoutController@__invoke' => 'Revokes the caller\'s own token: logout($request->user()).',
        'App\Http\Controllers\Api\V1\Auth\RefreshController@__invoke' => 'Re-issues the caller\'s own token: refreshToken($request->user()).',
        'App\Http\Controllers\Api\V1\Auth\MeController@__invoke' => 'Reads $request->user() and its own employee record. Nothing is addressable.',
        'App\Http\Controllers\Api\V1\Auth\MfaSetupController@setup' => 'Generates a TOTP secret for $request->user(). Refuses at 409 if already enabled.',
        'App\Http\Controllers\Api\V1\Auth\MfaSetupController@enable' => 'Enables MFA on $request->user() after verifying a code against the secret it just issued them.',
        'App\Http\Controllers\Api\V1\Auth\MfaSetupController@disable' => 'Disables MFA on $request->user(); the request carries their own current code.',
        'App\Http\Controllers\Api\V1\Auth\MfaVerifyController@__invoke' => 'Verifies a code against $request->user()\'s own secret.',
        'App\Http\Controllers\Api\V1\Auth\PasswordResetController@change' => 'Changes the password of $request->user(), against their own current one.',
        'App\Http\Controllers\Api\V1\Auth\SessionController@index' => 'Scoped to $request->user()->tokens().',
        'App\Http\Controllers\Api\V1\Auth\SessionController@destroy' => 'whereKey() applied to $user->tokens() — another user\'s token id misses.',
        'App\Http\Controllers\Api\V1\Auth\SessionController@revokeAll' => 'Deletes from $user->tokens(), keeping the current one.',
        'App\Http\Controllers\Api\V1\Auth\TrustedDeviceController@index' => 'where(\'user_id\', $user->id).',
        'App\Http\Controllers\Api\V1\Auth\TrustedDeviceController@destroy' => 'where(\'user_id\', $user->id)->whereKey($id) — another user\'s id misses.',
        'App\Http\Controllers\Api\V1\Leave\LeaveRequestController@my' => 'where(\'employee_id\', $user->employee_id).',
        'App\Http\Controllers\Api\V1\Leave\LeaveRequestController@balance' => 'where(\'employee_id\', $user->employee_id).',
        'App\Http\Controllers\Api\V1\Leave\LeaveRequestController@cancel' => 'Explicit 403 when $leaveRequest->employee_id !== $user->employee_id, before any write.',
        'App\Http\Controllers\Api\V1\Dashboard\DashboardController@employee' => 'EmployeeDashboardService::assemble($user) — the caller is the only input.',
        'App\Http\Controllers\Api\V1\Profile\ProfilePreferencesController@update' => 'Writes preferences on $request->user().',
        'App\Http\Controllers\Api\V1\Profile\ProfileController@show' => 'Reads $request->user() and its own employee record.',
        'App\Http\Controllers\Api\V1\Profile\ProfileController@update' => 'Writes to $user->employee, the caller\'s own record; 422 when they have none.',
        'App\Http\Controllers\Api\V1\Profile\ProfilePhotoController@store' => 'Writes photo_path on employeeOrNull($request), the caller\'s own employee row.',
        'App\Http\Controllers\Api\V1\Profile\ProfilePhotoController@destroy' => 'Acts on employeeOrNull($request), the caller\'s own employee row.',
        'App\Http\Controllers\Api\V1\Profile\ProfileEmergencyContactController@index' => 'Reads $employee->emergencyContacts() off employeeOrNull($request).',
        'App\Http\Controllers\Api\V1\Profile\ProfileEmergencyContactController@store' => 'Creates through $employee->emergencyContacts() on employeeOrNull($request); capped at MAX_CONTACTS.',
        'App\Http\Controllers\Api\V1\Profile\ProfileEmergencyContactController@update' => 'ownedContact($employee, $publicId) — a contact of another employee misses.',
        'App\Http\Controllers\Api\V1\Profile\ProfileEmergencyContactController@destroy' => 'ownedContact($employee, $publicId) — a contact of another employee misses.',
        'App\Http\Controllers\Api\V1\Profile\ProfileUpdateRequestController@withdraw' => 'where(\'employee_id\', $employee->id) on the lookup; 404 when it misses.',
        'App\Http\Controllers\Api\V1\Notification\NotificationController@index' => 'Scoped to $request->user()->notifications().',
        'App\Http\Controllers\Api\V1\Notification\NotificationController@unreadCount' => 'Counts $request->user()->unreadNotifications().',
        'App\Http\Controllers\Api\V1\Notification\NotificationController@markAsRead' => 'firstOrFail() on $request->user()->notifications() — another user\'s id 404s.',
        'App\Http\Controllers\Api\V1\Notification\NotificationController@markAllAsRead' => 'Acts on $request->user()->unreadNotifications().',
        'App\Http\Controllers\Api\V1\Notification\NotificationPreferencesController@update' => 'Writes preferences for $request->user() only.',
        'App\Http\Controllers\Api\V1\Notification\NotificationPreferencesController@index' => 'where(\'user_id\', $user->id).',

        /*
         * The two that are tenant-wide by design rather than self-scoped. Here
         * `BelongsToTenant` is the whole boundary, and that is the intent: a
         * staff directory every colleague can search, and announcements every
         * colleague is meant to read.
         */
        'App\Http\Controllers\Api\V1\Directory\DirectoryController@index' => 'Tenant-wide staff directory — name, work phone, work email, photo, department, position, branch. Visible to every colleague by design; pinned by EmployeePortalTest.',
        'App\Http\Controllers\Api\V1\Announcement\AnnouncementController@index' => 'Tenant-wide, filtered to ->published()->notExpired().',
    ],

    /*
     * Outside the `auth:sanctum` group. These are not "unprotected endpoints":
     * each authenticates by a presented secret, a route middleware, or is
     * deliberately public. What they are not is *individually audited* — the
     * reasons below are read off the route definition and this file's own
     * history, not off a line-by-line pass over each action. Treat an entry
     * here as a claim to check, not a claim that has been checked.
     */
    'public' => [
        'App\Http\Controllers\Api\V1\HealthController@__invoke' => 'Deliberately public liveness probe, throttle:health.',
        'App\Http\Controllers\Api\V1\Cron\CronRunController@schedule' => 'VerifyCronToken middleware.',
        'App\Http\Controllers\Api\V1\Cron\CronRunController@queue' => 'VerifyCronToken middleware.',
        'App\Http\Controllers\Api\V1\PlanController@index' => 'Deliberately public pricing.',
        'App\Http\Controllers\Api\V1\SiteContentController@index' => 'Deliberately public marketing copy.',
        'App\Http\Controllers\Api\V1\TemplateController@index' => 'Deliberately public template catalogue.',
        'App\Http\Controllers\Api\V1\TemplateController@show' => 'Deliberately public template catalogue.',
        'App\Http\Controllers\Api\V1\ContactController@__invoke' => 'Deliberately public contact form, throttle:auth.',
        'App\Http\Controllers\Api\V1\Auth\LoginController@__invoke' => 'The authentication endpoint itself — the presented credential is the authority.',
        'App\Http\Controllers\Api\V1\Auth\RegisterController@__invoke' => 'Pre-authentication tenant sign-up, throttle:auth.',
        'App\Http\Controllers\Api\V1\Auth\OtpController@request' => 'Pre-authentication OTP issue, throttle:otp.',
        'App\Http\Controllers\Api\V1\Auth\OtpController@verify' => 'The presented OTP is the authority.',
        'App\Http\Controllers\Api\V1\Auth\PasswordResetController@forgot' => 'Pre-authentication; the lookup states tenant_id from the hostname.',
        'App\Http\Controllers\Api\V1\Auth\PasswordResetController@reset' => 'The presented reset token is the authority; the lookup states tenant_id from the hostname.',
        'App\Http\Controllers\Api\V1\Auth\SubdomainCheckController@__invoke' => 'Pre-authentication availability check during sign-up.',
        'App\Http\Controllers\Api\V1\Kiosk\KioskSessionController@authenticate' => 'The kiosk session token is the authority: where(\'token\')->where(\'status\', \'active\'), 401 when it misses.',
        'App\Http\Controllers\Api\V1\Kiosk\KioskCheckInController@__invoke' => 'X-Kiosk-Token resolves an active KioskSession; the employee lookup then states that session\'s tenant_id.',
        'App\Http\Controllers\Api\V1\Auth\SessionClaimController@__invoke' => 'Single-use handoff nonce, bound to the hostname\'s tenant rather than the body.',
        'App\Http\Controllers\Api\V1\Auth\TenantContextController@__invoke' => 'Returns only a tenant\'s name, subdomain and logo — the branding a login page needs before anyone has authenticated.',
        'App\Http\Controllers\Api\V1\Auth\SsoController@initiate' => 'Pre-authentication SAML leg.',
        'App\Http\Controllers\Api\V1\Auth\SsoController@callback' => 'The signed SAML assertion is the authority.',
        'App\Http\Controllers\Api\V1\Auth\SsoController@metadata' => 'Deliberately public SP metadata.',
        'App\Http\Controllers\Api\V1\Scim\ScimUserController@index' => 'ScimAuth middleware.',
        'App\Http\Controllers\Api\V1\Scim\ScimUserController@show' => 'ScimAuth middleware.',
        'App\Http\Controllers\Api\V1\Scim\ScimUserController@store' => 'ScimAuth middleware.',
        'App\Http\Controllers\Api\V1\Scim\ScimUserController@update' => 'ScimAuth middleware.',
        'App\Http\Controllers\Api\V1\Scim\ScimUserController@destroy' => 'ScimAuth middleware.',
        'App\Http\Controllers\Api\V1\Scim\ScimGroupController@index' => 'ScimAuth middleware.',
        'App\Http\Controllers\Api\V1\Scim\ScimGroupController@show' => 'ScimAuth middleware.',
        'App\Http\Controllers\Api\V1\Scim\ScimGroupController@store' => 'ScimAuth middleware.',
        'App\Http\Controllers\Api\V1\Scim\ScimGroupController@update' => 'ScimAuth middleware.',
        'App\Http\Controllers\Api\V1\Scim\ScimGroupController@destroy' => 'ScimAuth middleware.',
        'App\Http\Controllers\Api\V1\Device\DeviceController@webhookHikvision' => 'Device webhook token (unique index since BASELINE §11e) or IP allowlist, resolved in resolveWebhookDevice().',
        'App\Http\Controllers\Api\V1\Device\DeviceController@webhookZkteco' => 'Device webhook token or IP allowlist, resolved in resolveWebhookDevice().',
        'App\Http\Controllers\Api\V1\Device\DeviceController@webhookSuprema' => 'Device webhook token or IP allowlist, resolved in resolveWebhookDevice().',
    ],
];
