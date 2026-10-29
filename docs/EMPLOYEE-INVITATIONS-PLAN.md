# Bringing device staff into ETHR and inviting them

**Plan, 2026-10-28.** Read from source on `main` at `96bba0e6f`.

**Status, 2026-10-28:** PR A is built on `feat/queued-invitations`, together with the parts of
PR B that the owner asked to have ready for later: the text channel, its console switch (off),
the invitations' share of the SMS cap, and activation by sign-in code. While the switch is off,
people with only a phone get an in-person code, which a phone used to block.

PR C is built on `feat/invite-after-migration`, stacked on PR A. Point 3 reuses
`MigrationStep` on a page of its own (`/employees/from-device`) rather than moving it, and
point 4 is the owner's warning. PR D is built on `feat/adms-enrolled-users`, from `main`.
The owner said to build it without a real terminal (2026-10-28), so it accepts both the
PUSH 2.x and 3.x formats, falls back from one to the other, and its fixtures follow the
published protocols. The first real terminal still has to be compared against them
(`DEVICE_INTEGRATION.md`).

The goal: an organisation that already has a ZKTeco, Hikvision or Suprema terminal with its
staff enrolled gets those people into ETHR as employees, and every one of them receives a way
to sign in, without HR typing each person and without one slow request doing the sending.

Three gaps stand in the way. They ship as four PRs, in this order, because each one needs the
one before it:

| PR | Gap | Needs a real device | Migration |
|---|---|---|---|
| A | Invitations are sent inline, one SMTP conversation per row | No | Yes |
| B | Phone-only and no-contact employees have no invitation channel | No | Yes (in A) |
| C | A device migration creates employees and invites nobody | No | No |
| D | ZKTeco ADMS devices cannot list who is enrolled on them | **Yes** | Yes |

---

## What exists today

| Piece | Where | State |
|---|---|---|
| Read who is enrolled on a device, matched against employees | `DeviceEnrollmentController::index`, `IdentityResolver` | Complete for ZKTeco pull, Hikvision, Suprema, generic HTTP, mock. **Empty for ADMS** |
| Stage those people, choose create / merge / skip, commit | `WorkforceMigrationService`, `/migration/*` routes | Complete. UI only in the onboarding wizard (`features/onboarding/v2`); the devices page has a read-only enrollments dialog |
| Push employees to terminals | `DeviceCommandQueue::enrol`, `sendStaff`, `employeeSaved` | Complete (ADMS and pull adapters that take commands) |
| Hold punches from unknown badges, replay once assigned | `UnmatchedPunches` | Complete |
| Create a login in `invited` status and email a 72-hour activation link | `UserProvisioningService::provision`, `sendActivationLink` | Complete, **sent inline** through `CriticalMail` |
| Resend one invitation | `POST /users/{user}/resend-invite`, gate `users.invite` | Complete, one user at a time |
| Activate in person with a one-time code | `ActivationCodeService`, `provisionWithoutContact` | Complete |
| Bulk employee import that also creates logins | `EmployeeImporter::import` | Creates logins for rows with an email and sends each email **inside the request** |

Constraints this plan is shaped by, all read from the code:

- **No worker.** The queue is drained by `CronRunController::queue` for at most
  `cron.queue_max_seconds` (25 s) per call, every 5 minutes from two callers, plus
  `RunBackgroundWorkAfterResponse` after ordinary traffic. The queues are
  `QueueHealth::QUEUES`: `default`, `attendance`, `notifications`, `exports`.
- **30-second PHP limit** on the host (stock Plesk `max_execution_time`; the shipped `.user.ini` is not applied there). An import of 300 rows with an
  email each runs 300 SMTP conversations in one request today.
- **Activation tokens are keyed by email.** `account_activation_tokens.email` is the primary
  key, so a person with no email cannot receive an activation *link* at all.
- **The SMS daily cap is platform-wide**, not per organisation (`ConfiguredSmsSender`, default
  500). One organisation inviting 300 people by SMS would use most of the day's sign-in codes
  for every organisation.
- **Sign-in by SMS code refuses non-active users** (`OtpController`: `status !== 'active'`
  answers 403).
- **ADMS acknowledges and discards** every upload that is not `table=ATTLOG`
  (`AdmsController::upload`).

---

## PR A: queued, throttled, tracked invitations

### Backend

1. **Table `invitation_sends`** (tenant-scoped, `BelongsToTenant`, ULID `public_id`):
   `tenant_id`, `user_id`, `batch` (ULID, nullable), `channel` (`email` / `sms` / `in_person`),
   `status` (`queued` / `sent` / `failed` / `skipped`), `attempts`, `error_code` (short code
   only, never an exception message), `queued_by`, `queued_at`, `sent_at`.
   Indexes `(tenant_id, batch)` and `(tenant_id, user_id, queued_at)`. Name them explicitly and
   keep each name under 64 characters; run the migration on scratch MariaDB before pushing
   (SQLite does not enforce the limit).
2. **`App\Services\Invitations\InvitationDispatcher`**, the one place invitations are sent:
   - `sendNow(User $user, ?User $by): InvitationSend`. For a single invite from the employee
     form or the resend button, where someone may be waiting. Keeps today's `CriticalMail`
     behaviour and records the row.
   - `queue(iterable $users, ?User $by): string`. Returns the batch id. Skips users who are
     not `invited`, and users with a row `queued` in the last 10 minutes (so a double click,
     or "resend to everyone" pressed twice, sends once). Writes rows, dispatches one job per
     row.
3. **`SendInvitationJob`** on `notifications`:
   - Constructor `public readonly int $tenantId, public readonly int $invitationSendId`,
     **required, not nullable**. Add it to `QueuedJobTenantPredicateTest`.
   - `handle()` sets `CurrentTenant`, loads the row with `where('tenant_id', $this->tenantId)`
     on the same chain, stops if the user has activated meanwhile (`skipped`).
   - **Creates the activation token inside `handle()`**, so the jobs table never holds one and
     the job does not need `ShouldBeEncrypted`.
   - Calls `notifyNow` directly, not `CriticalMail`: the job is already the retry.
     `$tries = 4`, backoff `[60, 300, 1800]`, `$timeout = 60` (under the queue's 90 s
     `retry_after`). `failed()` marks the row `failed` with an `error_code`.
   - **Throttle per organisation** with `RateLimiter` (`invitations:{tenant_id}`,
     `config('invitations.per_minute', 30)`): over the limit, `release()` with the limiter's
     wait. This keeps one large organisation from filling every drain window.
4. **Callers change:**
   - `EmployeeImporter`: `provision(..., sendActivation: false)`, collect the users, one
     `queue()` at the end. Return `invitations_batch` in the result.
   - `EmployeeController::store` and `UserController::resendInvite`: `sendNow()`.
5. **Endpoints**, behind `users.invite`:
   - `POST /users/invitations`: body is either `user_ids[]` (public ids) or `scope=not_activated`.
     Answers 202 `{ batch, queued, skipped }`.
   - `GET /users/invitations/{batch}`: counts by status, plus the failed rows (name, channel,
     error code). Another organisation's batch is 404.
   - `UserResource` gains `last_invitation { channel, status, at }`.
   - No comment directly above any key in these response arrays, or above the `return`
     (Scramble publishes them). Regenerate `src/src/api/generated.ts`.
6. **Audit:** `invitation.batch_queued` with the count, once per batch rather than per row.

### Frontend

- Import result: "312 employees added. Invitations are being sent: 0 of 280." A card that
  polls the batch every 15 s while anything is `queued`, then shows sent / failed with
  **Retry failed**.
- Users list: a **Not activated** filter and a bulk **Resend invitation** action.
- Copy follows `copy-style.test.ts` (no dashes as punctuation, no emoji, no exclamation
  marks), en and am.
- State plainly that sending takes time: at 30 per minute, 300 invitations take about
  10 minutes when traffic is steady, and up to about an hour when only the 5-minute cron
  is draining.

### Tests (Pest)

- Import of 50 rows sends nothing during the request (`Notification::fake()`, assert nothing
  sent), queues 50 jobs, and a drain sends 50.
- The job states `tenant_id`; a job for tenant A cannot load tenant B's row.
- An activated user's queued invite becomes `skipped`, and no email goes out.
- Queueing the same user twice within 10 minutes sends once.
- Over the throttle, the job is released, not failed.
- Batch endpoint: counts are right; another tenant's batch is 404; `assertJsonMissingPath('id')`.
- `TenantIsolationTest` covers the new model automatically; confirm it collects it.

---

## PR B: an invitation for everyone, whatever contact they have

The channel is chosen per person, in this order:

| Person has | Channel | What they receive |
|---|---|---|
| Email | `email` | Activation link, as today |
| Phone only | `sms` | "Your organisation added you to ETHR. Sign in at ethr.et/{slug} with this phone number." **No link and no token in the text** |
| Neither | `in_person` | Nothing is sent. The person is listed for HR to issue an in-person code |

What the phone-only row needs:

1. **`UserProvisioningService::provisionWithPhone(Employee $employee, ...)`**: a user with
   null email and the employee's canonical phone, `invited`. (The email-keyed token table is
   why this is not a link.)
2. **Let an `invited` user with a phone sign in by SMS code.** `OtpController` refuses any
   status other than `active`. The change: an `invited` user whose phone matches may complete
   a code sign-in, which sets them `active` and sends them to choose a password, the same
   screen activation ends on. The code proves they hold the phone, which is the same proof
   the activation link gives for an email. **This is a change to sign-in rules and needs
   owner approval** (decision 2 below).
3. **SMS budget for invitations.** Invitations may use at most
   `config('invitations.sms_share', 0.3)` of the platform's daily cap, counted from
   `invitation_sends` (channel `sms`, sent today). Over budget, the row stays `queued` and the
   job releases until the next day. Sign-in codes keep the rest. The SMS ledger stays free of
   numbers and text.
4. **Throttle SMS more slowly** than email (for example 10 per minute per organisation).

Tests: channel choice for each of the three cases; an `invited` phone-only user completes a
code sign-in and becomes `active`; an `invited` user with an email still cannot use a code
before activating (unchanged); the SMS share is respected; a `suspended` user is still
refused.

---

## PR C: invite after a device migration

1. **The commit response lists who still cannot sign in:** after
   `WorkforceMigrationService::commit`, return `without_login` (employee public ids, name,
   whether a phone or email is on file).
2. **A follow-up step, "Give them a way to sign in"**, in the migration UI:
   - A table of the new employees with an editable phone and email field per row (saved
     through the existing employee update) and the channel each will get.
   - **Send invitations** posts to a new `POST /employees/logins` (`users.invite` plus
     `employee.update`): it provisions a login for each employee (email, phone or no-contact,
     as in PR B), with nothing sent inline, then calls `InvitationDispatcher::queue`. No SMTP
     in the request, so 500 rows fit well inside 30 s.
   - Nothing is sent at commit time. HR confirms the list first, because names read off a
     terminal are often wrong ("Abebe 2", "guest card", "test").
3. **Reach the migration outside onboarding.** The devices page enrollments dialog
   (`device-workforce-dialogs.tsx`) gains **Bring these people into ETHR**, which stages a
   batch (`POST /migration/devices/{device}`) and opens the same review components the
   onboarding wizard uses, moved to a shared location rather than copied.
4. **Hire date.** `createEmployee` sets `status = hired` and `hire_date = today` for everyone
   it creates. For people who have worked there for years both are wrong and feed leave
   accrual and severance. The review table gains a hire date column (blank means unknown) and
   a status choice. Decision 3 below.

Tests: commit returns `without_login` correctly; `POST /employees/logins` provisions each
channel and queues once; an employee who already has a login is skipped; another tenant's
employee id is 422 with the generic message (the `ValidatesRelationTenancy` rule).

---

## PR D: ADMS devices report who is enrolled

ADMS terminals call ETHR; ETHR cannot call them. So the list is requested with a command and
arrives later.

1. **`DeviceCommandType::QUERY_USERS`**, written by `AdmsProtocol::command` as
   `C:{id}:DATA QUERY USERINFO`. Queued by a new `DeviceCommandQueue::requestEnrollments()`.
2. **Accept the answer.** In `AdmsController::upload`, `table=OPERLOG` (and `table=USERINFO`,
   which some firmware uses) lines starting `USER PIN=` are parsed by a new
   `AdmsProtocol::userLines()`: `PIN`, `Name`, `Card`, `Pri`. Everything else stays
   acknowledged and discarded, as now.
   - **Never stored:** the `Passwd` field, and every `FP`, `FACE` and `BIODATA` line
     (fingerprint and face templates are biometric data under PDPP 1321/2024). Only a count of
     fingerprints per PIN is kept.
   - The acknowledgement count still includes every line, so the device does not resend.
3. **Table `device_enrollment_snapshots`** (tenant-scoped): `tenant_id`, `device_id`,
   `device_user_id`, `name`, `card_number`, `is_admin`, `fingerprint_count`, `seen_at`;
   unique `(device_id, device_user_id)`. Upserted as lines arrive. The tenant is already set
   by `AdmsDeviceResolver` before the upload is handled, so no new scope bypass is needed;
   if one appears, the inventory test will say so.
4. **`ZktecoAdmsAdapter::pullEnrollments`** reads the snapshot instead of returning `[]`, so
   `DeviceEnrollmentController` and `WorkforceMigrationService::stageFromDevice` work
   unchanged.
5. **UI:** for an ADMS device the enrollments dialog says when the list was last received and
   offers **Ask the device again**. After asking, it polls until `seen_at` moves. The device
   answers at its next check-in, usually within a minute.
6. **No completeness signal.** The firmware does not say "that was everyone". Show the count
   and the time, and do not claim the list is complete.

Tests: protocol parsing from recorded fixtures (tabs, empty names, Amharic names, a `Passwd`
value that must not survive, `FP` lines that must not be stored); upsert; another tenant's
device cannot write a snapshot; the command is written and acknowledged.

**A real terminal is required before merge.** Each firmware formats these lines slightly
differently, and newer Push 3.x firmware may use `/iclock/querydata` instead. Capture what
the test device actually sends into the fixtures and into the ADMS section of
`DEVICE_INTEGRATION.md`. If it uses `querydata`, that route is an addition to this PR, not a
guess made now.

---

## Owner decisions (2026-10-28)

1. **Automatic reminders: yes.** A daily `ethr:invitation-reminders` queues another
   invitation for anyone still `invited` 3 days after the last one, at most twice. Built in
   PR A.
2. **SMS invitations: build now, switch on later.** SMS for ETHR is not live yet. The `sms`
   channel and phone-only activation are built so that a configuration switch turns them on,
   and they stay off until it does. While off, a phone-only person is listed for an
   in-person code, exactly like a person with no contact at all. PR A builds the channel
   choice and the switch (`invitations.sms_enabled`, false by default); PR B builds the
   phone-code activation behind the same switch.
3. **Hire date: warn, do not change.** Migrated employees keep today's date as now. The
   migration review and the commit result warn that the date was not read from the device
   and should be checked. No schema change. Replaces PR C point 4.
4. **Channels today: email and in person.** Invitations go by email, or are listed for an
   in-person code. The SMS share of the daily cap (`invitations.sms_share`, 30%) is built
   with the SMS channel and only matters once the switch is on.

## Release

Each PR goes local, then branch, then `main`, then `production`, then Plesk, in that order.
PRs A and D add migrations, so their deploys need `migrate` then `optimize`. PRs B and C need neither.
None of the four touches payroll or attendance records.
