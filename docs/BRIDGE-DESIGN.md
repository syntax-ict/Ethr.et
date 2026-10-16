# ETHR Bridge — design (build after the hardware test)

Gap plan Wave 3 #10 (2026-10-16). **A design document, deliberately not an
implementation**: the plan gates the build on owner action O1 — one real
Hikvision terminal and one real ZKTeco ADMS terminal — because every Hikvision
payload shape in this repository is reconstructed from published documentation,
never captured from hardware. Building the agent before the field test would
repeat the mistake the device audit found: an adapter speaking an API the
device does not.

## The problem, precisely

ETHR runs on shared hosting that cannot open a connection into a customer's
LAN, and `DeviceHost` additionally refuses private addresses on the multi-tenant
deployment on purpose (SSRF: a tenant-supplied device host would let the server
probe its own network; `devices.allow_private_hosts` is off in production).
That splits the fleet:

| Device family | Transport | Works from shared hosting? |
|---|---|---|
| ZKTeco ADMS (`zkteco_adms`) | Device pushes to `/iclock/*`, commands ride the reply | **Yes** — live since #248 |
| Generic HTTP / webhook firmware | Device pushes to `/api/v1/devices/webhook/*` | **Yes** (events only, no command channel) |
| Hikvision (ISAPI) | Server pulls `/ISAPI/...` on the device | **No** — the device answers only on the LAN |
| Suprema (BioStar) | Server pulls the BioStar API | **No** — same |

The command queue built in #247 (`device_commands`: `set_time`, `upsert_user`,
`delete_user`; `pending → sent → done/failed/cancelled`) is therefore inert for
exactly the two families that need it most. The bridge exists to carry that
queue, and events, across the boundary — **always outbound from the LAN, never
inbound to it**.

## Shape

One small agent per site, installed on any always-on machine on the same LAN
as the devices (the attendance PC, a Raspberry Pi, the branch server):

```
[Hikvision/Suprema devices] ←LAN (ISAPI/BioStar, device credentials)→ [ETHR Bridge]
                                                                          │ outbound HTTPS only
                                                                          ▼
                                                              https://ethr.et (or on-prem host)
```

The bridge is a **dumb executor**: ETHR decides what should happen
(`DeviceCommandQueue` already enqueues on employee save/terminate and on
"Enrol on devices"); the bridge fetches, performs, reports. No business rules
on the agent, so a bridge is never the thing that must be upgraded for a
payroll or policy change.

### Loop

Every 30 seconds (configurable, 10–300):

1. `POST /api/v1/bridge/heartbeat` — agent version, site label, per-device
   reachability, device clock offsets (the same drift measure ADMS reports, so
   the integrity queue can flag punches from a device more than 2 minutes off).
2. `GET /api/v1/bridge/commands` — pending `device_commands` for the devices
   this bridge serves. Claiming marks them `sent` (existing state).
3. Execute each over ISAPI/BioStar on the LAN; report
   `POST /api/v1/bridge/commands/{publicId}/result` → `done` or `failed` with
   the device's own error text (existing states; no new ones).
4. `POST /api/v1/bridge/events` — attendance events pulled from the devices
   since the last loop, batched, each carrying an `Idempotency-Key`-style
   dedupe id (`device serial + device event id`), replay answering
   `was_duplicate` per convention 10. Identity resolves server-side through
   `IdentityResolver`, exactly as the existing webhook and pull paths do.

Long-polling is a later optimisation, not a requirement: a 30-second interval
matches the ADMS cadence and survives any NAT, proxy or firewall that allows
plain outbound HTTPS.

### Authentication, both directions

- **Bridge → ETHR:** a per-bridge API key through the existing
  `AuthenticateApiKey` / `EnforceApiKeyAbilities` path, with one new module
  ability `bridge` whose semantics live in `ApiKeyAbilities` like the others.
  The key is created on the Devices page ("Add bridge"), shown once, and names
  the branches it serves; `EnsureUserBelongsToTenant` semantics hold because
  the key is tenant-owned. Revoking the key is how a stolen bridge machine is
  cut off — one row, immediate.
- **Bridge → devices:** the ISAPI/BioStar credentials live **only** in the
  bridge's local config file, never uploaded, never echoed into heartbeats.
  ETHR's database holds no LAN credential for bridged devices — the `Device`
  row for a bridged device carries no host/password at all, just serial,
  branch and `via_bridge`.

### Packaging

A single static binary (Go is the working assumption: one file per OS, no
runtime to install, cross-compiled in CI), configured by one file:

```ini
ethr_url   = https://ethr.et
api_key    = ethr_...          # the bridge key, shown once at creation
devices    = [{serial, kind: hikvision|suprema, host, username, password}]
interval   = 30
spool_dir  = ./spool
```

Events spool to disk when ETHR is unreachable and drain in order on
reconnection — the same owner-visible property the PWA's IndexedDB queue gives
phones. The binary ships from this repository's releases; "no paid API
dependencies" holds (Go toolchain in CI is free-tier).

### Capture mode (what O1 actually needs)

`ethr-bridge --capture` performs the read-only loop only (device info, event
pull) and writes every raw request/response pair to `spool_dir/fixtures/`,
redacting credentials. The field test's deliverable is that directory: real
`AcsEvent` JSON from a real terminal, which becomes the test fixtures the
adapters replay — the plan's O1 row verbatim. Capture mode sends **nothing**
to the devices (no time set, no user writes), so it is safe to run on a pilot
site's production terminal during working hours.

## What the bridge is not

- **Not a tunnel.** It exposes no port, forwards no traffic, and cannot be
  made to fetch arbitrary URLs: the allowed HTTP shapes are the fixed
  ISAPI/BioStar calls against the configured device hosts.
- **Not a second brain.** No matching, no engine rules, no retries beyond the
  spool; a conflict is still the server's to resolve.
- **Not required for ZKTeco ADMS**, which keeps pushing directly, or for
  on-prem installs (`ETHR_MODE=single`), where the server usually sits on the
  same LAN and `DEVICE_ALLOW_PRIVATE_HOSTS=true` lets the existing pull
  adapters work unmediated — the bridge is for LAN devices whose server is far
  away.

## Build order, once O1 delivers hardware

1. Captured fixtures land in `api/tests/fixtures/devices/` and the Hikvision
   adapter's reconstructed payloads are confirmed or corrected against them.
2. The four `bridge/*` endpoints (one controller, API-key auth, the `bridge`
   ability, inventory entries for any scope bypasses — expected none: the key
   is tenant-owned, so every query states `tenant_id` from it).
3. The agent, smallest loop first: heartbeat → commands → results → events.
4. Pilot at the O1 site alongside the existing paths; promote when a week of
   punches reconciles.

## Open questions for the owner

- **Suprema priority:** no Suprema customer exists yet; the adapter is
  speculative. Ship Hikvision-only first?
- **Who installs bridges:** ETHR staff at onboarding, or a customer-run
  installer with a setup wizard? Decides how much polish the config step needs.
- **Update channel:** auto-update (simplest for the fleet, needs signing) or
  manual (simplest to build, rots in the field)?
