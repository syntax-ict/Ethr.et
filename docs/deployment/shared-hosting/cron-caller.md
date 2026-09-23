# The external cron caller — from "Q6 answered" to background work running

**Status: the endpoints exist and nothing has ever called them on the target account.**
This file is the step between choosing a caller and having a working background half. It
was missing: [`../SHARED_HOSTING_PLAN.md`](../SHARED_HOSTING_PLAN.md) §5.3a lays out the
*trade* between callers, [`cron.txt`](cron.txt) gives the *endpoint mechanics*, and §5.5
step 11 says *"point an external caller at both cron endpoints"* — but nothing joined them
into a procedure.

**Which caller is not decided here.** That is question **Q6**, and it is the owner's: §5.3a
sets out four candidates with their trade-offs, and its recommendation is explicitly *"not
the obvious one"*. Everything below is caller-independent except §3, which says what each
candidate needs and stops there.

---

## Why this exists at all

**G0-D is FAIL.** There is no *Scheduled Tasks* section on the subscription dashboard
(owner-read 2026-09-18, [`../GATE-0-RESULT.md`](../GATE-0-RESULT.md)), and SSH is
**Forbidden**. So nothing on the host starts `schedule:run` or `queue:work`. Two endpoints
exist to be driven from outside instead:

```
POST https://ethr.et/api/v1/cron/schedule
POST https://ethr.et/api/v1/cron/queue
```

**Both, not one.** Eleven of the fourteen entries in `routes/console.php` do nothing but
insert rows into the `jobs` table, and all 16 classes in `app/Jobs` implement `ShouldQueue`.
A caller wired only to `/cron/schedule` leaves every queued job unrun while looking
perfectly healthy — see §5 and `cron.txt`, which makes the same point about the two cron
lines it would have needed.

---

## 1 · Generate the token

`CRON_TOKEN` must be **at least 32 characters** or the routes disable themselves —
`VerifyCronToken` logs `CRON_TOKEN is shorter than the configured minimum` and 404s
(**MEASURED**, `config/cron.php:31`, `VerifyCronToken:49`). A short token is worse than
none, because it looks configured.

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

**Run that somewhere else.** `cron.txt` gives the same line, and on this account there is
no command line to run it on — that is the whole reason this file exists. Any machine will
do; the value is what matters, not where it was generated.

## 2 · Put it on the host

Plesk environment variable, `CRON_TOKEN`. Not a file in the document root, and not in the
repository — `.env` files and `APP_KEY` literals are refused by the pre-push hook, and the
same judgement applies here.

**Until it is set, the routes 404 and the caller cannot tell that from a broken deploy.**
See §4.

## 3 · Configure the caller — the part that depends on Q6

**Not decided. §5.3a's table is the comparison; the choice is the owner's.** What each
candidate needs, and nothing more:

| Caller | Where the token goes | What to set |
|---|---|---|
| **GitHub Actions** | repository or environment **secret** | a `schedule:` workflow POSTing both URLs. Its documented minimum is **5 minutes** and delivery is best-effort *(ASSUMED — §5.3a marks every cadence claim there ASSUMED, and this repository measures none of them)* |
| **The VPS already in hand** | a file readable only by the cron user | two `crontab` lines. **Choosing this re-opens Option A** — §5.3a's recommendation explains why that is a strategic decision, not an operational one |
| **A third-party cron service** | that service's dashboard | two jobs. A stranger then holds a bearer credential — §5.3a states the blast radius |
| **A laptop** | locally | not a production answer; named in `config/cron.php` for completeness |

Present the token as the **`X-Cron-Token` header** wherever the caller can set headers.
Where it cannot, `?token=…` on the query string is accepted — and puts the token in access
logs and `Referer` headers, so treat a query-form token as rotatable from day one.

**POST, not GET.** A GET would be followed by a crawler or a link prefetcher.

## 4 · Verify the first tick — do not assume it worked

Three checks, in order. The first two need nothing but the caller's own log.

1. **`POST /api/v1/cron/schedule` returns 200**, with `status: "ok"` and `exit_code: 0`.
   The body also carries `duration_ms` and up to 2000 characters of Artisan output
   (**MEASURED**, `CronRunController::tail()`).
2. **`POST /api/v1/cron/queue` returns 200** the same way. It runs
   `queue:work --stop-when-empty --max-time=50` over `QueueHealth::QUEUES`.
3. **`GET /api/v1/health` shows the heartbeat moving.** Unauthenticated, behind
   `throttle:health`. Read `queue_detail.scheduler.last_run_at` — it should be within a tick
   of when the caller fired, and `queue_detail.status` should read `healthy`.

**Check 3 is the one that proves the loop closed**, because 1 and 2 only prove the request
was accepted. `ethr:queue:check` would be the natural tool and **there is no CLI on this
account** — the endpoint is the instrument. See
[`../../operations/QUEUE-MONITORING.md`](../../operations/QUEUE-MONITORING.md).

### What each response code means

| | |
|---|---|
| **200** | Ran. Read `status` and `exit_code` — a 200 with `status: "failed"` means the command ran and returned non-zero |
| **409** | The previous run of *that task* is still going, and the lock (`CRON_LOCK_SECONDS`, default 110 s) refused a second. **Normal under load, not a fault** — a caller that treats non-200 as failure will alarm for no reason |
| **404** | `CRON_TOKEN` is unset, shorter than 32 characters, or did not match. **Deliberately indistinguishable from "no such route"**, so that an unconfigured deployment does not advertise these endpoints. So: **a 404 here means check the token first**, not that the deploy is broken |
| **429** | More than 10 requests a minute from one IP (`Limit::perMinute(10)->by(ip)`) |

## 5 · What to watch afterwards

Covered in full by [`../../operations/QUEUE-MONITORING.md`](../../operations/QUEUE-MONITORING.md)
and [`health-check.md`](health-check.md). The three that are specific to having an external
caller:

- **A fresh heartbeat does not mean the queue is draining.** The two endpoints are
  independent calls. Verify both, every time.
- **The caller's silence leaves no trace on this host.** A disabled workflow, an expired
  billing card, a suspended account — none of it shows up here except as the heartbeat going
  stale.
- **The thresholds were set for a one-minute cron** (900 s scheduler staleness, 1800 s
  oldest job — `QueueHealth::snapshot()`). **They are not changed anywhere in this
  repository**, because the right value depends on which caller is chosen. Re-check both
  once Q6 is answered, and expect to widen rather than narrow.

## 6 · Rotation

**Manual on both sides**, and there is a window in between where background work is dead and
nothing says so. Change `CRON_TOKEN` in the Plesk environment and the caller's secret as
close together as you can, then re-run §4's three checks. A query-form token should be
rotated on a schedule rather than on suspicion, since it has been in access logs all along.

---

## What this file does not claim

**None of it has ever been executed on this account.** The endpoints are **MEASURED** in the
code; the procedure above is derived from that code and from the panel facts recorded in
`../GATE-0-RESULT.md`. No request has been made, no token has been set, and no caller has
been configured. It is a procedure, not a record.
