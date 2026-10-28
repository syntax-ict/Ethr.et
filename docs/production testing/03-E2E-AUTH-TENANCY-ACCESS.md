# E2E specification 03: sign-in, tenancy and access control

Columns, personas and modes are defined in the [README](README.md#how-a-test-case-is-written) and the
[test plan](01-TEST-PLAN.md#5-test-accounts-and-personas). API paths are under `/api/v1`. Error
responses are `application/problem+json` with `type`, `title`, `status`, `detail` and, for 422,
`errors`.

**Before running the negative sign-in cases**, read the lockout rule (AUTH-010): five failures in a
minute on one identifier and IP give 429; ten in fifteen minutes lock it for fifteen minutes. Use a
spare account (EMP3) for them, and run them last.

---

## 1. Password sign-in

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| AUTH-001 | P1 | R | Valid sign-in, each role | For each of TA, HR, FIN, DA, SUP, EMP: open the organisation address, sign in | Lands on `/dashboard`; the sidebar shows only the sections the role holds (see ACC-001) |
| AUTH-002 | P1 | R | Wrong password | Sign in as EMP3 with a wrong password | 422; "These credentials do not match our records." under the field used |
| AUTH-003 | P1 | R | Unknown account | Sign in with an address that has no account | Exactly the same message and status as AUTH-002 (no hint that the account does not exist) |
| AUTH-004 | P2 | R | Sign-in identifier types | In Settings, see which identifiers the organisation allows (email by default; phone, employee code, username are optional). Sign in with each allowed one | Each allowed identifier works; a disallowed one fails like AUTH-002 |
| AUTH-005 | P1 | W | Suspended user | TA deactivates a test user; that user signs in | 403 `account-inactive`, "Your account has been suspended." |
| AUTH-006 | P1 | W | Deactivated while signed in | User signed in in one browser; TA deactivates them; user clicks anything | 401 `account-inactive`; sent to the sign-in page; the session no longer works |
| AUTH-007 | P2 | R | Organisation not given on the apex | On `https://www.ethr.et/login` with no organisation chosen, try to sign in | 422 on `tenant`: "Organization is required. Provide your subdomain." (or the page asks for the organisation first) |
| AUTH-008 | P1 | R | Typed organisation wins | Sign in to QA Audit, sign out. On the apex sign-in, type the QA Isolation organisation and that organisation's credentials | Signed in to QA Isolation, not refused because the browser remembered QA Audit |
| AUTH-009 | P2 | R | Canonical address | For an organisation that uses its own subdomain or verified domain, try to sign in on `www.ethr.et/{slug}` | 409 `canonical-address` with the right `canonical_url`, or a redirect to it, before the password is checked |
| AUTH-010 | P1 | R | Lockout | As EMP3, fail 5 times within one minute | Sixth attempt: 429 "Too many failed login attempts. Please try again in N seconds." with a `Retry-After` header. Continue to 10 failures in 15 minutes: locked 15 minutes. The admins receive one alert email when the lock starts |
| AUTH-011 | P2 | R | Success clears the counter | After 3 failures, sign in correctly, then fail again | Counting restarts from zero |
| AUTH-012 | P1 | R | Password expiry | If the organisation sets `expiry_days`, sign in with an expired password | Sign-in succeeds with `password_expired: true`; the interface asks for a new password |
| AUTH-013 | P1 | R | Sign out | Sign out, then open `/dashboard` directly, and press Back | Sent to the sign-in page; Back does not show cached private data |
| AUTH-014 | P1 | R | Unauthenticated deep link | Signed out, open `/payroll` | Redirected to sign-in; after sign-in, returns to `/payroll` (or the dashboard) |

## 2. Sign-in codes (email and SMS)

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| AUTH-020 | P1 | X | Code by email | `/login/otp`, give EMP's email | 200 "If the account exists, a verification code has been sent."; the email arrives with a 6-digit code |
| AUTH-021 | P1 | X | Use the code | Enter the code within 5 minutes | Signed in |
| AUTH-022 | P1 | X | Code reuse and expiry | Use the same code again; request a code and wait 6 minutes before using it | Both 422 `invalid-otp`, "That verification code is invalid or has expired." |
| AUTH-023 | P2 | X | New code replaces old | Request two codes; use the first | First refused, second works |
| AUTH-024 | P2 | R | Unknown address | Request a code for an address with no account | Same 200 message as AUTH-020; no email |
| AUTH-025 | P2 | X | Rate limit | Request 4 codes for one address within a minute | The fourth gets 429 |
| AUTH-026 | P1 | X | Code by SMS | Only with the owner's go-ahead (billed). Request with an Ethiopian mobile number in `09…` and in `+2519…` form | One SMS per request; both forms find the same account |
| AUTH-027 | P2 | R | Non-mobile number | Request with a landline (`011…`) | 422 `sms_mobile_only` |
| AUTH-028 | P2 | R | Both channels | Send email and phone together (API) | 422: the two fields cannot be combined |
| AUTH-029 | P2 | R | SMS not configured | When SMS is switched off in the console | 503 `otp-unavailable`; the page explains SMS sign-in is unavailable |
| AUTH-030 | P3 | R | Code length | Enter 5 or 7 digits | 422 on `code` |

## 3. Multi-factor authentication

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| MFA-001 | P1 | W | Enrol | As HR: Profile → Security → Enable authenticator; scan the QR, enter the code | Enabled. A wrong code gives "Invalid verification code." |
| MFA-002 | P1 | W | Sign-in asks for the code | Sign out, sign in as HR | After the password, `/login/mfa` asks for 6 digits; the dashboard is not reachable until it is given |
| MFA-003 | P1 | R | Challenge token is limited | After the password and before the code, call any API endpoint other than `/auth/mfa/verify`, `/auth/me`, `/auth/logout` (for example `GET /employees`) | 403 `mfa-incomplete` |
| MFA-004 | P1 | R | Challenge expires | Wait more than 5 minutes on the code screen, then enter a valid code | Refused; must sign in again |
| MFA-005 | P2 | W | Trusted browser | Tick "trust this device" when entering the code; sign out and in | No code asked on that browser; still asked on another browser. Profile → Security lists the device; removing it ("Device removed. MFA will be required on it again.") brings the code back |
| MFA-006 | P2 | W | Disable | Profile → Security → disable with a current code | Disabled; a wrong code refuses; disabling when not enabled gives 409 |
| MFA-007 | P1 | W | Organisation requires MFA | TA sets the MFA policy to `required`. A user without MFA signs in | 403 `mfa-enrolment-required` on everything except account security; the interface redirects to `/profile/security?mfa=required` |
| MFA-008 | P2 | W | Organisation forbids MFA | Policy `disabled`; a user tries to set up MFA | 403 `mfa-disabled-by-policy` |
| MFA-009 | P1 | X | Admin resets a lost authenticator | TA: Settings → Users → reset MFA for HR | HR signs in with the password only and is told to set MFA up again; HR receives an email. Resetting a user without MFA gives 409 |
| MFA-010 | P1 | R | Reset limits | TA tries to reset their own MFA; HR tries to reset TA's | Own: 422. Higher role: 403 |
| MFA-011 | P2 | R | No recovery codes | Check Profile → Security | No recovery codes are offered (by design); the documented recovery is an admin reset or the `ethr:reset-mfa` command run by the operator |

## 4. Sessions, idle timeout and tokens

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| SES-001 | P2 | R | Session list | Sign in as EMP on two browsers; Profile → Security → sessions | Both listed; the current one marked |
| SES-002 | P1 | W | Revoke one | From browser A, end browser B's session | B is signed out at its next action; ending an already ended one gives 404 "That session no longer exists." |
| SES-003 | P2 | W | Revoke all others | "Sign out everywhere else" | Every session but the current one ends |
| SES-004 | P1 | W | Idle timeout | TA sets the session timeout to 5 minutes. EMP signs in and leaves the tab idle for 6 minutes, then clicks | 401 `session-expired`, "Your session ended after a period of inactivity. Sign in again." Restore the timeout (default 480) afterwards |
| SES-005 | P2 | R | Background polling does not keep a session alive | With the 5-minute timeout, leave the dashboard open (it polls notifications) for 6 minutes | The session still expires |
| SES-006 | P2 | R | Silent refresh | Stay active for more than 15 minutes (token lifetime) | No sign-out; the token refreshes in the background |
| SES-007 | P1 | R | Cookie flags | In developer tools, inspect the `access_token` cookie | `HttpOnly`, `Secure`, `SameSite=Lax`, path `/api`. Not readable from `document.cookie` |

## 5. Password reset, change and invitations

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PWD-001 | P1 | X | Forgot password | `/login/forgot` with EMP's email | "If an account with that email exists in this organization, a reset link has been sent."; email arrives |
| PWD-002 | P1 | R | Same answer for unknown address | Repeat with an unknown address | Identical message; no email |
| PWD-003 | P1 | X | Reset | Open the link, set a new password | "Password has been reset. Please sign in with your new password."; all EMP's other sessions are signed out |
| PWD-004 | P1 | R | Link reuse and expiry | Use the same link again; use a link older than 60 minutes | 422 "This reset link is invalid or has expired." |
| PWD-005 | P2 | R | Forged token | Open `/login/reset` with a made-up token | Same refusal as PWD-004 |
| PWD-006 | P2 | X | Reset rate limit | Request 4 resets for one address within 5 minutes | Fourth: 429 "Too many password reset attempts. Try again in a few minutes." |
| PWD-007 | P1 | W | Change password | Profile → Security → change; wrong current password; then correctly; then reuse the current one as new | Wrong current: "The current password is incorrect." Same as current: refused. Success signs out other sessions |
| PWD-008 | P1 | R | Policy | Try a 7-character password; if the organisation adds composition rules, try one that breaks them | Refused with the rule named. The minimum is never below 8 |
| PWD-009 | P1 | X | Invite a user | TA: Settings → Users → invite (email the team controls, role `hr_admin`) | 201; status `invited`; email with a link to `/login/reset?token=…&email=…&tenant=…` |
| PWD-010 | P1 | X | Accept the invitation | Open the link, set a password | "Your account is now active. Please sign in with your new password."; status `active` |
| PWD-011 | P2 | R | Invitation expiry | Use an invitation older than 72 hours | Refused as invalid or expired |
| PWD-012 | P2 | X | Resend | Resend an invitation to an `invited` user; try to resend to an active user | First works; second 422 |
| PWD-013 | P1 | R | Cannot invite above own role | HR invites someone as `tenant_admin`; anyone tries `super_admin` | Refused |
| PWD-014 | P2 | R | Duplicate email | Invite an email already in the organisation | 422 on `email` |

## 5a. In-person activation (no email, no phone)

HR gives a one-time code, face to face, to an employee who has no email and no phone.
`GET|POST|DELETE /employees/{employee}/activation-code` needs `users.issueActivationCode` (`hr_admin`,
inherited by `tenant_admin`) and the employee inside the issuer's reach. The employee redeems the code
at the public `POST /auth/activation/redeem`, rate limited by `activation` (20 a minute per IP, 10 a
minute per organisation and employee code) on top of `auth`. Needs test employee **E-NC** (EMP-014)
and employee code sign-in on (AUTH-004).

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| ACT-001 | P1 | W | Issue a code | HR opens E-NC → "Activate in person"; "Document checked" National ID; tick "I checked this person's identity document in person"; own password (and authenticator code if HR has MFA) → "Issue code" | 201, `Cache-Control: no-store`. A QR and "Or type this code" as `XXXX-XXXX-XXXX` (12 characters: digits and letters without I, L, O, U); `purpose: activation`; `expires_at` 24 hours later. Audit "Activation code issued" records the document type, never a document number. Reopening the dialog never shows the code again (`GET` gives `state: live`, no code) |
| ACT-002 | P1 | W | Redeem by QR | E-NC scans the QR with their own phone, chooses and confirms a password → "Activate account"; then signs in with employee code and that password | Opens `…/login/activate?tenant=qa-audit` with the employee code and code filled; the code leaves the address bar. "Your account is ready" and "Sign in with your employee code and the password you just chose.", then back to sign-in, which reaches the dashboard. HR's status shows `used`, login `active`. Audit `user.activation_code_redeemed` and `user.activated`. Redeeming never signs the employee in by itself |
| ACT-003 | P1 | R | Single use | Submit the ACT-002 code again | 422 `invalid-activation-code`, "This code is not valid. Ask HR to issue a new one."; the page shows "This code is not valid or has expired. Ask HR to issue a new one." |
| ACT-004 | P1 | R | Failures are identical | With a live code for E-NC, submit a wrong code; an unknown employee code; the right code with another employee's code | All give the same 422 body as ACT-003; nothing tells whether the employee or the code exists |
| ACT-005 | P1 | W | Lost password, in person | E-NC is active. HR issues again (title "Reset sign-in for :name in person"); E-NC types the code by hand in lower case, without hyphens, `o` for 0 and `l` for 1, and sets a new password | Accepted (spaces and hyphens dropped, O read as 0, I and L as 1). `purpose: recovery`. Every other session of E-NC ends; status stays `active`; MFA, if E-NC had it, stays on |
| ACT-006 | P1 | R | Lock after five wrong tries | With a live code, 5 wrong codes for E-NC's employee code, then the right one | All six get the ACT-003 422, the right one included. HR's status shows `locked`. Audit "Activation code locked after failed tries" |
| ACT-007 | P2 | W | A new code withdraws the old one | Issue code A ("A code is already live. Issuing a new one withdraws it." on the next issue), then code B. Redeem A. "Withdraw code" on B twice | A refused as ACT-003. First withdraw `revoked: true`, "Code withdrawn"; second `revoked: false`. Withdrawing while impersonating: 403 `impersonation-restricted` |
| ACT-008 | P1 | R | Who may issue, and for whom | `POST /employees/{id}/activation-code` as SUP, DA, FIN. As HR: on HR's own record; on TA's; on EMP (has an email); on a deactivated login; on a QA Isolation employee id | SUP, DA, FIN: 403. Otherwise 422 `activation-code-refused` with a `reason`: own record "You cannot issue an activation code for your own account."; TA "You cannot issue an activation code for an account at or above your own level."; EMP "This employee has an email or a phone number. Use the normal invitation or password reset instead." (no button shown); deactivated "This account cannot be activated or recovered in its current state." Other organisation: 404 |
| ACT-009 | P1 | R | Step-up and attestation | Issue with a wrong password; HR with MFA leaves out the authenticator code, then gives a wrong one; leave the box unticked; leave the document unchosen | Wrong password: 422 on `current_password`, "The password is incorrect." Missing or wrong authenticator code: 422 on `mfa_code` with the same text (see K-21). Unticked: "The attested field must be accepted." No document: refused on `document_type`. No code issued in any case |
| ACT-010 | P2 | W | No way to sign in afterwards | TA turns employee code sign-in off (E-NC has no username); HR issues a code. Restore the setting | 422 "This employee would have no way to sign in. Turn on employee code sign-in in settings first." |
| ACT-011 | P2 | R | Password rules at redemption | With a live code: a password equal to the employee code in other case; 7 characters; a mismatched confirmation | "The password field and employee code must be different."; the PWD-008 policy refusal; "The password field confirmation does not match." The code is not spent |
| ACT-012 | P2 | R | Typing the code without the QR | Open `/login/activate` with no `?tenant=`, on the apex and on QA Audit's own address. Look for a link to it on the sign-in page | Record what happens. Suspected: "Invalid activation link" / "This link does not say which organisation it is for. Ask HR to show you the code again." everywhere, and no link from sign-in, although HR's dialog tells staff to "open the sign-in page and type the code" (K-19) |
| ACT-013 | P3 | R | Expiry, rate limit, purge | Redeem a code older than 24 hours; send 11 redemptions in a minute for one employee code. Observe: `auth:purge-activation-codes` daily 00:00 UTC | Expired: the ACT-003 422, HR's status `expired`. Excess: 429. Codes more than 30 days past expiry are deleted; HR's status then reads `none` |
## 6. Find my organisation and registration

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| ORG-001 | P1 | X | Find my organisation | On the apex sign-in, "find my organisation" with EMP's email | "If that address belongs to an organisation, we've emailed you its sign-in link."; email lists QA Audit's sign-in link (and any other active organisation the address belongs to) |
| ORG-002 | P1 | R | Unknown address | Same with an unknown address | Same message; no email |
| ORG-003 | P2 | R | Limit | 4 requests in 5 minutes for one address | Fourth refused (429) |
| ORG-004 | P1 | W | Register an organisation | `/register` with slug `uat-iso-<date>`, organisation name, admin name, a +251 phone, password | 201; signed in as `tenant_admin`; status `trial`, Starter plan, trial ends **6 months** from today; a welcome email arrives |
| ORG-005 | P1 | R | Slug check | Type a taken slug, a deleted organisation's slug, a name another organisation retired by renaming (TEN-019), a reserved one (`admin`, `api`, `www`, `login`, `register`, `dashboard`, `am`, `en`), one with capitals or an underscore, 2 characters | Live check says unavailable or invalid for each; submit refused (taken or retired: "The subdomain has already been taken.") |
| ORG-006 | P2 | R | Phone forms | Register with `0911…` | Stored as `+251911…` |
| ORG-007 | P2 | R | Registration closed | Only on a single-organisation installation | 403 `registration-closed` (not expected on ethr.et; record if seen) |

## 7. Organisation addresses and tenancy

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| TEN-001 | P1 | R | Entry address | Open `https://www.ethr.et/qa-audit` | The organisation's branded sign-in page (or a redirect to its own address if it has one) |
| TEN-002 | P1 | R | Apex redirect | Open `https://ethr.et/qa-audit` | 301 to `https://www.ethr.et/qa-audit` |
| TEN-003 | P1 | R | Unknown slug | Open `https://www.ethr.et/no-such-org-xyz` | A real 404 page; API calls with that organisation give 404 `tenant-not-found`, "No tenant found for 'no-such-org-xyz'." |
| TEN-004 | P2 | R | Reserved slug | Open `https://www.ethr.et/admin`, `/api`, `/login` | Not treated as an organisation |
| TEN-005 | P1 | R | Subdomain address | If subdomains are on, open `https://qa-audit.ethr.et` | Redirects to `/login` on that host; sign-in works there |
| TEN-006 | P1 | R | Suspended organisation | PA suspends a `uat-` organisation; its admin uses it | 403 `tenant-inactive`, "This tenant account is not active."; the interface shows "Organization unavailable". The public entry page still answers the same as for an active one. Lookups are cached up to 5 minutes, so allow that before failing the case |
| TEN-007 | P1 | R | Expired trial | A `uat-` organisation whose trial end is in the past | Treated as inactive (same as TEN-006) until it moves to a paid plan or the trial is extended |
| TEN-008 | P1 | R | Token from another organisation | Signed in to QA Audit, send an API request naming QA Isolation (`X-Tenant: uat-iso-…` on the apex) | 403 `tenant-mismatch`, "Your account does not belong to this tenant." |
| TEN-009 | P1 | R | Console only on the platform host | As TA, open `https://www.ethr.et/admin`; call `GET /api/v1/admin/tenants` on the organisation address | The page redirects to `admin.ethr.et`; the API gives 404 "Platform administration is not available on this host." |
| TEN-010 | P1 | R | Tenant pages on the platform host | As PA, open `https://admin.ethr.et/dashboard` | Redirected to `/admin` |
| TEN-011 | P1 | R | Super admin on a tenant host | PA tries to sign in on QA Audit's address | Refused (403); only impersonation reaches a tenant |
| TEN-012 | P2 | R | Header ignored on tenant hosts | On `qa-audit.ethr.et`, send `X-Tenant: uat-iso-…` | Still QA Audit; the header is honoured only on the apex |
| TEN-013 | P2 | W | Organisation chooses its own address | On a `uat-` organisation, TA: Settings → address: switch between the path form and the subdomain form (and a custom domain if the plan carries the add-on; verify with `POST /settings/address/verify`) | Saved; emails, the entry page and sign-in links use the new canonical address, and the old form redirects to it. The same rules as the console's domain endpoints (CON-P-006, CON-P-007). This is the address *form* (`PUT /settings/address`); renaming the name itself is TEN-015 |
| TEN-014 | P2 | W | Organisation details | TA: Settings (organisation and general sections): name, Amharic name, contact details, and, where offered, fiscal year start (Hamle 1 or Meskerem 1) and the working week | Saved, audited; the header, sign-in page and leave day counting follow (working week change affects LR-002) |
| TEN-015 | P1 | W | Rename the address | `uat-` organisation only (a rename reserves the old name for that organisation for good). TA: Settings → Address → "Address name" → "Change name"; type `<slug>-b`, wait for "Available" → "Continue"; read the dialog → "Change address". Routes: `GET /settings/address/subdomain/check?subdomain=`, `PUT /settings/address/subdomain`, `settings.manage` (tenant admin only), 5 renames an hour per organisation | Dialog "Change your address to …?": ":old keeps working and forwards to :new. The old name stays reserved for your organisation." 200; "Address changed to …". The card lists "Earlier names that still forward here: `<slug>`". Audit "Organisation address renamed" (`settings.subdomain_changed`) with both names. Emails sent afterwards use the new name |
| TEN-016 | P1 | R | Old name keeps working | After TEN-015: `https://www.ethr.et/<old>`; with subdomains on, `https://<old>.ethr.et/login`; `GET /api/v1/auth/me` with `X-Tenant: <old>` | Both pages answer **302** (not 301) to the current address (the subdomain to the new host's `/login`). The API answers as the same organisation, not 404 `tenant-not-found` |
| TEN-017 | P1 | R | Name rules | Check, then save: `ab`, `-qa`, `qa_1`, `www`, `dashboard`, `admin`, ` QA-Test-9 ` (capitals, spaces around) | The check always returns 200 with `available` and `message`. `ab`: "The subdomain field must be at least 3 characters." (screen: "Use at least 3 characters."). `-qa`, `qa_1`: "Use lower-case letters, numbers and hyphens, starting and ending with a letter or number." Reserved: "That name is reserved by ETHR. Please choose another." The last is trimmed and lower-cased, then judged |
| TEN-018 | P1 | R | Names held by others | Rename to `qa-audit`, to a deleted organisation's name, to a name another organisation retired | Each: "That name is already in use. Please choose another." |
| TEN-019 | P1 | R | What another organisation sees | Type the name retired in TEN-015 as the slug on `/register` and submit; from QA Isolation, Settings → Address, rename to it | Registration's live check says unavailable; submit 422 on `subdomain`, "The subdomain has already been taken." The rename: "That name is already in use. Please choose another." |
| TEN-020 | P2 | W | Take a name back | Rename the TEN-015 organisation back to its original name | Allowed. The retired list now shows `<slug>-b`, not the original; both names reach the organisation |
| TEN-021 | P1 | R | Only tenant admins; no change | HR opens Settings → Address and calls both routes. TA renames to the current name | HR: no "Change name"; both routes 403. Same name: 200, nothing retired, nothing audited |
| TEN-022 | P2 | R | Sessions and SSO after a rename | Subdomain-mode organisation: one user signed in at `<old>.ethr.et` and another at `www.ethr.et/<slug>` during TEN-015; open `/api/v1/sso/saml/<old>/metadata` | The user on the old subdomain signs in again at the new host; the one on the shared address stays signed in. The dialog warned: "If you sign in with SSO, give your identity provider the new addresses shown in the SSO settings." The old SAML path still reaches the organisation |
| TEN-023 | P3 | R | Rename rate | 5 `PUT /settings/address/subdomain` with a reserved name (each 422), then a sixth | Sixth: 429 with `Retry-After`. Failed attempts count, so no name is retired by this test |

## 8. Impersonation (platform operator)

Only in QA Audit or a `uat-` organisation.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| IMP-001 | P1 | W | Start | PA: console → tenant → Impersonate, MFA code | A new tab or redirect on the organisation's address, signed in as its first `tenant_admin`; a banner shows impersonation |
| IMP-002 | P1 | R | MFA needed | PA without MFA, or a wrong code | 409 `mfa-required`, or 422 |
| IMP-003 | P1 | R | Hand-off link is single use | Copy the hand-off address and open it a second time, or after 30 seconds | 422 `invalid-handoff`, "This sign-in link is no longer valid. Start the impersonation again." |
| IMP-004 | P1 | R | Blocked actions | While impersonating: delete anything, change a password, set up or disable MFA, create an API key, change the plan | Each 403 `impersonation-restricted`, "This action is not allowed while impersonating a tenant." |
| IMP-005 | P1 | R | Recorded | After impersonating, check the platform audit log and the organisation's audit log | The impersonation and every action taken are recorded with the impersonator |
| IMP-006 | P1 | W | Exit | Click exit | Back in the console; an ordinary session calling `POST /auth/impersonation/exit` gets 403 `not-impersonating` |
| IMP-007 | P2 | R | Expiry | Leave the impersonated session for 31 minutes | Ended |

## 9. SAML single sign-on

Needs an identity provider the test team controls (for example a free developer tenant of a SAML
IdP). Skip and mark Blocked if none is available.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| SSO-001 | P2 | R | Not configured | `GET /sso/saml/qa-audit/initiate` before configuration | 422 `sso-not-configured`, "Single sign-on is not configured for this organization." |
| SSO-002 | P2 | W | Configure | TA: Settings → SSO, IdP SSO URL, default role `employee`, auto-provision off | Saved; `/sso/saml/qa-audit/metadata` returns XML metadata |
| SSO-003 | P2 | R | Sign in | Start SSO from the sign-in page, authenticate at the IdP as an existing user | Returned to `/login/sso?org=qa-audit` and on to the dashboard (or to MFA when owed) |
| SSO-004 | P2 | R | Errors | IdP user with no ETHR account (auto-provision off); deactivated user; suspended organisation | `/login/sso?error=no_account`, `account_inactive`, `tenant_inactive`; each explained on the page |
| SSO-005 | P3 | R | Open redirect | Start SSO with a `RelayState` / `next` pointing at an outside site | Lands on `/dashboard`, never the outside site |

---

## 10. Roles, permissions and reach

The full matrix is `docs/PERMISSIONS.md`. Run every case through the interface **and** through the
API (the interface hiding a button is not access control).

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| ACC-001 | P1 | R | Navigation per role | Sign in as each persona; list the sidebar sections | EMP: Workspace (Dashboard, My Attendance, Leave, My Payslips), Directory, Announcements, Voice. SUP and DA add Approvals, Employees, Team Attendance. HR adds People, Operations, Settings. FIN adds Finance (Payroll, Loans, Cost Sharing), Reports. TA sees all tenant sections plus Billing. Nobody but PA sees Platform. Each row appears only for someone holding the ability its page's API checks; the reference is `src/src/lib/navigation/registry.ts` |
| ACC-002 | P1 | R | Employee cannot read others | As EMP: `GET /employees`, `GET /employees/{EMP2 id}`, `GET /payroll/runs`, `GET /attendance?…` for all | 403 (or only EMP's own record where the endpoint is self-scoped) |
| ACC-003 | P1 | R | Supervisor reach | As SUP: open EMP and EMP2 (direct reports) and EMP3 (not a report) | EMP and EMP2 open; EMP3 gives 403 or 404 |
| ACC-004 | P1 | R | Department admin reach | As DA (D1): open an employee in D1 and one in D2 | D1 opens; D2 refused |
| ACC-005 | P1 | R | Supervisor has no reports | As SUP: open Reports, call `POST /reports/generate` | Not in the menu; 403 |
| ACC-006 | P1 | R | Separation of duties | As HR: `POST /payroll/process`. As FIN: change an employee's bank details. As FIN: approve a run | All three refused (403). Only TA approves, voids and reprocesses payroll |
| ACC-007 | P1 | R | Devices are tenant-admin only | As HR: register a device | Refused (403); HR can view devices |
| ACC-008 | P1 | R | Financial fields hidden | As SUP open EMP; as HR open EMP | SUP sees no salary; HR sees salary. Nobody sees TIN or national ID in any API response (they are never returned) |
| ACC-009 | P1 | W | Custom role | TA: Settings → Roles: create "QA Viewer" with `employee.viewAny`, `employee.view`, reach `department`; assign to EMP2 | EMP2 sees only D2 employees and has **only** those permissions (the custom role replaces the built-in role, so EMP2 also loses what an employee normally has, such as `leave.request`, unless included) |
| ACC-010 | P1 | R | Custom role cannot carry platform power | Try to give a custom role `admin.manage` (API) | Refused; it is not in the catalogue |
| ACC-011 | P1 | R | Cannot grant more than you hold | HR assigns a custom role with `settings.manage` or `payroll.approve` to anyone, including themselves | Refused |
| ACC-012 | P2 | W | Delete a role in use | Delete "QA Viewer" while EMP2 holds it; reassign EMP2; delete again | 422 first; then deleted |
| ACC-013 | P2 | W | Changes apply at once | Remove a permission from "QA Viewer"; EMP2 reloads | Access gone on the next request (no 1-hour cache delay) |
| ACC-014 | P1 | R | Cannot act on a higher role | HR edits, deletes or resets MFA for TA | 403 |
| ACC-015 | P1 | R | Cannot delete self | TA deletes their own user | 422 |
| ACC-016 | P2 | R | Error shape | Any 403 above | Problem JSON with `type`, `title`, `status: 403`, `detail`; no stack trace, no internal ids |

## 11. Plans, limits and billing

Run on the QA Isolation organisation. PA changes its plan and status on request. A **trial**
organisation has every feature and no limits, so these cases need it out of trial.

| ID | P | Mode | Scenario | Steps | Expected |
|---|---|---|---|---|---|
| PLAN-001 | P1 | R | Starter gates | QA Isolation on Starter (out of trial): process payroll, generate a report, create an API key, create a webhook, read audit logs | Each 403 "Your plan does not include :feature. Upgrade to use it." with the feature named |
| PLAN-002 | P1 | R | Reads stay open after downgrade | Move from Professional (with a run already processed) to Starter | Old payroll runs, payslips and bank files still readable; only new processing refused. API keys and webhooks can still be listed and deleted |
| PLAN-003 | P1 | W | Employee limit | Starter allows 10 employees: add the 11th | 403 "You have reached the employee limit for your plan." Same for branches (1) and devices (2). The import checks the whole batch before creating anything |
| PLAN-004 | P1 | R | Professional | On Professional: payroll and reports allowed; API keys, webhooks, saved and scheduled reports, audit log refused | As stated |
| PLAN-005 | P1 | R | Enterprise | On Enterprise: everything allowed | As stated |
| PLAN-006 | P2 | W | Change plan | TA: Billing → change plan (needs `billing.manage`) | Applies at once; the billing dashboard shows the new plan; an inactive plan is 404; not allowed while impersonated |
| PLAN-007 | P1 | W | Upgrade during trial | A trial organisation upgrades to Professional, then its trial end passes (PA can shorten it in a `uat-` organisation if the console allows; otherwise review the status) | The organisation must stay usable. **Suspected defect to verify:** the code may leave the organisation in `trial` after the upgrade, so it is treated as inactive (403 `tenant-inactive`) when the trial date passes. Record the actual status after the upgrade |
| PLAN-008 | P2 | R | Trial days | Billing dashboard of a trial organisation | `trial_days_remaining` correct |
| PLAN-009 | P2 | X | Invoices | On a paid plan, after the monthly run (1st of the month 03:00 UTC): an invoice `sent`, due in 15 days. Free plans get no invoice | As stated |
| PLAN-010 | P2 | R | Receipt | Billing → invoice → receipt | Downloads `receipt-{id}.pdf` |
| PLAN-011 | P2 | R | Dunning (observe only) | For a test invoice left unpaid: 7 days overdue, 30 days past due (subscription past due), 60 days suspended | Observe on a `uat-` organisation only; do not wait 60 days on QA Audit |
| PLAN-012 | P1 | W | Mark paid | PA marks a test invoice paid twice | Paid; the second call changes nothing |
| PLAN-013 | P2 | R | Pricing page | Public `/en/pricing` and `/am/pricing` | Plans and prices match the console catalogue |
