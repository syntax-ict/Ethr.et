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
| Failed jobs | `SELECT COUNT(*) FROM failed_jobs` | No Horizon UI to surface this. `Queue::failing` still logs to `laravel.log` (`AppServiceProvider::boot()`) — check that too. |
| Cron actually firing | `SELECT MAX(created_at) FROM jobs` should be recent whenever the schedule expects something queued | Cron silently not firing looks identical to "nothing to do" — the only way to tell them apart is to know what *should* have run. |
| Disk usage | Plesk → *Statistics*, or `du -sh ~/ethr/api/storage` over SSH | `FILESYSTEM_DISK=local` means uploads consume the account's own quota — see `ENVIRONMENT.md` "Storage sizing". Nothing alerts before this fills. |
| TLS expiry | `openssl s_client -connect www.ethr.et:443 2>/dev/null \| openssl x509 -noout -dates` | Let's Encrypt certs are 90 days; confirm auto-renewal is actually configured in Plesk rather than assuming it is because it worked once. |

## What changed from the VPS runbook's health story

The VPS `docs/DEPLOYMENT.md` leans on `horizon:status`, `queue:failed` via
`docker compose exec`, and Sentry (optional there too). None of the `docker compose`
commands apply here — there is no container to exec into. Sentry, if configured
(`ENVIRONMENT.md` — inert without B5's Node.js answer for the frontend half, but the
backend half works independently via `SENTRY_LARAVEL_DSN`), is the closest equivalent
to what Horizon's dashboard gave visibility into, and is worth turning on for this
deployment specifically *because* the dashboard is gone.
