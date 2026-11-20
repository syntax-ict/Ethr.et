# Device Integration

Attendance devices integrate through the `App\Contracts\DeviceAdapter` contract,
resolved per device by `App\Services\Device\DeviceManager` from `adapter_type`.

## Adapters

| `adapter_type` | class | status |
|---|---|---|
| `hikvision` | `HikvisionAdapter` | ISAPI (events, enrollments, webhook push) |
| `hikvision`, no address | `HikvisionPushAdapter` | The terminal sends to ETHR and is never called. The way to run Hikvision from shared hosting (below) |
| `zkteco` | `ZktecoAdapter` | HTTP API |
| `zkteco_adms` | `ZktecoAdmsAdapter` | ADMS push: the terminal calls ETHR (`/iclock/*`). The way to run ZKTeco from shared hosting |
| `suprema` | `SupremaAdapter` | HTTP API |
| `generic` | `GenericHttpAdapter` | configurable JSON — any vendor/middleware |
| `mock` | `MockAdapter` | simulator for tests/demos (reads real employees) |

Native adapters for Anviz, FingerTec, ESSL, Matrix, and Ronald Jack are
deliberately **not** hand-written (no hardware to test against); the
`GenericHttpAdapter` covers them today. See ONBOARDING_V2.md decision D7.

## Contract

```php
connect(Device): bool
getStatus(Device): array            // ['online' => bool, ...]
getDeviceInfo(Device): array
pullEvents(Device, ?since): array    // [{employee_badge, timestamp, type}]
pullEnrollments(Device): array       // [{device_user_id, name, card_number, department, fingerprint_count, face_registered}]
pushEventUrl(Device, callbackUrl): bool
```

`pullEnrollments` (Slice 5) reads the **people** on a device — not their punches —
so onboarding can match them to employees before importing attendance.

## GenericHttpAdapter configuration

Everything vendor-specific lives in the device's `connection_config`:

```json
{
  "base_url": "https://middleware.local:8080",
  "auth": { "type": "bearer", "token": "…" },
  "events_path": "/events",
  "enrollments_path": "/users",
  "status_path": "/status",
  "mapping": { "badge": "userId", "timestamp": "time", "type": "direction",
               "user_id": "userId", "name": "fullName", "card": "card",
               "events_root": "data", "enrollments_root": "data" },
  "check_in_values": ["in", "0"]
}
```

IP-based vendors require `connection_config.ip`/`port`; `generic` requires
`base_url` (enforced by `StoreDeviceRequest`).

## Event ingestion & time

Every ingestion path stamps attendance at the **event** time, not ingestion time
(`AttendanceInput::$occurredAt`, Slice 3): `PullDeviceEventsJob`, the Hikvision
and ZKTeco webhooks, and offline sync. Badges resolve to employees through
[identity resolution](IDENTITY_RESOLUTION.md); unknown/ambiguous badges are
logged for review, never guessed.

**Arrival or departure (2026-10-14).** Each vendor's own field decides, the same
way on push and pull:

| vendor | field | in | out | neither |
|---|---|---|---|---|
| Hikvision | `attendanceStatus` (`HikvisionEvent::direction`) | checkIn, overtimeIn | checkOut, overtimeOut | `breakIn` and `breakOut` are noted as breaks (below); `undefined` / absent → `auto` |
| ZKTeco | punch state (`ZktecoAdapter::directionFor`) | 0, 4 | 1, 5 | 2 (break-out) and 3 (break-in) are noted as breaks (below); absent, 255 → `auto` |
| Suprema | event code | — | 0x2000–0x2FFF | everything else → in |
| Generic | `type` vs `check_in_values` | listed values | any other value | empty → `auto` |

`auto` lets the engine decide: it closes the day's open record (the one a check-out
would close) or, with none open, checks in; a second scan within 5 minutes of the
check-in is a duplicate, not a departure. Until this date the Hikvision webhook
recorded **every** event as a check-in and the pull read `eventType`, a field these
events do not carry — an evening scan opened a second record and a conflict.

**Breaks (2026-11-18).** A terminal's own break buttons (Hikvision `breakOut` / `breakIn`, ZKTeco states 2 and 3) are noted on the day's open record as `metadata.breaks`; they neither close nor open it. A terminal that sends an ordinary check-out and check-in around lunch (in 08:50, out 12:05, in 13:55, out 17:00) gets the same result: a check-in on the day of the check-out, within 180 minutes of it and before the shift's end, reopens the day's record and notes the lunch as a break, so the day is one record with no conflict. A return later than that stays a second session. See [ATTENDANCE-PIPELINE.md](ATTENDANCE-PIPELINE.md).

Hikvision events count only when they name an enrolled person (`employeeNoString`):
heartbeats answer `ignored`, and an unregistered card (`cardNo` alone) answers
`no_badge`. Multipart notifications (`event_log` form field) are read like JSON, with
the time taken from the envelope's `dateTime`. The pull searches every minor type
of major 5 and pages with `searchResultPosition` (100 a page, 10 pages a pull);
it asked for face passes only, one page, until this date. Webhooks and pulls
resolve badges through the same `IdentityResolver`.

**Not yet proven on hardware.** These shapes follow the vendors' published
formats; no real terminal has been connected. The field test (plan O1) replays
captured payloads against these rules before any customer depends on them.

## Discovery endpoint

`GET /api/v1/devices/{device}/enrollments` — device roster with a suggested
employee match per person and a summary (matched / probable / ambiguous / new).
Read-only; confirming the matches happens in the [migration workspace](MIGRATION.md).
`roster.source` is `live` for a device read now, and `reported` for a ZKTeco ADMS
terminal, whose list is as of `roster.received_at` (see *Who is enrolled on an ADMS
terminal* below).

## Unrecognised badges (2026-10-20)

A punch whose badge matches no employee, or matches more than one, is **held,
not dropped**. This applies on every path: pull, the vendor webhooks and
ADMS. It is stored in `unmatched_device_punches` with the idempotency key it
would have been recorded under, so a re-pull or a redelivered webhook adds
nothing.

Devices → **Unrecognised badges** lists the held badges by device:

- **Assign** links the badge to an employee (verified) and replays every held
  punch through the normal device path, so the employee's next punches
  resolve at once. A punch the engine refuses (an unreadable or future time)
  is marked `refused`, with the reason.
- **Dismiss** drops the held punches of a badge that is no one's.

### Recorded without a click, by number only (2026-11-19)

When HR writes a terminal's number in a person's **Badge number** (or it equals their employee code) after punches were held, the held punches of that badge are recorded by themselves. A sweep every five minutes (`held-punches-auto-record`, `RecordHeldPunchesJob`, queue `attendance`) queues one job per organisation, device and badge; each replays one slice, earliest first, and queues itself again while punches remain. It records only when all four hold (`UnmatchedPunches::recordsItself`, used by the job and by the card): (a) the card's verdict is a match to a current person who has not left; (b) no person has replayed any punch of this badge on this device (no `replayed` or `refused` row with `resolved_by`), because a person who started a replay and stopped may have stopped for a reason, so what is left is theirs; dismissed rows do not count; (c) the number says so today with any mapping set aside: the number and the terminal's name are scored again and must match the same person (an existing mapping alone proves only that someone once linked it), or the mapping is one a person confirmed and the scoring names no other current employee, which is how terminal users are mapped when nobody holds the number; (d) the terminal's own name points to the same person and nobody else: the terminal has sent a name for the badge, and `TerminalNames::lookalikes()` (two words or more, matched from the first word in order, nobody when more than five fit) returns exactly one employee, the verdict's. The number and the name are two independent things and must name the same single person, because a mistyped number on one employee would otherwise record on the wrong person within minutes with no undo. d applies to every automatic path, a mapping a person confirmed included, and is checked last. A terminal that sends no name, a one-word name, a name two people fit (duplicate records included) and a name that fits someone else leave the punches for a click: the card still says "This is :name" with "Assign and replay". The sweep lists a badge only once a name is stored for it (`device_enrollment_snapshots`). Two holders of the number (even when the terminal's name agrees with one of them: the number must be held by exactly one current employee, whatever the name adds to the score), a name that differs, a leaver, a name with no number, an unconfirmed mapping the person no longer holds the number for, and a number confirmed for someone else leave everything held. A number a person confirmed for someone else is never moved. The link is not marked verified and the rows carry no `resolved_by`. Each slice that replays or refuses a punch is audited as `device.held_punches_auto_recorded` (badge, employee, reasons, replayed, refused). A manual Assign holding the badge's lock makes the job leave quietly. The card marks such a badge (`recording_itself` on `GET devices/unmatched-punches`) and Assign stays available. Applies to every organisation; no setting.

Listing needs `device.viewAny`; assigning and dismissing need
`attendance.manage`. Both are audited (`device.badge_assigned`,
`device.badge_dismissed`). Before this, every device path logged the badge
and discarded the punch, so a day was lost for good once the badge was
finally mapped.

**Bringing the punches in is bounded and carries on (2026-11-16).** The host
ends a request at about 30 seconds and a badge can hold hundreds of punches,
so `POST devices/unmatched-punches/assign` links the number, replays held
punches earliest first for about 12 seconds (`UnmatchedPunches::SLICE_SECONDS`,
the same as an import slice) and answers `{replayed, refused, remaining}`. It
always replays at least one punch, so every call makes progress. Sending the
same three inputs again carries on from where it stopped; a punch already
`replayed` or `refused` is never looked at again. The Devices card and the
committed import screen do this themselves, with a Stop button and a Carry on
button after a failure, and add the totals.

One cache lock per organisation, device and badge (`UnmatchedPunches::lockKey`,
taken without waiting, released in `finally`, 60 seconds at most if the request
dies) keeps two requests from replaying the same punch. A caller that cannot
take it gets `409` ("These punches are already being brought in") and nothing
has been linked or replayed for it. Production's cache store is `database`,
which supports locks. Every call that replays or refuses at least one punch writes one audit
entry with that call's own counts: `device.badge_assigned` when the call made
or changed the link, `device.held_punches_replayed` when it only carried on or
the number was already confirmed (an import does that). A call that finds
nothing pending writes nothing. Refused punches are marked `refused` with the
reason and are shown nowhere in the product, only counted in the answer and
the audit entry.

After a list is added, the terminals read of that batch
(`GET onboarding/migration/batches/{batch}/terminals`) lists, for someone with
`attendance.manage`, the held badges that now resolve to exactly one of the
list's people (`held`, at most 100, the rest counted in `held_more`; ambiguous
or probable matches are never listed). One button there assigns them one
badge after another through the same endpoint. The button is a person's click: it gives
each number to that person and cannot be undone from that screen. A number that equals
the person's badge number or code is recorded by the five-minute sweep above without it.

## Attendance-history import (backfill)

`POST /api/v1/devices/{device}/import-history` with `{ window }`:
`last_30` | `last_90` | `from_date` (+ `from_date`) | `full`. It dispatches
`PullDeviceEventsJob` with `triggeredBy: 'history_import'` and a `sinceOverride`
(none for `full`), so the pull is a one-off backfill:

- events are dated at their real event time (`AttendanceInput::$occurredAt`), so
  a 90-day backfill lands 90 days ago, not today;
- the device's incremental cursor (`last_sync_at`) is **left untouched**, so the
  next scheduled sync doesn't skip the gap between the backfill window and now;
- idempotency keys let a backfill safely overlap live syncs.

## Paging through a device (backfills and scheduled syncs alike)

Adapters return up to their per-call cap (~100 events), so one call is never
assumed to be everything. **Both** a backfill and the five-minutely incremental
sync drain the device page by page. *(2026-10-03: until then only a backfill
with a start date paged. `full` and the incremental sync made one call and then
moved the cursor to now, so anything past the first page was lost for good: a
device back from a day offline with 400 punches kept 100.)*

- **The cursor stays on each page's latest timestamp**, not one second past it.
  Vendors disagree on whether `since` is inclusive; stepping past the last event
  lost the next second's punch on a strictly-after API and a same-second punch
  on an inclusive one. Punches an inclusive API returns twice are skipped by the
  job and, across runs, by idempotency key.
- **A run stops at the moment it began.** Later punches belong to the next
  incremental sync, which starts its cursor at that moment. This is also what
  ends a run against an adapter that never runs dry (`MockAdapter`, a device
  clock running fast).
- **A run has a time budget** (`devices.pull_time_budget_seconds`, default 20).
  Production drains the queue in 50-second windows inside an HTTP request, so a
  long history is read across a chain of jobs: each stops at its budget and
  queues a continuation from its cursor, which is a backfill and leaves
  `last_sync_at` alone. An incremental sync moves `last_sync_at` to the moment
  it began, whether or not it handed work on.
- **What a time cursor cannot do:** if a full page of punches shares one second,
  the device's API offers no way past them by time. The run stops, records the
  sync as `partial` and says so in `error_message`. Getting further would take
  vendor offset paging (Hikvision `searchResultPosition`, for one), which has not
  been verified against real hardware and is not built.

Pinned in `tests/Feature/AttendanceHistoryImportTest.php`.

## A failed read is not an empty one

`pullEvents()` and `pullEnrollments()` throw `App\Exceptions\DeviceRequestFailed`
when the device (or its middleware) is unreachable, answers non-2xx, or answers
2xx with a body that is not JSON — the three checks in
`Services\Device\Concerns\ReadsFromDevice`, which every vendor adapter uses. *(2026-10-03: until then each adapter caught
everything and returned `[]`, which is also what an idle device returns. The job
could not tell them apart, so a timed-out read looked like "nothing new" and an
incremental sync moved `last_sync_at` past punches it never read.)*

The job already had the right path for an exception: the device goes to
`error`, the sync log to `failed`, the job retries once, and after that
`DeviceSyncFailed` notifies the tenant's admins. It never reaches the cursor
update, so the next attempt reads from where the last good one stopped.

- A 2xx JSON reply with no events key is still an empty read. ISAPI leaves out
  `InfoList` when nothing matched, and the vendor quirks beyond that cannot be
  checked without hardware.
- The exception's message names the vendor and the HTTP status, never the
  address or the credentials; the cURL error is chained, not quoted, because it
  carries the full URL and a generic `base_url` may embed `user:pass@`.
- **Over HTTP it is a 502.** `bootstrap/app.php` renders `DeviceRequestFailed`
  as an RFC-7807 problem with `type` `…/errors/device-unreachable` and the
  translated `device.read_failed` as `detail` — not the exception's message,
  which is for the sync log. Enrollment discovery used to answer 200 with an
  empty roster, and staging a migration from a down device used to create an
  empty batch; staging now reads the device *before* creating the batch, so a
  failure leaves nothing behind. Both screens already had an error state (the
  discovery dialog's `QueryBoundary`, the migration step's toast).
- `connect()` and `getStatus()` still answer `false` / offline on failure:
  answering "is it up?" with "no" is their job.

Pinned in `tests/Feature/Device/VendorAdapterProtocolTest.php` and
`tests/Feature/DeviceEnrollmentTest.php`.

## A device that failed is tried again (2026-11-15)

The scheduler pulled only devices that read `online` or `pending`. The pull job
writes `offline` after one failed status probe and `error` after a sync that
threw, so a device that failed once was never pulled again until an admin
pressed Test or Pull by hand, although the job itself sets `online` as soon as
its probe succeeds. It was simply never dispatched.

The rule now:

- `devices:sync` retries an auto-sync device that is `offline` or `error` when
  its last pull attempt started at least 15 minutes ago, or at least its own
  `sync_interval_minutes` ago if that is longer, or when it has no attempt on
  record. The attempt is the latest `started_at` in `device_sync_logs` for that
  device, read with the organisation stated in the query.
- A ZKTeco ADMS terminal is never retried. ETHR does not call it, and it comes
  back online by its own next call.
- A terminal that only sends is unchanged: its status follows from when it was
  last heard.
- "Sync all" now includes offline and error devices, and skips terminals that
  only send. The count it returns is the number of pulls queued.
- Each kind of notice (device offline, sync failed) goes out at most once a day
  per device. Nothing clears it early, since a device on a bad link changes
  state all day; the device page still shows the current state.

Pinned in `tests/Feature/Device/DeviceOfflineRetryTest.php`.

## How often a pulled terminal is read (2026-11-17)

The owner can set a pulled terminal's sync interval to 5 to 1,440 minutes (default 5). The floor is 5 because
`devices:sync` is scheduled `everyFiveMinutes()` and production has no cron of its own: the scheduler is
driven from outside, best effort. An interval under 5 minutes could not be kept, so the API refuses it with a
422 (`Device::MIN_SYNC_INTERVAL_MINUTES`, used by both the store and update requests), the select offers 5
minutes and up, and the screen says "At most every :count min" with a hint that a pull can come later than
the interval.

A terminal already stored at 1 to 4 minutes needs no migration: the model reads `sync_interval_minutes` as
`max(5, stored value)`, so the card, the resource, `isDueForSync()` and the retry wait all see 5. The next
save of that device stores 5.

A late pull delays when punches appear and loses none: each pull reads from where the last one stopped, and a
punch keeps the time it happened. A terminal that sends to ETHR does not depend on any of this, and shows no
interval.

## Commands to devices (2026-10-14)

Until this, device management was one-way. Punches came in and nothing went out: every
new hire had to be enrolled on every terminal by hand, leavers stayed on them, and
device clocks were never set. `device_commands` is the outbox that replaces that.

| Command | Payload | When it is queued |
|---|---|---|
| `set_time` | none; the time is read when the command is sent | Once a day per device, at its next delivery, and from **Set clock now** |
| `upsert_user` | `device_user_id`, `name` (32 characters) | A hire, a rename, a new badge number, a move into the branch, **Send all staff**, **Enrol on devices** |
| `delete_user` | `device_user_id` | Status becomes resigned, terminated or retired; the employee is deleted; a move out of the branch |

Five rules:

- **Off until an administrator turns it on.** `devices.commands_enabled` defaults to
  false, and existing devices are unchanged. Removing a person from a terminal deletes
  the fingerprints and face enrolled there, so nobody should get that by surprise. The
  switch is in the device's **Edit** dialog, shown only for adapters that can carry
  commands out.
- **A person's commands go to the devices of their branch.** The device user id is the
  one already linked to that device (`employee_external_identities`), else the badge
  number. The badge number is set on the employee's profile or the new-employee form
  (*Badge / device ID*, since 2026-10-14; letters, digits and hyphens, at most 32, unique
  in the organisation). ZKTeco terminals take digits only, and `DeviceIdRules` enforces it: a number
  with a letter in it is not queued for a `zkteco` or `zkteco_adms` device. It is kept as a failed
  `upsert_user` whose error reads *Not sent: this terminal takes digits only as a person number*, so
  the device's Commands tab says who was not sent, and **Send all staff** counts it as skipped. A
  list read for import warns (`terminal_number_not_accepted`, in `warnings`) when a badge would be
  refused by a type the organisation has a device of; the person is still added. Other types have no
  rule of their own, and nothing is claimed for them. Until then nothing in the
  product could set it, so a new hire never reached a terminal by badge. A person with neither is skipped, and **Send all staff** reports how many.
- **Only a device ETHR can write to is sent commands.** The switch is cleared when a terminal
  becomes one that only sends (Hikvision push), and the `zkteco` (REST) adapter takes none, so a
  ZKTeco terminal that carries commands is `zkteco_adms`.
- **A newer command for the same person replaces one still waiting** (status
  `cancelled`). Two edits in a row reach the device once, and a hire followed by a
  termination never adds.
- **Suspension does not remove anyone.** Only resigned, terminated and retired do. A
  suspension is often reversed, and re-enrolling a face is not free.
- **Biometrics are still captured at the device.** ETHR sends the person's id and name.
  Their fingerprint, face or card is enrolled on the terminal itself.

**Delivery.** An adapter that implements `App\Contracts\AcceptsDeviceCommands` is called
directly: `DeliverDeviceCommandsJob` is queued when a command is added, and
`PullDeviceEventsJob` delivers after every sync that finds the device online.

- The device answered and refused (`DeviceCommandRejected`): the attempt counts. After
  three, the command is `failed`.
- The device was not reached: the command stays `pending`, with the reason in
  `last_error`, and the attempt does not count.
- A failure to queue never fails the employee save. The hook reports the exception and
  moves on, which also covers the window between a deploy and its `migrate`.

| Adapter | Commands | Notes |
|---|---|---|
| `hikvision` | yes | ISAPI: `PUT /ISAPI/System/time`, `POST /ISAPI/AccessControl/UserInfo/SetUp`, `PUT /ISAPI/AccessControl/UserInfo/Delete`. Written from the published specification and **not yet run on a terminal** (field test O1) |
| `mock` | yes | Accepts everything; `connection_config.reject_commands` makes it refuse |
| `zkteco_adms` | yes, collected | The device takes them when it polls `/iclock/getrequest` (below) |
| `zkteco` | no | The REST adapter; register the terminal as `zkteco_adms` to manage it |
| `suprema`, `generic` | no | |

**On shared hosting, direct delivery reaches only devices with a public address.**
`DeviceHost` refuses private addresses there, and the server could not reach a customer
LAN anyway. A device on a LAN has to collect its commands (ZKTeco ADMS) or go through a
bridge (Wave 3 #10). Until then, its commands wait as `pending` with the reason shown on
the device's **Commands** tab.

API, all under `auth:sanctum`:

- `GET /devices/{device}/commands` (`device.view`)
- `POST /devices/{device}/commands/set-time` and `/send-staff` (`device.update`; 409
  while commands are off)
- `POST /devices/{device}/commands/retry` (`device.update`; 409 while commands are off or
  for a type ETHR cannot write to). Body: optional `batch_public_id`, a list of this
  organisation, to send again only its people. Answers `{queued, skipped}`.
- `POST /employees/{employee}/enrol-on-devices` (`device.update`)

Pinned in `tests/Feature/Device/DeviceCommandQueueTest.php` and
`src/src/test/device-commands-tab.test.tsx`.

### Sending again, and what a list left at the terminals (2026-11-16)

**Retry goes through the same door as a save.** `DeviceCommandQueue::resend()` takes the
people whose newest add-or-update on the device failed (`failedPeople()`) and calls the
same private `upsert()` a save does, so `DeviceIdRules` applies again: a number the
terminal still would not take stays "not sent" and is counted as `skipped`. Someone who has left the device's branch is skipped, so the removal queued when they moved is not cancelled by an add. Old rows are
never flipped back to `pending`; a new command is queued beside them, and the newest one
is what every count reads.

**One way to tell a not-sent row.** A refusal of a number is a `failed` `upsert_user`
whose `last_error` starts with `DeviceCommandQueue::NOT_SENT_PREFIX`; ask
`DeviceCommandQueue::isNotSent()`, never repeat the text. A command the terminal itself
refused (`The device answered with error 4.`) is a failure; the two are counted apart
because only the second is cured by trying again.

**Reading it back for a list.** `GET /onboarding/migration/batches/{batch}/terminals`
(`employee.viewAny`, and `device.viewAny` for anything about devices; without it the
answer is the empty shape with `visible: false`) lists, for each device of the branches
the list's people are in, `queued` (pending or sent), `done`, `failed`, `not_sent`,
the newest failure's `last_error`, and for a device ETHR cannot write to (`writable`
false: commands off, or a type with no adapter that takes them, or a Hikvision terminal
that only sends) `enrol_by_hand`, the people to enrol there with the number each is to be
given. It also counts `without_number` (no badge and no id any device of the branch
reported) and `without_branch`. Only people the viewer may open are named or counted.
`MigrationTerminals` reads it in a fixed number of queries: the devices, the ids they
reported, and each person's newest add-or-update per device
(`DeviceCommand::newestUpsert`). Pinned in
`tests/Feature/Migration/MigrationTerminalsTest.php`.

## ZKTeco ADMS (2026-10-14)

ADMS is the "cloud server" push protocol in ZKTeco firmware. The terminal calls ETHR, so
it works from any office network to shared hosting, where ETHR cannot reach the
terminal. Register it as **`zkteco_adms`** with its serial number and no address. Then,
on the terminal, open **COMM → Cloud Server Setting**:

- Server address: the organisation's own address, `{slug}.ethr.et` (shown on the device page).
  A terminal set up with the shared host `ethr.et` is refused.
- Port: 443 with HTTPS on, or 80 for firmware without HTTPS.
- Enable domain name: on.

| Request | ETHR's answer |
|---|---|
| `GET /iclock/cdata?SN=…` | The options: `TimeZone` from the device's zone, `Realtime=1`, `ATTLOGStamp=None`, so a first connection uploads what the device holds |
| `POST /iclock/cdata?SN=…&table=ATTLOG` | Each line `PIN⇥YYYY-MM-DD HH:MM:SS⇥status⇥…` is a punch in device-local time. Direction comes from `ZktecoAdapter::directionFor`. The answer is `OK: <lines>` |
| `POST /iclock/cdata?…&table=OPERLOG` (and `USERINFO`, `tabledata`) | People in the body go to the roster (below); everything else is not stored. `OK: <lines>` |
| `POST /iclock/querydata?SN=…&tablename=user` | PUSH 3.x firmware's answer to a roster request. People go to the roster. The answer is `<tablename>=<lines>` |
| `GET /iclock/getrequest?SN=…` | Waiting commands, `C:<id>:<command>` per line, or `OK` |
| `POST /iclock/devicecmd?SN=…` | Results, `ID=<id>&Return=<code>`. 0 marks the command done |

Commands as the terminal reads them:

- `DATA UPDATE USERINFO PIN=…⇥Name=…⇥Pri=0`
- `DATA DELETE USERINFO PIN=…`
- `SET OPTION DateTime=<packed>`. The packed value is seconds in a calendar of
  12 × 31-day months from 2000, the firmware's own encoding.
- `DATA QUERY USERINFO`, the roster request. PUSH 3.x firmware gets
  `DATA QUERY tablename=user,fielddesc=*,filter=*` instead (below).

### Who is enrolled on an ADMS terminal (2026-10-28)

ETHR cannot read an ADMS terminal, so `pullEnrollments` returns what the terminal last
reported, held in `device_enrollment_snapshots` (`AdmsRoster`). The discovery endpoint and
the migration workspace read it as they read any other device. The terminal reports in
three ways:

- **When asked.** `POST /api/v1/devices/{device}/enrollments/request` (`device.update`)
  queues a `query_users` command. The terminal takes it at its next check-in, usually
  within a minute. A request still waiting is not repeated. It changes nothing on the
  terminal, so it is the one command sent while *Send commands to this device* is off.
- **At start-up**, when ETHR holds no list for it and has not asked in 24 hours.
- **As people change.** The handshake's `TransFlag` includes `EnrollUser ChgUser`, so a
  person enrolled or edited at the terminal is uploaded at once. A person ETHR adds or
  removes is written to the list when the terminal confirms the command.

The two firmware generations, both accepted in either upload:

| | PUSH 2.x | PUSH 3.x |
|---|---|---|
| Person | `USER PIN=1⇥Name=…⇥Pri=0⇥Passwd=…⇥Card=[0000123456]` | `user uid=1⇥pin=1⇥name=…⇥privilege=0⇥password=…⇥cardno=123456` |
| Fingerprint | `FP PIN=1⇥FID=6⇥TMP=…` | `templatev10 pin=1⇥fingerid=6⇥template=…`, or `biodata … type=1` |
| Face | `FACE PIN=1⇥…` | `biodata … type=9` (any type but 1), `biophoto` |

Keys are read without regard to case. Firmware that separates fields with spaces is split
before each `Key=`, so a name with a space stays whole. A card loses its brackets and the
zeros that pad it; all zeros means no card. A name that is not valid UTF-8 is cleaned, not
refused.

**Never stored:** the password, and every fingerprint and face template, which are
biometric data under PDPP 1321/2024. Only a count of fingers per person, and whether a
face is enrolled, are kept.

**PUSH 3.x.** A terminal that answers the 2.x request with an error (`Return` below 0) is
asked once more in the 3.x form. A 3.x terminal reports the number of records sent as its
`Return`, so 0 and above is success for this command only.

**Removed at the terminal.** The firmware never says "that was everyone". When the
terminal confirms a request it answered, anyone it did not mention since the request was
sent is dropped from the list. If the confirmation arrives before any of the answer, the
list is left as it was. The device dialog shows the count and when it arrived, and does
not call the list complete.

A sent command that is not confirmed is offered again after 30 minutes and fails after
three sends. The `.aspx` spellings some firmware uses are routed too.

**Authentication: serial selects, the organisation's address and the IP allowlist admit.**
ADMS sends its serial, the host it was set up to call, and nothing else an administrator
can configure, so this is the token-less webhook policy, applied strictly
(`AdmsDeviceResolver`):

- The serial is printed on the case and proves nothing on its own.
- The request must arrive on the organisation's own address: its subdomain, or its
  verified custom domain. Only someone at the terminal can set that, so registering
  another organisation's serial first gains nothing (owner decision 2026-10-31). On a
  deployment that serves neither, this step does not apply.
- An empty allowlist admits nobody.
- A serial held by two devices admits neither.
- The allowlist accepts an IPv4 range (`196.188.0.0/16`), because office broadband
  addresses change. Nothing wider than /8 is accepted.
- A refused attempt for a known serial is remembered for a week with its address. The
  device page shows it, because that address is usually the one the allowlist is missing.
  Only attempts that reached the organisation's own address are remembered.

**Every line is acknowledged**, an unknown PIN or a refused time included. A line answered
with an error is sent again by the device indefinitely.

**Routing.** `/iclock` is fixed in the firmware, so it is outside `/api/v1`, and outside
the `api` middleware group, because a device has no session, CSRF token or tenant host.
The `.htaccess` template routes `^/(api|sanctum|iclock)` to Laravel, and `iclock` is a
reserved tenant slug. Its limiter is `adms`: 240 requests a minute per serial and address.

**Host action for HTTP-only firmware:** if Plesk's *permanent HTTP → HTTPS redirect* is on,
a terminal that cannot do HTTPS receives a 301 it does not follow. Use a terminal with
HTTPS, or ask the host to exempt `/iclock`.

Not yet run against a real terminal (field test O1). The formats follow the PUSH SDK
2.x protocol, and the roster also the 3.x one. Watch the first device's **Commands** tab
and attendance, and compare its enrolled list with the count on the terminal. Then
replace the bodies in `tests/Feature/Device/AdmsRosterTest.php` with what it actually
sent.

## Hikvision that sends to ETHR (2026-11-11)

A Hikvision terminal normally sits on an office network. On shared hosting ETHR cannot
reach a private address and `DeviceHost` refuses one ("The device address points to a
private or internal network"), and the form required an address, so such a terminal could
not be registered at all. The push handler never needed the address: it finds the device
by its token. A terminal may now be registered with **no address**.

- **Registering.** `connection_config: {"mode": "push"}` and no `ip`. The mode has to be
  said: an address left out by mistake is still a 422. No other vendor has it (ZKTeco has
  ADMS). The device keeps `adapter_type` `hikvision`, so the push URL and the token lookup
  are unchanged; `DeviceManager::receivesOnly()` reads the stored config and picks
  `HikvisionPushAdapter`. `DeviceResource` says `receives_only`.
- **On the terminal.** Its web page, Network, HTTP listening (some firmware: Event alarm,
  Alarm host): the server address, port, protocol and URL the device page shows, taken
  from `webhook_url`. **The terminal must post over HTTPS to `www`**: the host answers
  plain HTTP and the apex with a 301, which a terminal does not follow (measured
  2026-11-11 on `/api/v1/devices/webhook/hikvision`).
- **Alive or not (four states; three from 2026-11-12, `quiet` added 2026-11-17).** Anything the
  terminal sends counts as contact, a heartbeat included, and sets `last_sync_at`. The stored
  `status` then follows one rule, `HikvisionPushAdapter::statusFor()`: `last_sync_at` null is
  `pending` (shown "Not connected yet": configured, never heard from); heard within
  `ONLINE_WITHIN_MINUTES` (15) is `online`; heard within `OFFLINE_AFTER_HOURS` (24) but not
  within 15 minutes is `quiet`; older is `offline` (shown with "Last heard 3 days ago").
  Online therefore means ETHR has fresh evidence, never only that the terminal was set up.
  `quiet` exists because ETHR cannot call this terminal: once the evidence is stale it cannot
  tell a switched-off terminal from one nobody is using, so it says exactly that ("Last heard
  3 hours ago. ETHR cannot call this terminal, so it cannot tell whether it is switched off or
  just not in use.") instead of "Online" (before 2026-11-17 the rule was `online` for 24
  hours, and a terminal silent for 11 hours still read Online). `quiet` is not an alarm: no
  notification, no error, and it counts as set up, not as live. That is a label, not a gate: a
  punch from an "offline" or "quiet" terminal is recorded. For a terminal ETHR also pulls, only
  a punch moves `last_sync_at`, because there it is the pull cursor.
- **The push inbox (2026-11-13).** A terminal re-sends any event not answered 200, at once and
  without backing off, and production has five PHP workers. So the post does one insert into
  `device_inbound_punches` (unique on the same idempotency key as before, so a re-sent event
  is one row) and answers `200 {"status":"accepted"}`. **The 200 is the acknowledgement**:
  anything else, a 429 included, makes the terminal send the same event again and adds
  requests without removing work. `ProcessDeviceInboundPunchesJob` then works the device's
  rows in arrival order (`id`) on the `attendance` queue, one run at a time per device: the
  badge is resolved, and the punch is recorded or held in `unmatched_device_punches`. The
  engine's `auto` direction reads the open record, so order is what makes an evening scan
  close the morning one. A database error leaves the row pending and the rows behind it wait
  for the retry. Processed rows are deleted after seven days by `CleanupExpiredDataJob`,
  waiting rows never, and quarantined rows (below) not until a person has dismissed them. The route's own
  `device-webhook` limiter allows 600 posts a minute per address (a ceiling against a broken
  sender, not a delivery pace), and the global API limiter does not count these posts a second
  time. Because the work is queued, a punch is recorded only once something drains the queue
  (production: `RunBackgroundWorkAfterResponse` after any API response, the terminal's own
  posts included, at most once a minute, and the cron caller), and a heartbeat or punch stamps the device row at most once a
  minute.
- **Every push path goes through the inbox (2026-11-20).** The ZKTeco and Suprema JSON
  webhooks and ADMS `ATTLOG` uploads used to run the attendance engine inside the request, so
  an unexpected error was acknowledged and lost, and a backlog after a power cut ran the
  engine per line under the host's 30-second limit. They now `insertOrIgnore` into
  `device_inbound_punches` in chunks of 500 (`DeviceInboundPunch::stash()`), keep their key
  prefixes (`webhook:zk|sup|adms:{device}:{badge}:{time}`, so punches recorded before still
  dedupe a resend), queue `ProcessDeviceInboundPunchesJob` when a row was new, and answer
  exactly as before (`processed` with a `count`, or `OK: n` for ADMS). An unreadable ADMS line
  is stored in quarantine with its text and counted in the `OK: n`, so the terminal does not
  send it for ever. `/iclock` carries `RunBackgroundWorkAfterResponse` like the API group.
- **Raw is kept, a failure is quarantined (2026-11-20).** Every inbox row stores the event as
  received in `raw` (Hikvision: the parsed event only, never the multipart face picture; ADMS:
  the line; ZKTeco and Suprema: the record), capped at 16 KB with a `...[truncated]` marker,
  and clears it once the punch is recorded or held. A punch the engine refuses
  (`AttendanceRefused`: a leaver, a check-out with no check-in, a disabled method), a time
  the terminal's clock puts more than ten minutes ahead, an event with no time, or an
  unexpected exception is not purged and not retried: the row gets `failed_at`, `failure`
  (`refused:{reason}`, `clock_ahead`, `no_time`, `error`) and a scrubbed `error`, and the
  device's later rows carry on. A database or deadlock error still retries; a row that fails
  five times becomes `failure = error` instead of being given up. An unknown badge is not a
  failure: it stays the held-punch path. `GET /devices/{device}/quarantine` lists them
  (`device.view`; `raw` only for `device.update`); `POST /devices/quarantine/replay` puts rows
  back in the inbox (so a fixed leaving date or terminal clock lets them record, once: a
  replay of a punch already recorded is a duplicate, never a second record) and
  `POST /devices/quarantine/dismiss` lets them go (kept a week, then purged). Both take
  `ids` (public ids, at most 500) and write `device.quarantine_replayed` /
  `device.quarantine_dismissed`. A `no_time` row cannot be replayed, since the same row fails
  again: the terminal sent no time, so the punch is entered by hand. The device page shows the
  list in "Punches that need attention", and `/devices/dashboard` the count.
- **Key guard.** `attendance_records.idempotency_key` is varchar(64) and device keys used to be
  built up to 191 characters, which production's strict MySQL refuses. `DevicePunchKey::fit()`
  returns a key of 64 characters or less unchanged and a longer one as `dk:` plus 60 hex
  characters of its sha256, and is applied at every construction site and inside
  `DevicePunchRecorder::record()`, so live push, pull and held-punch replay give the same key.
- **Queue order.** `QueueHealth::QUEUES` is `attendance, default, notifications, exports`:
  the first queue named is drained first, so punches no longer wait behind pulls and reports.
- **Who writes it.** The rule is written by `devices:sync` on every run for every such
  device (nothing else moves a terminal off `online` when it goes quiet, because it is
  never pulled), by `PUT /devices/{id}` when the mode changes, and by
  `GET /devices/{id}/status`, and since 2026-11-17 by `GET /devices`, `GET /devices/{id}` and
  `GET /devices/dashboard`, which settle the organisation's sending terminals before they
  answer, because the scheduler (a GitHub Actions call) is often 10 to 20 minutes late; they
  write only on change. The same `devices:sync` run also moves a ZKTeco ADMS terminal whose
  automatic work is switched off to `offline` once it has been silent past
  `ZktecoAdmsAdapter::OFFLINE_AFTER_MINUTES`, with no pull, no sync log row and no
  `DeviceOffline` notice; it comes back by its own next call. A terminal switched from pulling to sending has its
  `last_sync_at` cleared, since it was the pull cursor and would read as "heard just now";
  switched back to an address it is `pending`. `DeviceOffline` is **not** raised for these
  devices: a 24-hour threshold plus a weekend closure would alarm every Saturday unless the
  terminal sends heartbeats, which nobody has observed this model do. Since 2026-11-17 that
  is being measured: `TerminalKeepAlive` records, per device, the last time such a terminal
  posted something that was not an access event (cache, 30 days, written at most once a
  minute), and the device page shows it as "Reports in when idle, last 2 hours ago" once there
  is one. It is measurement only; the status rule does not read it yet, and may once a
  terminal has been seen doing it.
- **Never pulled.** `devices:sync` does not dispatch it and `PullDeviceEventsJob` returns
  at once. A pull found nothing and moved `last_sync_at` to now, which made a silent
  terminal look as if it had just been heard from. The device page and list card offer no
  Test, Pull, enrolled users or history for it, and say when it was last heard instead.
- **Switching.** An update that says `mode: push` replaces the stored config, so the
  address and the credentials go. An update that brings an address drops the mode.

**What this mode cannot do**, each because it needs a connection to the terminal: read
the enrolled users (so "bring people in from this device" lists nobody), fetch a punch the
terminal failed to deliver, set the clock, add or remove a person. A punch made while the
terminal had no internet arrives only if its firmware resends it. The on-premise bridge
(`BRIDGE-DESIGN.md`) is still what closes those.

**Not run on a terminal.** `HikvisionPushModeTest` sends the two body shapes firmware is
documented to send (JSON, and multipart with the event in `event_log`). Whether a given
model accepts an HTTPS host name, a query string in the URL field, and this certificate
is a field test, first owed on a DS-K1T321MFWX-B.

**Found while reading, not fixed here** (they affect a terminal ETHR calls, not this mode):

- `HikvisionAdapter` signs in with HTTP Basic only. Hikvision terminals commonly require
  Digest for ISAPI, in which case every call is a 401.
- `pushEventUrl()` is called by nothing, and its body is not the shape
  `/ISAPI/Event/notification/httpHosts` takes, so ETHR has never configured push on a
  terminal. It is set by hand.
- The adapter speaks plain HTTP to the terminal; there is no HTTPS option.

### The name a Hikvision terminal sends (2026-11-17)

An `AccessControllerEvent` carries the enrolled person's `name` beside `employeeNoString`. ETHR used to
keep only the number, so a number that matched no employee could not be traced to anyone: one
organisation had 83 such numbers (R011, T022, "66") and none of its employees carried a terminal number.

- **Where it is kept.** `HikvisionEvent::personName()` reads and cleans it (control characters out, spaces
  collapsed, 100 characters at most). `TerminalNames::note()` upserts it into
  `device_enrollment_snapshots`, one row per (device, user number), touching only `name`, `seen_at` and
  `updated_at`, so a row an ADMS roster wrote keeps its card, admin flag and template counts. A failure to
  keep it is reported and swallowed: a name never costs a punch. Nothing here changes how a punch is
  resolved or recorded.
- **What the card does with it.** For someone with `attendance.manage`, each held badge says "The terminal
  calls this person :name" and lists current employees the name could be (`name_matches`, with their
  department), each a button that only fills the picker. Assign stays a separate click. The name also goes
  to the resolver, so a small number that equals some employee's code while the two names share no word is
  shown as "Possibly", not "This is". The new-employee link and the downloaded list start from the name.
- **What it does not do.** It never assigns a badge and never touches the live punch path. The words of the terminal's name must match the employee's name in the same order from the
  first (given name, father's name, grandfather's name), so a father's name does not suggest his
  child. A name of one
  word suggests nobody, and so does a name that more than five people fit. Only people the viewer may open
  are listed, and only this organisation's. A terminal whose users were enrolled without names sends none,
  and then the card is as it was.

## Why a pull failed (2026-11-12)

`PullDeviceEventsJob` stored the literal "Device is not reachable" and threw away the
adapter's reason, which cost a morning of diagnosis on the Habru terminal (a pull-mode
registration on `196.190.67.12:8000`: `cURL error 7 ... Connection refused`, and 8000 is
Hikvision's SDK port, not the HTTP port ISAPI answers on). The adapter's message is now
kept in `device_sync_logs.error_message` after `DeviceErrorCode::scrub()` removes any
credentials and query string a transport error quotes, and `DeviceSyncLogResource` and
`GET /devices/{id}/status` publish `error_code` derived by `DeviceErrorCode::from()`, given
the device's adapter: `sdk_port` (Hikvision only: refused or timed out on port 8000),
`refused` (cURL 7), `timeout` (28), `dns` (6), `auth` (HTTP 401/403), `http` (any other
status), `unreachable` (the old message), `other`. The advice is in the dictionaries, not
in PHP, so it is read in Amharic and Afaan Oromoo too: `device_error.hint.<code>` is
vendor-neutral, and the screen adds `device_error.hikvision.*` only for a Hikvision device,
because only it has the office network setting and only for it does ETHR know Basic
sign-in can be refused where Digest is required.

There is deliberately **no "check your password" advice** for a Hikvision `auth`: ETHR
signs in with HTTP Basic only and Hikvision ISAPI commonly requires Digest (see the known
gap above), so a correct password can still be refused. The text says so instead.

A pull already running when the device is switched to send to ETHR writes neither its
status nor `last_sync_at` (`PullDeviceEventsJob::stillPulled()` reads the device again
first): either write would make a terminal nobody has heard from read Online for a day, or
Offline. `devices:sync` reports and skips one device it cannot read (a config that no
longer decrypts) rather than stopping for every organisation after it.

`GET /attendance/setup-status` keeps the two kinds apart: `devices_never_heard` (info) is a
terminal that sends to ETHR (Hikvision push, ZKTeco ADMS) and has sent nothing yet;
`devices_silent` (warning) is a device quiet for a day, or one ETHR pulls that has never
once synced a day after it was registered, which means every pull has failed. The console's
tenant journey keeps its own three-day `devices_silent`, with the same registration grace,
for both kinds: a terminal not set up three days after it was registered is a stalled setup
the platform should see.

## Why a terminal's calls were refused (2026-11-13)

A refused webhook used to leave only a log line, so a terminal with a stale or cut-off
address read "Nothing received from this terminal yet", exactly like one that never called.
`Services/Device/WebhookRejections` now keeps the last refusal for a week on the device it
can be traced to, and the device page says when the terminal called and why it was refused
(`webhook_rejected` on the device, shown to organisation admins, never through an
integration key). It stops showing once the terminal has been heard from since.

| Reason | How the call is traced to the device |
|---|---|
| `token_retired` | The token was regenerated and the terminal still sends the old one. The rotation stores a hash of the old token against the device, never the token. |
| `token_truncated` | The terminal sent the start of the device's token and no more, as a short address field does. Only a hex string of 16 to 63 characters is tried, so no `LIKE` wildcard reaches the match, and only when exactly one device of that vendor matches. |
| `token_missing` | The request named the device by its serial and carried no token, and the device has one. |
| `ip_not_allowed` | A device without a token called from an address not on its allowlist. |

**What is not traced: a token mistyped from the start.** Nothing else in a Hikvision push
names the device (the push carries no device serial), and the source address cannot stand in:
terminals of two organisations can share one public address behind carrier NAT, and a wrong
match would show one organisation another's terminal. Such a terminal still reads "Nothing
received from this terminal yet"; re-enter the address from the device page.

A terminal retrying every few seconds is written once a minute, and a cache that cannot be
reached never turns the 401 into a 500.

## Device clocks (2026-10-14)

`devices.clock_offset_seconds` is how far a device's clock is from the server's, read off
each pushed punch (`DeviceClock`): the punch time minus the time it arrived. Pushes
arrive seconds after the punch, so the gap is the clock error plus delivery delay.

- A gap over an hour is ignored. That is a backlog uploaded after an outage, or a time
  that is simply wrong.
- **Ahead by more than two minutes raises a `device_clock_ahead` integrity flag** on the
  punch. Up to ten minutes ahead the punch is recorded and flagged. Beyond that,
  `DeviceTime::isInFuture` refuses it, but the clock reading is still taken first.
- **Behind is shown, not flagged.** It cannot be told apart from a punch that arrived
  late.

The device page shows the reading as *in step*, *N min ahead* or *N min behind*. The fix
is the `set_time` command, sent daily to every device that takes commands, or **Set clock
now** on its Commands tab.

