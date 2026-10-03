# Device Integration

Attendance devices integrate through the `App\Contracts\DeviceAdapter` contract,
resolved per device by `App\Services\Device\DeviceManager` from `adapter_type`.

## Adapters

| `adapter_type` | class | status |
|---|---|---|
| `hikvision` | `HikvisionAdapter` | ISAPI (events, enrollments, webhook push) |
| `zkteco` | `ZktecoAdapter` | HTTP API |
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

`pullEvents()` throws `App\Exceptions\DeviceRequestFailed` when the device (or
its middleware) is unreachable, answers non-2xx, or answers 2xx with a body that
is not JSON — the three checks in `Services\Device\Concerns\ReadsDeviceEvents`,
which every vendor adapter uses. *(2026-10-03: until then each adapter caught
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
- `connect()`, `getStatus()` and `pullEnrollments()` still answer
  `false` / offline / `[]` on failure. None of them moves a cursor; an empty
  enrollment roster during discovery is misleading but loses nothing.

Pinned in `tests/Feature/Device/VendorAdapterProtocolTest.php`.
