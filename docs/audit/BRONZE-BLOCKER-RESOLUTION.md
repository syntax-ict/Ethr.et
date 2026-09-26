# ETHR — Bronze Blocker Resolution (Phase 1)

## Current verification date

**2026-09-25, 15:01–15:04 UTC.** All live readings in this document were taken in that
window. The canary's own report stamps itself `Generated: 2026-09-25T15:03:09+00:00`.

**Target measured:** `www.ethr.et` / `ethr.et`, pinned to **213.55.96.154** with
`curl --resolve www.ethr.et:443:213.55.96.154`, which is the pinning form
`htaccess-canary/RUN-SHEET.md` §2 prescribes so the reading cannot land on a different
server.

**Tooling used — existing, unmodified:** `scripts/hosting-verification/htaccess-canary/`
(its `RUN-SHEET.md` procedure, all eight fetches), plus `nslookup`, `curl` and
`openssl s_client` for the DNS/TLS rows the canary explicitly says it cannot answer.
**No new probe framework was written. No repository file outside `docs/audit/` was touched.**

**Not run, and why:** `scripts/hosting-verification/ethr-hosting-check.php` requires being
uploaded to the account's home directory and executed there (SSH, a Plesk one-off task, or
its token-gated web route). This session has **no shell, no FTP and no panel access** to the
account, so the probe could not be placed. Every row it would answer — PHP extensions,
limits, filesystem, `CREATE TRIGGER`, performance — therefore remains `NOT VERIFIED`. Its
`CREATE TRIGGER` test **is** safe and reversible (temp table → trigger → `DROP TRIGGER` →
`DROP TABLE`), but it needs database credentials and on-host placement; **no improvised
substitute was attempted**, per the Phase 1 constraint.

---

## Evidence table

| Gate / Capability | Current result | Evidence | Confidence | Remaining action |
|---|---|---|---|---|
| **G0-B.1** `mod_rewrite` honoured | **VERIFIED** | `GET /REWRITE_OK` → **200**, canary report prints `[ PASS ] G0-B.1 mod_rewrite honoured`. Report header: `server software : Apache`, `php sapi : fpm-fcgi` | High — direct, the canary's own automatic verdict | None |
| **G0-B.2 (a)** `mod_headers` runs | **VERIFIED** | `X-Ethr-Canary: headers-ok` present on `/canary.php` and on a PHP 404 at `/index.php` | High | None |
| **G0-B.2 (b)** the CSP survives Imunify | **NOT VERIFIED** | Only 2 of the deployment's 7 headers returned (`X-Frame-Options`, `X-Content-Type-Options`). **This proves nothing about the host** — the deployed canary is the **2026-09-18 revision (`eb239f2`), which sets only those two**. Byte-matched: `git show eb239f2:…/.htaccess` sets exactly `X-Ethr-Canary`, `X-Content-Type-Options`, `X-Frame-Options` — precisely the three observed | High confidence in the *non-answer*; zero in any verdict | Re-upload the current canary (`1edaad4`) and re-fetch. This is the trap `RUN-SHEET.md` §7a was written to close, now demonstrated live |
| **G0-B.3 (a)** `<FilesMatch>` + `Require all denied` | **VERIFIED** | `GET /secret.txt.probe` → **403** | High | None |
| **G0-B.3 (b)** `RewriteRule … [F,L]` — **the deployment's own mechanism** | **NOT VERIFIED** | `GET /secret.env.probe` → **404**. RUN-SHEET §4a: *"A 404 is NOT a pass."* The bait was added in `1edaad4` (2026-09-25) and is absent from the deployed 2026-09-18 set | High confidence in the non-answer | **Highest-value single fetch remaining.** Upload `secret.env.probe`, re-fetch. A 200 blocks deployment on every branch |
| **G0-B.4** `Authorization` reaches PHP | **VERIFIED** | `curl -H 'Authorization: Bearer probe' /canary.php` → `[ PASS ] G0-B.4` | High | None |
| **G0-B.5** does a real file shadow the rewrite | **VERIFIED — REWRITE WINS** | `GET /shadow.js` → canary report text, not `// NOT-A-SECRET-JS.`. `/shadow.txt` agrees. `.js` is the authoritative bait per RUN-SHEET §6 | High | None. This is the favourable outcome: `.htaccess` runs for static assets |
| **G0-E** PHP version | **VERIFIED — 8.3.33** | `X-Powered-By: PHP/8.3.33` on a live response. **Upgrades the prior PANEL-READ to a measurement** | High | Extensions/limits still need the probe |
| **G0-E** extensions, `memory_limit`, `max_execution_time` | **NOT VERIFIED** | Probe not placeable this session | — | Run `ethr-hosting-check.php` on the host |
| **Web stack identity** | **VERIFIED** | `Server: nginx` + `X-Powered-By: PleskLin` at the edge; canary reports `server software: Apache`, `php sapi: fpm-fcgi` → **nginx → Apache → PHP-FPM**, which is the topology the canary's B.5 reasoning assumes | High | None |
| **Document root** | **VERIFIED (behaviourally)** | The six canary files answer at `/`, not `/ethr-canary/`. So the served directory is whichever one the canary was uploaded into, and it is the domain root | High for *behaviour*; the panel's *path* remains unread | None for Phase 1 |
| **Application deployed?** | **VERIFIED — NO** | `/` serves Plesk's *Domain Default page* (`Content-Length: 1653`, `Last-Modified: Wed, 23 Sep 2026`). `/index.php` → PHP-FPM `File not found.` | High | Expected; ETHR has not been deployed |
| **G0-A** `/api` reachable without an nginx directive | **CONDITIONAL — mechanism verified, application untested** | See §1 | Medium-high | Deploy and re-fetch |
| **G0-D** timer for `schedule:run` | **PANEL-VERIFICATION-REQUIRED** | See §2. Nothing observable over HTTP | — | Panel |
| **G0-F** `CREATE TRIGGER` | **NOT VERIFIED** | See §3 | — | Probe with DB credentials |
| **Wildcard DNS** | **VERIFIED** | See §4 | High | None |
| **Wildcard vhost** | **CONDITIONAL** — measured absent; owner states it is enabled on subscription payment | See §5 | High for the measurement; **owner testimony** for the cause | Re-measure after payment (§5 has the two commands) |
| **Wildcard TLS** | **VERIFIED — certificate issued** (not bound) | See §6 | High | See §5 |
| **Subdomain quota** | **PANEL-VERIFICATION-REQUIRED** | See §7 | — | Panel |
| **Next.js production runtime** | **CONDITIONAL** | See §8 | Medium | Panel + one fetch |
| **Realtime / Reverb** | **VERIFIED (dependency map)** | See §9 | High | Phase 2 decision |

---

## 1. `/api` routing

**Previous evidence:** `GATE-0-RESULT.md` G0-A — *"neither directive textarea present on the
settings page (2026-09-17) — strong evidence of FAIL, unconfirmed"*, with `:961` pricing the
cross-origin fallback at *"roughly one day becomes one to two weeks, and it needs an
auth-security review."*

**Current evidence (2026-09-25):**

| Exact test | Result |
|---|---|
| `curl -D- https://ethr.et/` | `301` → `https://www.ethr.et/`, `Server: nginx` |
| `curl -D- https://www.ethr.et/index.php` | `404`, `X-Powered-By: PHP/8.3.33`, body `File not found.` — **PHP-FPM answered**, the script simply is not there |
| `curl https://www.ethr.et/api/v1/ping` | `404`, 808-byte Plesk static error page — **no rewrite to `index.php` in force** |
| `curl https://www.ethr.et/REWRITE_OK` | `200` + `[ PASS ] G0-B.1` — **mod_rewrite is honoured in this document root** |
| `curl -H 'Authorization: …' /canary.php` | `[ PASS ] G0-B.4` — **the header reaches PHP** |

**Observed behaviour:** requests for `.php` reach PHP-FPM through Apache; `.htaccess`
rewriting is active; the `Authorization` header survives. The `/api/v1/ping` 404 is fully
explained by ETHR not being deployed — there is no `index.php` and no `.htaccess` rewrite for
`/api`, because neither has been uploaded.

**Status: CONDITIONAL.** Every *mechanism* `docs/deployment/shared-hosting/.htaccess` depends
on to serve `/api` is now verified present:

```apache
RewriteCond %{REQUEST_URI} ^/(api|sanctum)(/|$) [NC]
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
```

`mod_rewrite` (B.1) runs it, PHP-FPM answers it, and `Authorization` (B.4) reaches Sanctum.

**This materially changes the G0-A risk, and the change should be stated precisely.** G0-A
asked whether a *reverse-proxy directive* is available. The answer still appears to be no —
and it now matters far less, because **serving `/api` needs no proxy directive at all**:
Apache + `.htaccess` + PHP-FPM in the same document root is sufficient, and all three are
measured. G0-A's cost was never about `/api` reaching Laravel; it was about the **frontend**
reaching `/api` same-origin — which is §8's question, not this one.

**Remaining uncertainty:** the rewrite has been proven for the canary's own rules, not for
the deployment's `.htaccess`, which has not been uploaded. B.3(b) — the `[F,L]` deny
mechanism in that same file — is still unmeasured (see the evidence table). And §8's
who-serves-`/` question is unresolved.

---

## 2. Scheduler / queue

**Previous evidence:** `GATE-0-RESULT.md` G0-D — *"No Scheduled Tasks / Task Scheduler /
Cron Jobs section exists on the subscription dashboard"* (owner-read 2026-09-18),
**FAIL — strong evidence, one confirmation short**.

**Current evidence: none. Panel state is not observable over HTTP**, and nothing in this
session could read it. **Status: `PANEL-VERIFICATION-REQUIRED`.** The 2026-09-18 reading is
neither confirmed nor contradicted; it stands as the most recent evidence.

**No cron solution was invented.** The repository's existing mechanism was inspected only.

### The relationship between the components, verified from source

Re-counted this session, by parsing rather than by `grep -l`:

| Component | Count | Source |
|---|---|---|
| `Schedule::` entries | **14** (6 `call`, 6 `job`, 2 `command`) | `api/routes/console.php` |
| Job classes in `app/Jobs` | **16** | directory listing |
| …that implement `ShouldQueue` | **16 — all of them** | `grep -rl ShouldQueue api/app/Jobs` |
| Queue names | **4** — `default`, `attendance`, `notifications`, `exports` | `QueueHealth::QUEUES` (`:48`) |

**How they connect:** 12 of the 14 scheduled entries do nothing but dispatch onto those four
queues; the 13th is `ethr:backup` and the 14th is the minute heartbeat
(`QueueHealth::beat()`). `CronRunController::queue` drains **all four by name**, in
`QueueHealth::QUEUES` order, with `--stop-when-empty --max-time=50`; membership of that
constant is the correctness property, because a queue missing from it is a queue nothing
drains. `CronRunController::schedule` runs `schedule:run` and lets Laravel decide what is due.
Both sit behind `throttle:cron` **then** `VerifyCronToken` — order load-bearing — and a
`Cache::lock` returning **409** on overlap.

**So the dependency chain is:** *host timer* → `POST /api/v1/cron/{schedule,queue}` →
`schedule:run` / `queue:work` → 4 queues → 16 jobs. **Only the first link is missing**, and
it is the one link this repository cannot supply. Nothing was modified.

**What can actually be triggered today: nothing.** The endpoints 404 by design when
`CRON_TOKEN` is unset, and ETHR is not deployed. Once deployed, anything that can fetch a URL
on a timer drives them — `docs/deployment/shared-hosting/cron-caller.md` has the mechanics.

---

## 3. `CREATE TRIGGER`

**Previous evidence:** G0-F `NOT VERIFIED`; `GATE-0-RESULT.md:1158` argues a favourable
prior — `TRIGGER` is part of `ALL PRIVILEGES` on a schema and needs no `SUPER`, Plesk's
default is `ALL` on databases it creates — while explicitly calling that *"an argument about
priors, not a measurement."*

**Current evidence: none.**

**Status: `NOT VERIFIED`.**

**Exactly why.** The existing tooling *does* contain a safe reversible test —
`ethr-hosting-check.php` §6 creates a scratch table, creates
`BEFORE UPDATE … SIGNAL SQLSTATE '45000'` on it, records `DB4`, then
`DROP TRIGGER IF EXISTS` and `DROP TABLE IF EXISTS`. It creates no permanent object and
touches no application table. **It could not be run**, for two independent reasons:

1. **The probe must execute on the host.** It needs to be uploaded to `~/`; this session has
   no shell, no FTP and no panel access.
2. **It needs database credentials** (`--db-host/--db-name/--db-user/--db-pass`), which are
   not in the repository and must not be.

Per the Phase 1 constraint, **no improvised production-database test was attempted.** This
row is answered by one probe run, not by anything reachable from here.

---

## 4. Wildcard DNS

**Previous evidence:** PASS, 2026-08-29, re-confirmed 2026-09-17.

**Current evidence (2026-09-25) — re-confirmed independently:**

| Hostname | Resolves to |
|---|---|
| `ethr.et` | `213.55.96.154` |
| `www.ethr.et` | `213.55.96.154` |
| `admin.ethr.et` | `213.55.96.154` |
| `zzq7x-phase1-probe.ethr.et` *(never configured)* | `213.55.96.154` |
| `another-random-cwq91.ethr.et` *(never configured)* | `213.55.96.154` |

**Status: VERIFIED.** Two labels that have never existed anywhere resolve to the account IP,
which is the definition of a wildcard `A` record. Confidence: high.

---

## 5. Wildcard vhost

**Previous evidence:** *"panel accepts `*` per **owner report**, not a measurement; vhost not
yet created"* — **PARTIAL**.

**Current evidence (2026-09-25) — now measured, and it is a FAIL:**

| Exact test | Result |
|---|---|
| `curl -k -D- https://admin.ethr.et/` | **303** → `https://admin.ethr.et:443/login.php` |
| `curl -k -D- https://zzq7x-phase1-probe.ethr.et/` | **303** → `https://zzq7x-phase1-probe.ethr.et:443/login.php` |
| TLS presented on both | `CN=lin6.ethiotelecom.et` — **the server default certificate, not `*.ethr.et`** |

**Status: CONDITIONAL.** *(Was FAILED when first measured; reclassified the same day — see
"Why it is absent" below. The measurement itself is unchanged.)*

Any `*.ethr.et` hostname other than `ethr.et` / `www.ethr.et` falls through to the **server's
default vhost, which is the Plesk control-panel login**. Two consequences, both measured
rather than inferred:

- **A tenant subdomain today serves the Plesk panel login page**, after a certificate name
  mismatch that every browser will interrupt.
- **`admin.ethr.et` — ETHR's reserved platform host — is in exactly that state.**
  `ResolveTenant::isPlatformHost()` and `middleware.ts` both treat it as structural, and it
  currently belongs to Plesk.

**This is the decisive Bronze finding of Phase 1**, because `ResolveTenant`'s docblock names
the subdomain as *"the only selector honoured in production"*. Confidence: high — two
independent hostnames, consistent behaviour, and the certificate identifies which vhost
answered.

**Note what this does *not* say.** It does not say Plesk refuses to create a `*` vhost. It
says **no such vhost exists today**. Those are different findings and only the second is
measured.

### Why it is absent — owner statement, 2026-09-25

> **"wildcard is enable when i pay the subscription fee."**

**This is owner testimony, and it is graded as such.** `docs/deployment/SHARED_HOSTING_PLAN.md`
sets the precedent for exactly this kind of input — the Bronze tier itself is owner-supplied,
and that document records it as *"testimony, not panel output, which this repository grades
PARTIAL wherever else it appears."* The same grading applies here, for the same reason.

**What it changes:** the blocker moves from **technical incompatibility** to **commercial
provisioning precondition**. That is a large and favourable reclassification — nothing about
ETHR's architecture has to change, and no fallback tenant-selector strategy needs designing.

**What it does not change:** the measurement. As of 2026-09-25, 15:03 UTC, no wildcard vhost
exists, and every tenant hostname serves the Plesk panel login. **This row cannot be marked
VERIFIED until it is re-measured after activation**, and converting testimony into a PASS is
precisely the failure mode `GATE-0-RESULT.md` exists to prevent.

**One observation consistent with the account being partially provisioned**, offered as
context rather than corroboration: a valid `*.ethr.et` certificate already exists (§6) while
no vhost binds it. A wildcard certificate with nothing to attach it to is the shape one would
expect of a feature issued but not yet switched on.

**Re-test after payment — two commands, no panel needed:**

```bash
curl -sS -o /dev/null -w '%{http_code}\n' --max-time 20 https://<random-label>.ethr.et/
echo | openssl s_client -connect 213.55.96.154:443 -servername <random-label>.ethr.et 2>/dev/null \
  | openssl x509 -noout -subject -ext subjectAltName
```

**PASS looks like:** a status that is not a 303 to `/login.php`, **and** the served
certificate's subject/SAN showing `*.ethr.et` rather than `CN=lin6.ethiotelecom.et`. Both
halves are required — DNS and the certificate already pass, so either alone would be a false
positive.

---

## 6. Wildcard TLS

**Previous evidence:** *"per-hostname **PROVEN**; wildcard blocked, needs DNS-01"* —
**PARTIAL**.

**Current evidence (2026-09-25) — this contradicts the prior reading:**

```
openssl s_client -connect 213.55.96.154:443 -servername www.ethr.et
  subject = CN=ethr.et
  issuer  = C=US, O=Let's Encrypt, CN=YR1
  notBefore = Sep 16 14:57:06 2026 GMT
  notAfter  = Dec 15 14:57:05 2026 GMT
  X509v3 Subject Alternative Name: DNS:*.ethr.et, DNS:ethr.et
```

**Status: VERIFIED — a wildcard certificate covering `*.ethr.et` exists, is currently valid,
and is served for `ethr.et` and `www.ethr.et`.**

Let's Encrypt issues a wildcard only over **DNS-01**, so the DNS-01 challenge that
`GATE-0-RESULT.md` records as the blocker has evidently been completed — on or before
**2026-09-16**, the certificate's `notBefore`.

**The distinction this section exists to preserve:** the certificate is **issued but not
bound to any wildcard vhost**. `admin.ethr.et` and a random label are both served the
**`lin6.ethiotelecom.et`** default certificate, not this one. So:

- **Wildcard TLS — issuance: VERIFIED.**
- **Wildcard TLS — in force on tenant hostnames: FAILED**, and it fails *because* of §5, not
  for any certificate reason.

Collapsing these into one verdict would have reported either a false PASS (a wildcard cert
exists) or a false FAIL (tenant hostnames have no valid cert). Both would be wrong.

---

## 7. Subdomain quota

**Status: `PANEL-VERIFICATION-REQUIRED`.** Not observable over HTTP, and not inferable.

**Explicitly not assumed:** whether the published Bronze *"5 subdomains"* figure counts a
wildcard vhost as **one** slot, as **five**, or whether wildcards are outside the quota
entirely. **Nothing in this session's evidence bears on it**, and the Phase 0 arithmetic
(*"5 minus `www` and `admin` leaves room for about three tenants"*) is a **conditional** that
only applies if wildcards are unavailable *and* each tenant consumes a slot. It should not be
quoted as a finding until this row is answered.

What §5 and §6 *do* establish is that the question is live rather than academic: the wildcard
certificate already exists, so if a `*` vhost is permitted and counts as one slot, the
capacity concern disappears and §5 becomes a configuration task.

---

## 8. Next.js production runtime

**Traced from source, not assumed:**

| Evidence | Finding |
|---|---|
| `src/next.config.ts:24` | `output: "standalone"` — the build emits `.next/standalone` with its own `server.js` |
| `docker/frontend/Dockerfile` runner stage | `CMD ["node", "server.js"]` — a **persistent Node process** |
| `src/src/middleware.ts` | Runs per request; hostname decides which application a request may reach. **Cannot run without a server** |
| `src/next.config.ts:88`–`100` | `rewrites()` proxies `/api/:path*` and `/sanctum/:path*` to `NEXT_PUBLIC_API_URL` — **executed by the Node server** |
| `src/src/api/client.ts:14` | `baseURL: "/api/v1"` — relative, so the browser's API calls resolve against whatever origin served the page |
| `src/public/sw.js:59` | Service worker intercepts `/api/` **same-origin only** |
| `docs/deployment/shared-hosting/DEPLOYMENT.md` §5 | *"Node.js is the chosen branch — owner decision 2026-09-24."* Static export retained as the fallback |

**Answer to the question posed: NO — the existing frontend production architecture cannot run
on Bronze without a persistent Node server.** `output: "standalone"` plus `middleware.ts` plus
runtime `rewrites()` are three independent hard requirements for a running Node process.

**Status: CONDITIONAL**, because Bronze appears able to provide one: G0-G (panel, 2026-09-22)
read Node **22.23.2**, Application Root `/ethr`, Application Mode `production`, with *Enable
Node.js* and *Run Node.js commands* offered. Nothing was enabled or started, and this session
could not enable it.

**What must change later if the Node branch fails** — precisely, from
`DEPLOYMENT.md` §5's fallback list, which is already written and costed:

1. `next.config.ts`: add `output: "export"`, remove `rewrites()`, move the CSP into
   `.htaccess`'s `mod_headers` block.
2. **Delete `middleware.ts`**; its host-based `/admin` rule is reimplemented in the
   commented BRANCH B block of `docs/deployment/shared-hosting/.htaccess`.
3. `(auth)/layout.tsx`: replace the `headers()` read with `window.location.host`.
4. `generateStaticParams` on the four dynamic routes — **DEPLOYMENT.md records that this step
   does not work as written**: all four are `"use client"`, Next rejects the combination, and
   `[]` still 404s every real id because those ids are tenant data.
5. Marketing pages lose SSR — *"an SEO consideration worth flagging to whoever owns that
   decision, not something to absorb silently."*

**The unresolved question DEPLOYMENT.md §5 names, and this session could not answer: who
serves `/` — Node or PHP?** If Plesk mounts the Node application at the domain root,
`/api/v1/...` may never reach `index.php` and the same-origin arrangement does not exist.
**It is a panel-and-fetch question**: enable the app, fetch one API route and one frontend
route, see which process answers. Phase 1's §1 finding narrows it usefully — Apache +
`.htaccess` + PHP-FPM can serve `/api` unaided — but it does not settle which process owns
the root.

---

## 9. Realtime / Reverb — factual dependency map for Phase 2

**Nothing was removed or replaced.** This is the map only.

### Backend broadcasters — and a correction to Phase 0

**Only 3 of the 7 event classes actually broadcast.** Phase 0's
`docs/audit/*` reported **5**; that figure came from `grep -rl ShouldBroadcast`, which matched
the string inside **comments**. Re-derived this session by reading each class declaration:

| Event | Broadcasts? | Channel |
|---|---|---|
| `AttendanceRecorded` | **Yes** — `implements ShouldBroadcast` | `PrivateChannel("tenant.{tenant_id}")` |
| `DeviceOffline` | **Yes** | `PrivateChannel("tenant.{tenant_id}")` |
| `EmployeeTransitioned` | **Yes** | `PrivateChannel("tenant.{tenant_id}")` |
| `DeviceSyncFailed` | **No** — no `implements` clause | — |
| `PayrollRunFailed` | **No**. Its own comment: *"broadcasting is deployed on neither target."* | — |
| `PayrollProcessed` | **No** | — |
| `TenantCreated` | **No** | — |

This correction **reduces** the realtime surface, and it is recorded rather than quietly
fixed because it is the same counting error the root `CLAUDE.md` documents at length for the
tenant-scope bypasses: a regex over file text cannot tell a declaration from a comment.

### Frontend consumers — there is exactly one

| Consumer | Subscribes to | Listens for | On event |
|---|---|---|---|
| `components/providers/reverb-provider.tsx` | `echo.private(\`user.${userId}\`)` | `BroadcastNotificationCreated` | `invalidateQueries(["notifications"])` + a `toast.info` |

Mounted once, in `app/(dashboard)/dashboard-shell.tsx:31`. **`src/src/lib/echo.ts` and that
provider are the only two files in the frontend that touch Echo or Pusher.**

### The gap this map exposes

**All three broadcasting events publish to `tenant.{id}`, and no frontend code subscribes to
`tenant.*` at all.** The `device.{publicId}` channel authorised in `routes/channels.php` has
no subscriber either. So those three events currently reach **no user interface on any
target**, Reverb running or not.

The single realtime effect a user would notice is the **notification toast** — driven by
Laravel's own notification broadcast on `user.{publicId}`, not by any of the three events.

### The REST/polling fallback already exists

| Surface | Fallback | Location |
|---|---|---|
| Notification unread count | `refetchInterval: 30000` | `features/notifications/api.ts:30` |
| Device list | `refetchInterval: 30000` | `features/devices/api.ts:87` |
| Device dashboard | `refetchInterval: 30000` (×2) | `app/(dashboard)/devices/dashboard/page.tsx:58,64` |
| Admin surfaces | `refetchInterval: 30_000` | `features/admin/api.ts:314` |
| Team dashboard | `refetchInterval: 60000` | `features/dashboard/team-api.ts:28` |
| Payroll run status | conditional `refetchInterval` while processing | `features/payroll/api.ts:122,139` |

`reverb-provider.tsx` also wraps its subscription in `try { … } catch { }` with the comment
*"Reverb unavailable (dev without reverb running) — fall back to polling."* **The fallback is
not theoretical; it is the shipped default path when Reverb is absent.**

**Phase 2 conclusion, stated as fact rather than recommendation:** with
`BROADCAST_CONNECTION=null`, the measurable user-visible loss is that the notification toast
arrives on the next 30-second poll instead of instantly. No feature becomes unreachable.

---

## Decision section

### Confirmed Bronze-compatible

- **PHP 8.3.33** — measured, not panel-read.
- **`mod_rewrite`** (G0-B.1) — so `.htaccess` can route `/api` and `/sanctum` to `index.php`.
- **`mod_headers`** (G0-B.2 a) — the marker survives.
- **`<FilesMatch>` deny** (G0-B.3 a) — 403 on the bait.
- **`Authorization` passthrough** (G0-B.4) — Sanctum's transport works.
- **`.htaccess` runs for static assets** (G0-B.5 = REWRITE WINS) — security headers and
  `Cache-Control: immutable` will reach `.js`/`.css`/`.woff2`.
- **nginx → Apache → PHP-FPM** with per-directory `.htaccess` honoured.
- **Wildcard DNS.**
- **A valid `*.ethr.et` wildcard certificate**, already issued.
- **Serving `/api` needs no reverse-proxy directive** — Apache + PHP-FPM suffice.

### Confirmed Bronze-incompatible

**Nothing.** The one candidate was wildcard vhost routing, and it is not an incompatibility —
see the next list.

### Blocked on commercial provisioning, not on technology

- **Wildcard vhost routing.** Measured absent 2026-09-25: every `*.ethr.et` hostname except
  `ethr.et`/`www.ethr.et` lands on the Plesk panel login with a mismatched certificate,
  `admin.ethr.et` included. **Owner states it is enabled on payment of the subscription fee**
  (§5). Treated as a provisioning precondition — **no ETHR code or architecture change is
  implied**, and the fallback tenant-selector question Phase 0 raised does not need designing.
  Re-measure after activation with the two commands in §5; until then it stays **CONDITIONAL**,
  not VERIFIED.

### Still requiring host/provider evidence

| Item | Mechanism |
|---|---|
| **G0-B.3 (b)** the `[F,L]` deny mechanism | Upload `secret.env.probe`, one fetch. **Do this first** |
| **G0-B.2 (b)** CSP survival past Imunify | Re-upload the current canary, one fetch |
| **G0-F `CREATE TRIGGER`** | `ethr-hosting-check.php` with DB credentials, on the host |
| **G0-E** extensions, `memory_limit`, `max_execution_time` | Same probe run |
| **G0-D** timer availability | Panel |
| **Subdomain quota** and whether a `*` vhost may be created | Panel |
| **Who serves `/`** once the Node app is enabled | Panel + two fetches |
| **G0-H** SMTP, **G0-I** DB version/charset, **G0-J** performance | Same probe run |

### Requires application change

- **Nothing, on current evidence.** No Phase 1 finding requires a code change. The static-export
  fallback (§8) would require five, and it is *not* triggered — the Node branch remains chosen
  and unrefuted.
- The wildcard-vhost failure is a **hosting configuration** matter, not an application one.
  If it proves impossible, the tenant-selector strategy becomes an architecture question for a
  later phase — explicitly out of Phase 1 scope, and not designed here.

### Can be deferred without blocking installation

- **Reverb / realtime.** Three broadcasting events with zero frontend subscribers, one toast
  backed by a 30-second poll that already ships.
- **Off-host backup retention** (`--off-host` wired, not configured).
- **The canary's own cleanup** — `RUN-SHEET.md` §9 asks for the directory to be deleted after
  recording. It is still live at the document root, and it is designed to disclose nothing.

### Bronze production-readiness

**NOT production-ready — but no longer for any reason intrinsic to Bronze.** Phase 1 found
**zero confirmed technical incompatibilities.** What stands between this account and a
deployment is one commercial precondition (wildcard activation, §5) and eight unmeasured
capability rows — including `CREATE TRIGGER`, which aborts `migrate` by design, and the deny
mechanism that protects `api/.env`.

That is a materially better position than Phase 0 projected, and the reason is worth stating:
**every genuinely architectural worry either dissolved or shrank.** `/api` needs no proxy
directive; the realtime cost is one toast's latency; the wildcard certificate already exists;
and `mod_rewrite`, `mod_headers` and `Authorization` passthrough are all confirmed working.

---

## 11. Phase 1 status

**COMPLETE — 2026-09-25.**

Both Phase 1 objectives were met: the existing verification tooling was used without
modification or duplication, and current evidence now exists for the four blocker areas the
phase was scoped around. Five host capabilities moved from `NOT VERIFIED` to `VERIFIED`, one
moved from panel-read to measured, and eight discrepancies against existing documentation were
recorded without rewriting any of it (§10).

**Carried into Phase 2 as open items** — none of which blocked closing this phase:

| Item | Type | Cost to close |
|---|---|---|
| Wildcard vhost activation | Commercial | Owner action, then two commands (§5) |
| B.3(b) `[F,L]` deny bait | Measurement | Re-upload the current canary, one fetch |
| B.2(b) CSP survival | Measurement | Same upload, same fetch |
| `CREATE TRIGGER`, extensions, limits, SMTP, DB version, performance | Measurement | One `ethr-hosting-check.php` run with DB credentials |
| G0-D timer availability | Panel reading | Panel |
| Subdomain quota | Panel reading | Panel |
| Who serves `/` | Measurement | Enable the Node app, two fetches |

**No application code, dependency, migration or Laravel configuration was changed in Phase 1.**

---

## 10. Discrepancies against existing documentation

**Recorded here only. No existing document was rewritten, and no historical evidence was
erased.**

| # | Topic | Previous evidence | Current evidence | Status |
|---|---|---|---|---|
| 1 | **Wildcard TLS** | `GATE-0-RESULT.md` G0-C: *"wildcard blocked, needs DNS-01"*, PARTIAL | `*.ethr.et` SAN on a Let's Encrypt certificate valid 2026-09-16 → 2026-12-15, served for the apex | **Superseded.** The certificate exists. Recommend G0-C's TLS row be split into *issuance* (VERIFIED) and *binding* (FAILED) |
| 2 | **Wildcard vhost** | *"panel accepts `*` per owner report, not a measurement; vhost not yet created"*, PARTIAL | Random labels 303 to `/login.php` under the `lin6.ethiotelecom.et` certificate | **Upgraded from PARTIAL to measured FAILED.** The prior reading was right that it was not created; this is the first observation of what happens instead |
| 3 | **PHP version** | `GATE-0-RESULT.md` G0-E: **PANEL-READ** 8.3.33, *"not probe output"* | `X-Powered-By: PHP/8.3.33` on a live response | **Confirmed, and the grade improves** from panel-read to measured |
| 4 | **G0-B.1–B.5** | *"The canary is uploaded; **no fetch has been performed**"* | Eight fetches performed. B.1 ✅, B.2(a) ✅, B.3(a) ✅, B.4 ✅, B.5 ✅ REWRITE WINS; B.2(b) and B.3(b) unanswerable against the deployed revision | **Four gates newly VERIFIED.** The register's own caution was correct — a file's existence is not a measurement |
| 5 | **Deployed canary revision** | RUN-SHEET assumes the current six-file set | Header set byte-matches `eb239f2` (2026-09-18); `secret.env.probe` 404s | **New finding.** The live canary predates `1edaad4`, so the two baits added to close a known false-pass are the two that are missing |
| 6 | **`ShouldBroadcast` event count** | Phase 0 audit documents (this repository, 2026-09-25): *"5 `ShouldBroadcast` events"* | **3** implement it; 2 matched on comment text | **My own Phase 0 figure was wrong.** Corrected here and in the compatibility matrix |
| 7 | **G0-A reverse proxy** | *"strong evidence of FAIL"*, with a one-to-two-week cross-origin contingency | Still no directive field observed — **and `/api` does not need one**; Apache + `.htaccess` + PHP-FPM are measured sufficient | **Not contradicted; re-scoped.** G0-A's real cost attaches to §8's who-serves-`/` question, not to `/api` reaching Laravel |
| 8 | **`admin.ethr.et`** | Treated throughout as ETHR's reserved platform host | Currently serves the **Plesk panel login** | **New finding**, a direct consequence of §5 |

---

## Related

- [`BRONZE-COMPATIBILITY-MATRIX.md`](BRONZE-COMPATIBILITY-MATRIX.md) — updated with these readings
- [`../deployment/GATE-0-RESULT.md`](../deployment/GATE-0-RESULT.md) — the authoritative register; **not modified by Phase 1**
- [`../../scripts/hosting-verification/htaccess-canary/RUN-SHEET.md`](../../scripts/hosting-verification/htaccess-canary/RUN-SHEET.md) — the procedure followed
- [`../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md`](../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md)
- [`TENANT-ISOLATION-AUDIT.md`](TENANT-ISOLATION-AUDIT.md) — why the subdomain selector matters
