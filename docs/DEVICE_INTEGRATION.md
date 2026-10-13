# Device Integration

Attendance devices integrate through the `App\Contracts\DeviceAdapter` contract,
resolved per device by `App\Services\Device\DeviceManager` from `adapter_type`.

## Adapters

| `adapter_type` | class | status |
|---|---|---|
| `hikvision` | `HikvisionAdapter` | ISAPI (events, enrollments, webhook push) |
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
| Hikvision | `attendanceStatus` (`HikvisionEvent`) | checkIn, breakIn, overtimeIn | checkOut, breakOut, overtimeOut | `undefined` / absent → `auto` |
| ZKTeco | punch state (`ZktecoAdapter::directionFor`) | 0, 3, 4 | 1, 2, 5 | absent, 255 → `auto` |
| Suprema | event code | — | 0x2000–0x2FFF | everything else → in |
| Generic | `type` vs `check_in_values` | listed values | any other value | empty → `auto` |

`auto` lets the engine decide: it closes the day's open record (the one a check-out
would close) or, with none open, checks in; a second scan within 5 minutes of the
check-in is a duplicate, not a departure. Until this date the Hikvision webhook
recorded **every** event as a check-in and the pull read `eventType`, a field these
events do not carry — an evening scan opened a second record and a conflict.

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
  number. A person with neither is skipped, and **Send all staff** reports how many.
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
- `POST /employees/{employee}/enrol-on-devices` (`device.update`)

Pinned in `tests/Feature/Device/DeviceCommandQueueTest.php` and
`src/src/test/device-commands-tab.test.tsx`.

## ZKTeco ADMS (2026-10-14)

ADMS is the "cloud server" push protocol in ZKTeco firmware. The terminal calls ETHR, so
it works from any office network to shared hosting, where ETHR cannot reach the
terminal. Register it as **`zkteco_adms`** with its serial number and no address. Then,
on the terminal, open **COMM → Cloud Server Setting**:

- Server address: the application host (shown on the device page).
- Port: 443 with HTTPS on, or 80 for firmware without HTTPS.
- Enable domain name: on.

| Request | ETHR's answer |
|---|---|
| `GET /iclock/cdata?SN=…` | The options: `TimeZone` from the device's zone, `Realtime=1`, `ATTLOGStamp=None`, so a first connection uploads what the device holds |
| `POST /iclock/cdata?SN=…&table=ATTLOG` | Each line `PIN⇥YYYY-MM-DD HH:MM:SS⇥status⇥…` is a punch in device-local time. Direction comes from `ZktecoAdapter::directionFor`. The answer is `OK: <lines>` |
| `POST /iclock/cdata?…&table=OPERLOG` (and others) | `OK: <lines>`, not stored |
| `GET /iclock/getrequest?SN=…` | Waiting commands, `C:<id>:<command>` per line, or `OK` |
| `POST /iclock/devicecmd?SN=…` | Results, `ID=<id>&Return=<code>`. 0 marks the command done |

Commands as the terminal reads them:

- `DATA UPDATE USERINFO PIN=…⇥Name=…⇥Pri=0`
- `DATA DELETE USERINFO PIN=…`
- `SET OPTION DateTime=<packed>`. The packed value is seconds in a calendar of
  12 × 31-day months from 2000, the firmware's own encoding.

A sent command that is not confirmed is offered again after 30 minutes and fails after
three sends. The `.aspx` spellings some firmware uses are routed too.

**Authentication: serial selects, IP allowlist admits.** ADMS sends its serial and nothing
an administrator can configure, so this is the token-less webhook policy, applied
strictly (`AdmsDeviceResolver`):

- The serial is printed on the case and proves nothing on its own.
- An empty allowlist admits nobody.
- A serial held by two devices admits neither.
- The allowlist accepts an IPv4 range (`196.188.0.0/16`), because office broadband
  addresses change. Nothing wider than /8 is accepted.
- A refused attempt for a known serial is remembered for a week with its address. The
  device page shows it, because that address is usually the one the allowlist is missing.

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
2.x protocol. Watch the first device's **Commands** tab and attendance.

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

