# Mass activation: one starting password for a batch of employees

> **OWNER DECIDED, 2026-11-10** (plan "One employee identity", decision 1, PR 10 of 11). HR may
> give a batch of employees who have no email **one starting password**, made by ETHR. Each
> person signs in with their **employee code** and that password and must **choose their own
> before anything else**. Unused after **72 hours**, the account goes back to `invited`.
> The owner chose this knowing its weakness, stated under *The risk that was accepted*.

This record covers the starting password only. The other two parts of the same decision (a
login for everyone by default, and migrated staff arriving as Confirmed) and the printable code
sheet belong to PR 9 and are not built here.

## What forced it

Activation was one person at a time. An employee with no email is activated by an in-person
code ([`OWNER-DECISION-IN-PERSON-ACTIVATION.md`](OWNER-DECISION-IN-PERSON-ACTIVATION.md)), which
HR issues from each profile after checking a document. That is the right strength for one
person and does not scale to a factory floor of several hundred brought in from a device or a
spreadsheet in one afternoon.

## The design

`POST /api/v1/employees/starting-password` (`StartingPasswordController`,
`App\Services\Auth\StartingPasswords`). A sibling of `POST /employees/logins`, not a mode of
it: it needs a different permission, a step-up check and a response that must never be cached.

| Property | How |
|---|---|
| HR never types it | ETHR generates it: at least 12 characters, a capital, a small letter and a digit always, a symbol when the organisation's `PasswordPolicy` asks, longer when the policy's minimum is longer. No `I`, `L`, `O`, `0` or `1`, because it is read off paper and typed on a phone |
| Shown once | In the issue response only, `Cache-Control: no-store`. Only the hash is stored. It is not in the audit row, a log, a queue, mail or SMS. HR can only issue another |
| Hashed once | One bcrypt for the batch, shared by every account in it. 200 hashes at cost 12 do not fit in the host's 30 seconds |
| Batch size | At most 200 people in one request |
| Forced change | `users.password_change_required_at` is set. `RequirePasswordChange` refuses every authenticated request with 403 `password-change-required` except `POST /auth/password/change`, `POST /auth/logout` and `POST /auth/refresh` |
| Expiry | `users.starting_password_expires_at`, 72 hours from issue |
| Audit | One `user.starting_password_issued` record for the batch: how many, who (public ids), until when, how many were left out and why. Never the password |

### Guards

- **Off until HR chooses it for a batch.** Nothing issues one by default. It needs the new
  ability `users.issueStartingPassword`, granted to `hr_admin` and inherited by `tenant_admin`.
- **HR proves it is them.** Their own password, and their authenticator code when they have
  two-factor on (the check `IssueActivationCodeRequest` already made, now shared in
  `ConfirmsItIsThem`). Permission is checked before the password is looked at.
- **Employee role only.** A login above the employee role, or one carrying a custom role (which
  can hold any permission), is never touched.
- **Never an account that is already active.** Only a person with no login, or an invited login
  that was never used.
- **Never someone an invitation can reach.** A person with an email, or a phone once text
  invitations are on, is left for the ordinary invitation. The rule is the in-person code's
  (`ActivationCodeService::reachable()`).
- **Never a leaver, and never a suspended or deactivated login.**
- **Refused outright when the organisation does not sign in by employee code** (422
  `starting-password-refused`, reason `identifier`). The default is email only, and these
  people have none.
- **Someone left out is left exactly as they were** and counted in `skipped` (`active`, `role`,
  `closed`, `reachable`). When nobody in the batch qualifies the answer is 422, reason
  `nobody`, and no password is made.
- **Another organisation's employee, or one outside the caller's reach, is answered exactly as
  an id that matches nobody.**

### The hold

Modelled on `RequireTenantMfaEnrolment`, and placed before it, so a person in an organisation
that requires two-factor chooses a password first and is asked to enrol second. The
change-password endpoint is on both lists.

- `GET /auth/me` is **not** open while held. The change screen (`/login/change-password`)
  stands outside the app shell and needs nothing from it. The sign-in response carries
  `password_change_required`, and any other request's 403 sends the browser to that screen
  (`passwordChangeRedirect` in `src/src/api/client.ts`).
- The new password may not equal the starting one.
- An impersonation session is not held, for the reason the MFA gate gives.
- All three password writers clear both columns: `PasswordResetController::change`, `::reset`
  and `ActivationCodeService::redeem`.

### Expiry: decided as "at use, and in the daily cleanup"

An expired starting password is dealt with in three places, all calling
`StartingPasswords::expire()` or its bulk twin:

1. **At sign-in.** `LoginController` answers 403 `starting-password-expired`, only after the
   password matched, and puts the account back to `invited` with a password nobody knows.
2. **On any request from a session opened before the expiry** (`RequirePasswordChange`). The
   change itself is refused too: an expired starting password is not good for anything.
3. **In `CleanupExpiredDataJob`, daily at 02:00**, for accounts nobody tried. Without this an
   unused account would sit `active` with a known password until someone happened to try it.

Back at `invited`, the person can be invited, given an in-person code, or included in a new
batch.

### The spray limit

Everyone in a batch shares one password, so a guess that is wrong for one account is wrong for
all of them. Tried once per account it never reaches the per-identifier lockout (10 tries on
one identifier from one address). `StartingPasswordSprayLimit` counts failed sign-ins against
accounts that still have a starting password, per organisation, whatever the identifier or
address:

- **20 failures in 15 minutes** start a **15-minute cooling period** for those accounts.
- The organisation's `tenant_admin` and `hr_admin` users are told **once** per cooling period,
  and `user.starting_password_cooldown` is audited.
- Accounts with a password of their own are neither counted nor held back.
- **While cooling, a sign-in is answered exactly as a wrong password is**, even with the right
  one. A distinct answer (a 429, say) would tell a guesser which employee codes still have a
  starting password, for free, and those are exactly the accounts worth guessing at.

## The risk that was accepted

**Until a person first signs in, a colleague who knows the batch password and that person's
employee code can sign in as them and choose the password.** Everyone in the batch knows the
password, and employee codes are not secret. The guards narrow this; they do not close it:

- the window is at most 72 hours, and ends for each person the moment they sign in;
- only employee-role accounts with no prior use can be claimed, so what is exposed is one
  person's own records (their payslips, leave, attendance and profile), never an administrator;
- the forced change means the real person finds out at their own first sign-in, which fails,
  and HR can then recover the account with an in-person code.

The in-person code has no such window and stays the default the screen describes; the dialog
states this risk in plain words before HR confirms.

## What it costs

- **A wrong claim is detected, not prevented.** There is no step where the employee proves who
  they are.
- **The limit can be used against the organisation.** Anyone who can guess employee codes can
  put first sign-ins into the cooling period, 15 minutes at a time. It does not touch anyone
  who has chosen a password. A large batch of honest typos (20 in 15 minutes) has the same
  effect.
- **A person held by the cooling period sees "wrong credentials"**, not an explanation. That is
  the price of not naming the accounts; the administrators are told why.
- **Two nullable columns on `users`**, no backfill. No tenant-scope bypass was added: the
  inventory stays at 202 sites across 80 files. The daily sweep uses the query builder across
  every organisation by intent, like the rest of `CleanupExpiredDataJob`.
- **The sign-in response gained `password_change_required`** (always present). `POST
  /auth/login` otherwise answers as before.

## Not done, on purpose

- Choosing the password, or its lifetime, per organisation. One fixed rule is easier to reason
  about than a setting that can be made weaker.
- A list for HR of who has not yet changed their password. The users list shows such an
  account as active; the audit record names the batch.
- Offering to switch on employee-code sign-in from the dialog. It says to turn it on in
  Settings.
- Issuing at migration commit (PR 9 of the plan decides the commit-time choices).

## Deploying

`php artisan migrate` (one migration, two nullable columns), `db:seed --class=PermissionSeeder`
for the new ability, and the usual cache clear. Nothing is mailed by issuing; the cooling-period
alert is a notification to administrators and is sent inline, as the lockout alert is.
