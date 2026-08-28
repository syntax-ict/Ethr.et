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
`PullDeviceEventsJob` with a `sinceOverride`, so the pull is a one-off backfill:

- events are dated at their real event time (`AttendanceInput::$occurredAt`), so
  a 90-day backfill lands 90 days ago, not today;
- the device's incremental cursor (`last_sync_at`) is **left untouched**, so the
  next scheduled sync doesn't skip the gap between the backfill window and now;
- idempotency keys let a backfill safely overlap live syncs.

Note: adapters return up to their per-call cap (~100 events), so `full` history
against a large device needs adapter-side pagination — a documented follow-up
(ONBOARDING_V2.md decision D8).
