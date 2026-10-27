# In-person activation for employees with no email and no phone

> **OWNER DECIDED, 2026-10-26.** An employee who has neither an email address nor a phone
> number is activated **face to face by HR** with a one-time code, shown as a QR and as typed
> characters. `users.email` becomes nullable (no placeholder addresses). After activation the
> employee signs in with **employee code + password**; a lost password is recovered the same
> way, with a fresh code. No passkey in this change.

## What forced it

Every route into an account ended at an inbox or a handset:

| Step | Where it needed an email |
|---|---|
| Create the account | `UserProvisioningService::provision()` took a required email; `users.email` was NOT NULL |
| Send the link | `account_activation_tokens` is keyed by email |
| Redeem it | `PasswordResetController::reset` looked the user up by email |
| Sign in by code | `OtpController` needs a phone or an email on the user |

`EmployeeController::store` skipped such an employee without saying so
(`create_login && !empty($employee->email)`), so the person never got a login.

## The design

Code: 12 Crockford base32 characters (60 bits), shown `XXXX-XXXX-XXXX`. The QR encodes
`/login/activate?tenant=<slug>#c=<code>&e=<employee code>`; the code is in the **fragment**, so
no server, proxy or Referer header ever sees it. The page reads it once and removes it from the
address bar.

| Property | How |
|---|---|
| Single use | One conditional `UPDATE ... WHERE consumed_at IS NULL AND revoked_at IS NULL AND expires_at > now()`; proceeds only when 1 row changed (the pattern of `DeviceChallenges::consume`) |
| Hashed at rest | `hash_hmac('sha256', code, APP_KEY)`. Rotating `APP_KEY` voids outstanding codes, which is the safe direction |
| Shown once | In the issue response only, `Cache-Control: no-store`. Not in audit rows, logs, queues, mail or SMS. HR can only re-issue |
| Short life, one live code | 24 hours by default, tenant setting `activation_code_hours` 1 to 72, ceiling 72. Issuing withdraws the earlier code |
| Bound | Redemption needs the employee code **and** the code, in the resolved tenant; the lookup states `tenant_id`. No new `withoutGlobalScope` on the redeem path |
| Attempts | The code locks (is revoked) after 5 wrong tries; `activation` limiter 20/min per address and 10/min per organisation and employee code |
| No oracle | Unknown, expired, used, withdrawn and wrong-employee-code all answer one identical 422 |
| Employee chooses the password | HR never sets or sees it. `PasswordPolicy` applies, and it may not equal the employee code |
| No auto sign-in | Redemption activates, deletes every Sanctum token, and sends the employee to normal sign-in. MFA is never cleared by recovery |

Issuing (`POST /employees/{employee}/activation-code`, ability `users.issueActivationCode`,
granted to `hr_admin`, inherited by `tenant_admin`):

- HR ticks "I checked this person's identity in person", names the document (national ID,
  kebele ID, passport, driving licence, employer ID; **never its number**) and re-enters their
  password, plus their authenticator code if they have one.
- Refused: for oneself; for an account at or above the issuer's level, or any administrator;
  for an employee who has an email or a phone (the normal invitation or reset reaches them);
  and when the employee would have no way to sign in (the organisation's `login_identifiers`
  has neither `employee_code` nor a `username` for them).
- Creating an employee with `create_login` and no email or phone now provisions the no-contact
  account (`meta.activation_code_pending`) but **mints no code**, so a code cannot exist without
  HR in front of the person.

Audit: `user.provisioned_no_contact`, `user.activation_code_issued`, `_revoked`, `_redeemed`,
`_failed`, `_locked`, `user.activated`. A scheduled `auth:purge-activation-codes` deletes rows a
month after they expire.

## What it costs

- **`users.email` is nullable.** Anything that reads `$user->email` must tolerate NULL.
  `User::name()`, `OtpService`, `MfaService`, SCIM and the profile page were fixed; the next
  consumer to be written has to do the same. Both MariaDB and SQLite allow repeated NULLs in
  `unique(tenant_id, email)`.
- **The migration rebuilds three generated columns.** On SQLite, changing `email` rebuilds the
  `users` table, and the rebuild turned `phone_normalized` into a plain column (phone sign-in
  stopped matching, caught by `LoginIdentifierTest`). The migration drops `email_normalized`,
  `username_normalized` and `phone_normalized` and their indexes first and restores them with
  the same expressions. `down()` refuses while a no-email user exists.
- **Recovery needs a person.** There is no self-service route for these accounts, by design.
  Lost password means HR issues a new code in person.
- **One bypass added** for the purge sweep (174/68 once merged after the address rename; 172/68 on its own). Redemption itself adds none.

## Not done, on purpose

- A printable slip. A printed code is a persisting secret; the dialog says not to photograph or
  save the screen instead.
- Passkey enrolment at activation, dual approval for recovering privileged accounts, and bulk
  issuing from import.
- Telling HR by notification when a code locks. The status endpoint reports `locked`, and the
  audit log records it.
- A contact added later (rule 19 of the working plan): an email or phone added to such an
  account is not yet treated as unverified for password reset. Until that is built, changing
  contact details on these accounts should stay with HR.

## Deploying

`php artisan migrate` (two migrations), `db:seed --class=PermissionSeeder` for the new ability,
and the usual cache clear. Nothing is mailed, so no queue worker is involved.
