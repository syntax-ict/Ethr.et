# Pre-Cutover Checklist

Run through this after `DEPLOYMENT.md` steps 1–7, **before** step 8 (DNS cutover).
Technical/infrastructure checks — the full feature-acceptance list lives in
`docs/PRODUCTION_CHECKLIST.md` and is a separate pass, done after cutover against the
real domain.

## Environment

- [ ] `.env` on the server matches `ENVIRONMENT.md` line for line — no leftover
      `REDIS_*`/`MINIO_*`/`REVERB_*` values from copying the VPS template verbatim
- [ ] `APP_ENV=production`, `APP_DEBUG=false` — a stack trace in a JSON response is a
      disclosure bug, not a convenience
- [ ] `APP_KEY` is set and was generated on *this* deployment, not copied from the VPS
      (copying it would let this deployment decrypt VPS-encrypted data it's never
      supposed to see, and vice versa)
- [ ] `SESSION_SECURE_COOKIE=true` and the site is actually served over HTTPS —
      together these determine whether login can work at all (see `AUTH_URL`/TLS note
      below)

## Database

- [ ] `php artisan migrate:status` — every migration `Ran`, none `Pending`
- [ ] Specifically confirm `2026_07_22_000001_restrict_audit_log_to_insert_only`
      succeeded, not just that the run as a whole exited 0 — if H1 denies `TRIGGER`,
      this migration aborts the *entire* run by design, so a green `migrate:status` on
      everything up to it while this one shows `Pending` is exactly what a blocked
      deployment looks like. See `docs/AUDIT_LOG_INTEGRITY_DECISION.md`.
- [ ] `SELECT @@character_set_server` reports `utf8mb4` — required for Amharic; if it
      doesn't, Amharic text truncates or corrupts silently rather than erroring
- [ ] `ProductionSeeder` ran (plans, permissions, templates exist) — **not**
      `DatabaseSeeder`, which also creates a demo tenant with known credentials and must
      never run here

## Application

- [ ] `GET /api/v1/ping` → 200
- [ ] `GET /api/v1/health` → every service reports a true state (see `health-check.md`)
- [ ] `POST /api/v1/auth/login` against the freshly-created super admin succeeds and
      sets a session cookie (`assertAuthenticatedAs`-equivalent — check the response
      actually authenticates, not just that it returns 200; see the
      [[ethr-green-tests-hide-bugs]] class of bug this project has hit before)
- [ ] A file upload round-trips: upload an employee document, then fetch its signed URL
      and confirm it resolves — this is the one path that changed disk (`local` instead
      of `minio`) and is worth confirming explicitly rather than assumed from the code
      review alone

## Public paths

The document root serves the API and the public site from one directory
(`DEPLOYMENT.md` step 4a), so a file copied there silently overrides what the
application would have produced. These four checks are the only thing that
distinguishes "the rules are in place" from "the rules are ignored and nothing said so".

- [ ] `curl -s https://www.ethr.et/robots.txt` contains **both** `Disallow: /admin` and
      a `Sitemap:` line. If it reads `User-agent: * / Disallow:` with nothing else, the
      backend's `api/public/robots.txt` was copied into `~/httpdocs/` — remove it and
      redeploy the frontend. This is the silent one: the site is fully functional either
      way, and nothing logs the difference.
- [ ] `curl -si https://www.ethr.et/sitemap.xml | head -1` → 200, and the body is XML
      with `<urlset`, not an HTML 404 page
- [ ] `curl -si https://www.ethr.et/api/v1/ping | head -1` → 200 — proves the three
      repointed `require` paths in `~/httpdocs/index.php` all resolve (step 4a)
- [ ] `php artisan down` on the server, then `curl -si https://www.ethr.et/ | head -1`
      → 503; `php artisan up` afterwards. A 200 here means line 9 of `index.php` was not
      repointed, and maintenance mode is inert. Do this **before** cutover, never after.

## TLS

- [ ] `https://www.ethr.et` presents a valid certificate — no browser warning
- [ ] If the wildcard vhost has been created (see `DEPLOYMENT.md` step 8), a subdomain
      that has never been visited before also presents a valid certificate — this is
      what actually proves the per-tenant TLS path works end to end, not just that a
      cert exists for `www`

## Cron / queue

> **Rewritten 2026-09-23. Both boxes below assumed a cron entry and a command line, and
> the target account has neither** — **G0-D is FAIL** (no *Scheduled Tasks* section) and
> **SSH is Forbidden**. `php artisan schedule:list` has nothing to run it on. The scheduler
> and the queue are driven by an **external caller** over HTTP instead; the procedure is
> [`cron-caller.md`](cron-caller.md), and which caller is still open question **Q6**.
> The original boxes are kept struck through, because they are the right checks on any host
> that *does* offer cron.

- [ ] ~~`php artisan schedule:list` shows all 14 entries (only meaningful once cron is
      actually configured per `DEPLOYMENT.md` step 6)~~ **No CLI on this account.** The
      equivalent evidence is `GET /api/v1/health` → `queue_detail.scheduler.last_run_at`
      moving after the caller fires
- [ ] `POST /api/v1/cron/schedule` returns **200** with `status: "ok"` and `exit_code: 0`.
      A **404 means check `CRON_TOKEN` first** — unset, under 32 characters, or mismatched
      all 404 by design, and are indistinguishable from "no such route"
- [ ] `POST /api/v1/cron/queue` returns **200** the same way. **Both endpoints, not one:**
      a caller wired only to the scheduler leaves every queued job unrun while the
      heartbeat stays fresh
- [ ] ~~After the first minute the cron entry has had to fire,~~ **after the caller's first
      tick,** `SELECT * FROM jobs` and `SELECT * FROM failed_jobs` — confirm jobs are being
      picked up and, just as important, that nothing is failing silently on first contact
      with a real MySQL table instead of the SQLite test suite. *(Whether this account has
      any SQL console at all is blocker **B-5**, still open — `queue_detail.failed_jobs`
      from the health endpoint carries the same count without one.)*

## Security

- [ ] `curl https://www.ethr.et/.env` → 404 or 403, never the file contents (moot under
      the recommended layout since `.env` is outside `httpdocs` entirely — check anyway,
      in case the document root ever changes)
- [ ] `curl https://www.ethr.et/.git/config` → same
- [ ] `GET /api/docs` (Scramble) is refused outside local — confirm `APP_ENV=production`
      actually disables it here, not just in theory
