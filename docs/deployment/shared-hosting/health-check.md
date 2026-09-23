# Health Checking — Shared Hosting

No uptime-monitoring infrastructure exists yet for this deployment (the VPS's
Horizon dashboard is gone by design — see `docs/SHARED_HOSTING_AUDIT.md` §F). This is
what to actually watch instead, and why each one matters here specifically.

## The one built-in endpoint

```bash
curl https://www.ethr.et/api/v1/health
```

Point any external uptime service (UptimeRobot, a cron `curl` + email, whatever's
already in use) at this. It reports `database`, `database_read` (will read
`not_configured` — correct, there is no replica here, not a fault), `cache`, `queue`,
`storage`, each independently. **This endpoint was specifically fixed for this
migration** (commit `80cac67`) to check the *configured* store/disk rather than a
hardcoded `redis`/`minio` name — before that fix it would have reported this exact
deployment as permanently unhealthy regardless of whether anything was actually wrong.

## What has no dashboard and needs a manual/cron check instead

| What | How | Why it matters here |
| --- | --- | --- |
| Failed jobs | `SELECT COUNT(*) FROM failed_jobs` — **or `queue_detail.failed_jobs` from the endpoint above**, which needs no query console (`QueueHealth::snapshot()` counts the same table) | No Horizon UI to surface this. `Queue::failing` still logs to `laravel.log` (`AppServiceProvider::boot()`) — check that too. Reported separately from "nothing is running" on purpose: a failed job means something ran and broke, which needs a different response. |
| Cron actually firing | ~~`SELECT MAX(created_at) FROM jobs`~~ **— there is no cron here. Read `queue_detail` from the endpoint above instead; see the section below** | Cron silently not firing looks identical to "nothing to do" — the only way to tell them apart is to know what *should* have run. That reasoning still holds; the instrument has changed, and the SQL form assumed a query console this account has not been confirmed to have (blocker **B-5**). |
| Disk usage | Plesk → *Statistics*. **Not `du` over SSH — SSH is Forbidden on this subscription** (Hosting Settings, owner-read 2026-09-17) | `FILESYSTEM_DISK=local` means uploads consume the account's own quota — see `ENVIRONMENT.md` "Storage sizing". Nothing alerts before this fills. |
| TLS expiry | `openssl s_client -connect www.ethr.et:443 2>/dev/null \| openssl x509 -noout -dates` | Let's Encrypt certs are 90 days; confirm auto-renewal is actually configured in Plesk rather than assuming it is because it worked once. |

## Watching the thing that actually drives the background half

Added 2026-09-23. The table above was written when a Plesk cron entry was the assumed
runner. **There is no cron entry on this account** — **G0-D is FAIL**, the subscription
dashboard has no Scheduled Tasks section (owner-read 2026-09-18,
`../GATE-0-RESULT.md`). The scheduler and the queue are driven instead by an **external
caller** hitting `POST /api/v1/cron/schedule` and `POST /api/v1/cron/queue`. Which caller is
**unchosen** — question **Q6** in [`../SHARED_HOSTING_PLAN.md`](../SHARED_HOSTING_PLAN.md)
§5.3a.

So there are now **two** things to watch, and the second is not on this host:

| Watch | How | Why |
|---|---|---|
| The application | `GET /api/v1/health` — already above | Unchanged |
| **The background half** | the same response, field **`queue_detail`** | Carries scheduler staleness and per-queue oldest-job age. **This is the only route to it on this account:** `ethr:queue:check` is a CLI command and there is no CLI |
| **The caller itself** | in the caller's own console | A caller that is disabled, rate-limited or holding a stale token leaves **no trace on this host**. Its silence shows up here only as the heartbeat going stale |

**Three things to know before reading a result as a fault:**

- **404 from the endpoints means check `CRON_TOKEN` first**, not that the deploy is broken.
  `VerifyCronToken` 404s on an unset, too-short or mismatched token on purpose, so that an
  unconfigured deployment does not confirm the routes exist — which means a misconfigured
  caller and a missing route look identical from outside.
- **409 is normal.** The previous drain is still running.
- **A fresh heartbeat does not mean the queue is draining.** The two endpoints are separate
  calls; drive and verify both. `cron.txt` makes the same point about the two cron lines.

Full treatment, including the thresholds and why the "scheduler has never run" message names
a panel section this account does not have:
[`../../operations/QUEUE-MONITORING.md`](../../operations/QUEUE-MONITORING.md).

## What changed from the VPS runbook's health story

The VPS `docs/DEPLOYMENT.md` leans on `horizon:status`, `queue:failed` via
`docker compose exec`, and Sentry (optional there too). None of the `docker compose`
commands apply here — there is no container to exec into. Sentry, if configured
(`ENVIRONMENT.md` — inert without B5's Node.js answer for the frontend half, but the
backend half works independently via `SENTRY_LARAVEL_DSN`), is the closest equivalent
to what Horizon's dashboard gave visibility into, and is worth turning on for this
deployment specifically *because* the dashboard is gone.
