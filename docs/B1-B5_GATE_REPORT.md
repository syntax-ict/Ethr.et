# B1–B5 Gate Report

**Date:** 2026-08-29 (updated after external DNS/TLS probing of the live `ethr.et` zone)

**Owner decision, 2026-08-29: NO VPS.** Options A (remain on VPS), C (hybrid) and D
(Ethio Telecom VPS) are withdrawn by the owner. The target is the existing **Ethio
Telecom Linux Bronze** account, upgrading tier if required. Gate failures therefore no
longer resolve to "→ Option A"; they resolve to a **product consequence** the owner must
weigh. That is recorded per gate below.

## Target account (supplied by owner, 2026-08-29)

```
Username    etrhet
Server      line6.ethiotelecom.et   (internal name — does not resolve publicly)
IP          213.55.96.154           (AS24757 Ethio Telecom, Addis Ababa)
Plan        Linux Bronze (shared, Plesk)
```

| Gate | Subject | Status | On failure |
| --- | --- | --- | --- |
| **B1a** | Wildcard **DNS** | ✅ **VERIFIED — PASS** | — |
| **B1b** | Wildcard **vhost** in Plesk | ⚠️ **PARTIAL — downgraded 2026-09-18** (~~✅ VERIFIED — PASS~~) Rests on an **owner report** that `*` was accepted, not on recorded output, and the vhost **has never been created**. `GATE-0-RESULT.md` grades the same fact **PARTIAL** under its own rule that *testimony is not output* — same defect class as the SSH row below. Resolved by creating the subdomain and recording what the panel does (queue item 8). | — |
| **B2** | Wildcard TLS | ⚠️ **PARTIALLY VERIFIED** — LE works, wildcard needs DNS-01 | HTTP-01 per-tenant — **proven on this account** |
| **B3** | Cron | 🔲 NOT VERIFIED | Scheduler/queue over authenticated HTTP endpoint |
| **B4** | PHP ≥ 8.2 + extensions + GD | ⚠️ **PARTIAL** — version **VERIFIED 8.3.33** (panel, 2026-09-17); the **20 mandatory extensions and the limits are still unmeasured** and need the probe. Now gate **G0-E**. | Blocking — no fallback |
| **B5** | Node.js runtime | 🔲 NOT VERIFIED | Static export (Option B2) |
| **H1** | `CREATE TRIGGER` privilege | 🔲 NOT VERIFIED | See `AUDIT_LOG_INTEGRITY_DECISION.md` |

### Server capability observed externally, 2026-08-29

| Port | State | Meaning |
| --- | --- | --- |
| 80 | OPEN | HTTP |
| 443 | OPEN | HTTPS |
| **22** | **OPEN** | **SSH reachable** — a prerequisite for Composer/artisan on the server. Whether *this account's* shell is enabled is still a panel question (Plesk can set `/bin/false`). |
| 8443 | OPEN | Plesk panel confirmed |
| 21 | closed | No plain FTP |
| 3306 | **refused** | MySQL **not** exposed to the internet — correct, and means DB connections must be local (`DB_HOST=localhost`) |

Stack: `Server: nginx`, `X-Powered-By: PleskLin`.

### The `ethr.et` vhost is already live on this server

Verified by resolving the hostname to `213.55.96.154` manually (before any DNS change):

```
https://www.ethr.et/   -> 200 OK, Plesk placeholder page (Last-Modified 2026-08-07)
http://ethr.et/        -> 301 -> https://www.ethr.et/
http://zzq7x.ethr.et/  -> 200, but the SERVER DEFAULT page (Last-Modified 2024-09-05)
```

The third line confirms the wildcard vhost is **not yet created** — consistent with the
owner's note that `*` is accepted but will be added later.

### ✅ B2 — Let's Encrypt is already working on this account

```
Subject : CN=ethr.et
Issuer  : CN=YR2, O=Let's Encrypt, C=US
Valid   : 2026-08-07 -> 2026-11-05
SAN     : ethr.et, www.ethr.et
```

A valid, auto-issued Let's Encrypt certificate is **already in place**. It is
per-hostname, **not** wildcard — which both confirms the DNS-01 obstacle and, more
usefully, **proves the HTTP-01 fallback path works on this exact account**. Per-tenant
certificate issuance is therefore a demonstrated capability, not a hypothesis. Its only
cost is automation at tenant provisioning and Let's Encrypt's 50-certs-per-week ceiling.

### ✅ Filesystem layout — verified from the Plesk File Manager, 2026-08-29

```
~/                     home, user ethret : psaserv
├── .composer/         <- Composer HAS been run on this account
├── .ssh/              <- NOT what this means; see the 2026-09-17 note below
├── .trash/
├── error_docs/
├── httpdocs/          <- DOCUMENT ROOT
│   ├── .well-known/   <- ACME HTTP-01 challenge path (matches the live LE cert)
│   ├── cgi-bin/  css/  img/  public/  et/
└── logs/
```

Three gates resolve from this:

| Item | Result | Evidence |
| --- | --- | --- |
| **W6** — private files storable outside the document root | ✅ **VERIFIED PASS** | The home directory is a level *above* `httpdocs`. `.env`, `storage/` and the whole Laravel app can live there, unreachable over HTTP by construction. |
| **P4** — Composer available | ⚠️ **Strong evidence, and the stated confirmation is now impossible** | `~/.composer` exists — Composer has been executed under this account. ~~Confirm with `composer --version`.~~ That needs a shell, and SSH is Forbidden (below). Composer *is* present as a Plesk **extension**; whether it is reachable as a CLI binary is unverifiable without a shell. Tracked as `HOSTING_VERIFICATION_CHECKLIST.md` P4 = PARTIAL. |
| **SSH** — shell access | ❌ **DISPROVED 2026-09-17 — this row was wrong** | ~~`~/.ssh` exists and port 22 is open. Plesk creates this when shell access is provisioned. Confirm by logging in.~~ **Hosting Settings reports SSH access = `Forbidden`, and Dev Tools offers no Terminal.** See the correction below. |

#### The `~/.ssh` inference was wrong — corrected 2026-09-17/18

The tree listing above annotated `.ssh/` as *"SSH access provisioned"*, and the row above
graded that ✅. Both were **inference from a directory's existence**, which this document's
own standard rejects — and the measurement went the other way.

**Plesk creates `~/.ssh` regardless of whether shell access is enabled**, and an open port
22 belongs to the *server*, not to this account: the host serves many subscriptions and
answers on 22 for whichever of them do have shell access. Neither fact says anything about
`ethret`.

This matters beyond one row, because it is the root of two live blockers:

- **B-1** — the capability probe has no shell route. Its documented fallback is Plesk →
  Scheduled Tasks, which depends on **G0-D**, unverified.
- **B-4** — nothing runs `artisan`, so `key:generate`, `migrate`, `db:seed` and
  `ethr:create-admin` have no mechanism. This also takes five checks off
  `deploy-checklist.md` and the `SELECT` in `ROLLBACK_RUNBOOK.md` Scenario B.

The row said *"Confirm by logging in."* Nobody did, for nineteen days, and the green tick
read as settled in the meantime. That is the failure mode, not the wrong guess.

`httpdocs/.well-known/` independently corroborates that the existing Let's Encrypt
certificate was issued over **HTTP-01** — which is the per-tenant TLS fallback path.

### Deployment layout — DECIDED

Because the home directory sits above the document root, the standard shared-hosting
Laravel layout is available and does **not** depend on Plesk allowing a custom document
root (an unverified setting):

```
~/ethr/            Laravel application — app/ config/ routes/ vendor/ storage/ .env
                   NOT web-accessible. This is the security property that matters.
~/httpdocs/        document root
   ├── index.php   from api/public/, with the two require paths repointed to ~/ethr
   ├── .htaccess
   ├── .well-known/  (leave alone — ACME)
   └── (frontend static assets, if B5 sends us to static export)
```

If Plesk *does* allow setting the document root to a subdirectory, pointing it straight
at `~/ethr/api/public` is marginally cleaner. Either works; the layout above is chosen
because it is verified to be possible today.

`httpdocs/public/` and `httpdocs/et/` are pre-existing and unexplained — `et/` is empty.
Both should be confirmed as disposable before the docroot is populated.

### Re-observed 2026-09-17 — appended, not overwritten

The listing above is a dated observation and stays as it is. The account looked different
six weeks later, and the delta is the point:

```
~/                     home
├── .composer/  .ssh/  .trash/
├── bin/ dev/ etc/ lib/ lib64/ usr/ var/ tmp/   <- chroot skeleton (NEW)
├── error_docs/  logs/
├── git/                                        <- NEW
├── production.ethr.et/                         <- NEW: a SECOND vhost, home level
└── httpdocs/          <- DOCUMENT ROOT, re-confirmed
    ├── .well-known/acme-challenge/   <- intact; this is what re-confirms the docroot
    ├── cgi-bin/  css/  favicon.ico   <- Plesk defaults, stamped 2026-07-25
    ├── backend/                      <- NEW 2026-09-05, since REMOVED
    ├── dist/                         <- NEW, since REMOVED
    └── ethr.et/                      <- NEW 2026-09-17 23:48, a Plesk vhost skeleton
```

Three things this settles or raises:

- **The document root is still `httpdocs`.** Hosting Settings displays `Document root: /`,
  which read literally would put the home directory in the web root and make W6 above
  false. It does not: `.well-known/acme-challenge/` sits inside `httpdocs/`, an ACME
  challenge can only be served from the document root, and the certificate is live. Plesk's
  `/` is relative to the webspace root. **W6 stands.**
- **`httpdocs/backend/` was not ETHR.** A stock Laravel + Breeze scaffold (`tailwind.config.js`,
  `postcss.config.js`, `CHANGELOG.md`; no `lang/`, no `scripts/`, no `phpstan.neon`),
  dated 2026-09-05, with no `.env` and no `vendor/` — no credential exposure, and unable
  to run. Removed.
- **`httpdocs/public/` and `httpdocs/et/` were removed without the confirmation this
  section asked for.** `et/` was recorded empty here, so nothing is known to be lost;
  `public/` was never inspected. Noted so the gap is visible rather than silent.

**SSH is FORBIDDEN**, confirmed in Hosting Settings — the `/bin/false` caveat the shell
row below warned about. See `deployment/GATE-0-RESULT.md` → *Account evidence*, blockers
B-1 and B-4.

### ⚠️ Apex redirects to `www` — compatible, but note it

Plesk 301-redirects `ethr.et` → `https://www.ethr.et/`. This is **safe for ETHR**:
`www` is already in `Tenant::RESERVED_SUBDOMAINS`, and `ResolveTenant::resolveFromSubdomain()`
returns `null` for reserved subdomains, so `www.ethr.et` behaves as the apex — no tenant
resolved, login form keeps its organisation field.

Two configuration consequences:

- `CORS_ALLOWED_ORIGINS` should be `https://www.ethr.et` (currently templated as
  `https://ethr.et`), and `APP_URL` set to whichever host is canonical.
- `SANCTUM_STATEFUL_DOMAINS=ethr.et,*.ethr.et` already covers `www.ethr.et` via the
  wildcard — no change needed.
- A 301 on the apex would also catch `https://ethr.et/api/v1/...`. The SPA uses the
  relative `baseURL: "/api/v1"` so it always stays same-origin and never hits this, but
  any external API client pointed at the apex would be redirected — and a 301 on a POST
  is not reliably re-sent as a POST. Document the canonical host as `www.ethr.et`.

---

## VERIFIED FINDINGS — external probe of the live zone, 2026-08-29

These were obtained by querying public DNS and attempting TLS against `ethr.et`. No
account credentials were used and nothing was modified.

### ✅ B1a — Wildcard DNS **WORKS**

Six names that were never configured all resolve:

```
ethr.et              -> 91.99.81.71
www.ethr.et          -> 91.99.81.71
zzq7x.ethr.et        -> 91.99.81.71
tenant1.ethr.et      -> 91.99.81.71
habru-test-9f3.ethr.et -> 91.99.81.71
a1b2c3d4e5.ethr.et   -> 91.99.81.71
```

A wildcard `A` record exists in the zone. **The DNS half of B1 is settled and passes.**

### ⚠️ The domain does not currently point at Ethio Telecom

`91.99.81.71` is **Hetzner Online GmbH, Falkenstein, Germany**
(`static.71.81.99.91.clients.your-server.de`) — not Ethio Telecom. Ethio Telecom's own
ranges appear in the zone's SPF record (`196.189.93.65/66`, `196.189.189.1/2`) and its
mail is `mail1–4.ethionet.et`, so the *zone* is Ethio Telecom's; the *web A record* is
not.

Ports 80, 443, 8080, 22 and 21 on that host are all closed or filtered — **nothing is
serving `ethr.et` right now.** No live production traffic is at risk in this migration,
which removes the cutover-risk category entirely. Please confirm that matches your
understanding.

### ⚠️ B2 — zone is **not** managed by Plesk, which blocks automatic wildcard TLS

```
NS for ethr.et : ns2.telecom.net.et   (single nameserver — Ethio Telecom)
SOA primary    : ns.ethr.et           (does NOT resolve — a lame SOA, worth fixing)
CAA            : none                 (any CA may issue — good, no obstacle)
```

A wildcard certificate requires **DNS-01** validation, which requires writing a
`_acme-challenge` TXT record into the zone. Because the zone lives on Ethio Telecom's
nameservers rather than in Plesk, the Plesk Let's Encrypt extension **cannot do this
automatically**. Options, best first:

1. **Delegate the zone to Plesk's DNS** (or obtain a DNS API token from Ethio Telecom) →
   fully automatic wildcard issuance and renewal.
2. **Manual TXT record every ~90 days** — works, but a recurring operational task whose
   failure silently breaks every tenant login.
3. **Per-tenant certificates via HTTP-01** — *this is viable precisely because B1a
   passes*: since every subdomain already resolves to the host, Let's Encrypt can
   validate each tenant hostname over HTTP without touching DNS. Cost: automation hooked
   into tenant provisioning, and Let's Encrypt's limit of **50 certificates per
   registered domain per week** becomes a ceiling on signup rate.

No CAA record exists, so nothing blocks issuance at the policy level.

### Consequence for tier choice

Bronze's subdomain cap (5, per 2020 directory data) is **probably not the blocker it
appeared to be**: a wildcard vhost is *one* subdomain entry, not one per tenant. If
Plesk accepts `*` as a subdomain name, the numeric cap is irrelevant and Bronze's real
constraints are **5 GB storage and 50 GB bandwidth**. The cap only becomes fatal if
wildcard vhosts are unsupported — in which case per-tenant subdomains would be capped at
5 and the SaaS model cannot work on any tier below "unlimited".

---

## B1 — Wildcard tenant routing

### What must be proven

`tenant1.ethr.et`, `tenant2.ethr.et` and `tenant3.ethr.et` — names chosen at test time,
never configured in advance — all reach the same application and resolve to their own
tenant.

This is not "can I create subdomains". Every plan tier advertises unlimited subdomains,
and that is irrelevant: it describes subdomains an operator adds by hand. ETHR provisions
tenants from `RegisterTenantRequest` → `ProvisionTenant` with no human in the loop. If a
new tenant needs a panel action before it is reachable, self-service signup produces
tenants that silently do not work.

### Why it is load-bearing

`app/Http/Middleware/ResolveTenant.php` honours **only** the subdomain in production.
The `X-Tenant` header is explicitly refused outside local/testing, and the form-field
fallback covers login/register endpoints alone. There is no path-based tenancy.

### Test

1. Plesk → *DNS Settings* → add an `A` record with host `*` pointing at the account IP.
2. Plesk → *Subdomains* → *Add Subdomain*, name `*`, document root = the app's.
3. From outside: `curl -I https://zzq7x.ethr.et/api/v1/ping` using a name never
   configured. Repeat with two more random names.

**Pass:** all three return the application. **Fail:** NXDOMAIN, a default/parking page,
or a 404 from the web server.

### If it fails

Path-based tenancy is **not** a deployment workaround — it is a product redesign
touching `ResolveTenant`, the Next.js middleware, `SANCTUM_STATEFUL_DOMAINS`, every
generated tenant URL, and the session-cookie isolation model. Host-only cookies are
currently what stops one tenant's session reaching another; `.env.production.example`
documents that `SESSION_DOMAIN` is deliberately empty for exactly this reason. Changing
it would turn every session into a domain-wide credential.

**→ Option A (remain on VPS).**

---

## B2 — Wildcard TLS

### What must be proven

`https://<arbitrary-name>.ethr.et` presents a certificate the browser trusts, with no
per-tenant certificate issuance.

### Why it is load-bearing

`SESSION_SECURE_COOKIE=true`. A browser withholds a `Secure` cookie over a connection it
does not trust, so a certificate warning is not cosmetic — it is "no tenant can log in".

### Test

1. Plesk → *SSL/TLS Certificates* → is there a **wildcard** Let's Encrypt option?
2. Wildcard issuance requires **DNS-01** validation, which requires control of the
   `ethr.et` zone or an API token for it. Confirm who holds that.
3. `openssl s_client -connect zzq7x.ethr.et:443 -servername zzq7x.ethr.et` — check the
   SAN list covers `*.ethr.et`.
4. Confirm automatic renewal.

**Pass:** valid wildcard cert on an arbitrary name, auto-renewing.
**Fail:** per-hostname certificates only, or no zone control for DNS-01.

### If it fails

Per-tenant certificate issuance on signup would need Plesk API automation, is rate-limited
by Let's Encrypt (50 certs/domain/week), and adds a failure mode to tenant provisioning.

**→ Option A.**

---

## B3 — Cron

### What must be proven

`php artisan schedule:run` executes on a schedule, and `php artisan queue:work
--stop-when-empty --max-time=55` can drain the queue.

### Why it is load-bearing

11 scheduled entries in `routes/console.php` and 15 queued job classes. Without cron:
no leave accrual, no carry-forward, no monthly invoicing, no overdue-invoice handling,
no attendance-anomaly scan, no missing-punch scan, no scheduled reports, no dashboard
digests, no approval reminders, no data cleanup — **and no queued email is ever sent**,
which silently includes password reset.

### Test

1. Plesk → *Scheduled Tasks*: present at all?
2. Task type: is **"Run a command"** offered, or only "Fetch a URL"?
3. Minimum interval — 1 minute wanted, 5 tolerable.
4. `which php` over SSH; confirm the CLI binary is PHP ≥ 8.2 (it is often a *different*
   build from the web SAPI).
5. Schedule `php /path/to/api/artisan schedule:run` and confirm it runs.

**Pass:** command tasks at ≤ 5-minute intervals with a working PHP CLI.
**Fail:** no scheduler, or URL-fetch only.

### If it fails

URL-fetch-only is not automatically fatal but is a real design change: the scheduler and
queue would have to be driven through an authenticated HTTP endpoint, with its own
execution-time ceiling and its own security surface. That must be designed and costed,
not assumed. No scheduler at all is fatal.

**→ Option A.**

---

## B4 — PHP

### What must be proven

PHP ≥ 8.2, plus all mandatory extensions, plus **GD**.

Mandatory: `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `dom`,
`ctype`, `json`, `fileinfo`, `filter`, `hash`, `session`, `curl`, `bcmath`, `iconv`,
`zip`, `gd`.

### Why GD is called out separately

Its absence is **silent, not fatal**. `FileStorageService::compressImage()` returns the
original bytes and `storeThumbnails()` returns `[]` when GD is missing. Uploads keep
working while the byte budget (512 KB photos, 200 KB selfies) stops being enforced and
thumbnails stop being generated — so storage quota is consumed at several times the
expected rate and every list view falls back to full-size images. It would look fine
until the account filled up.

### Test

Run `scripts/hosting-verification/ethr-hosting-check.php` on the account. It reports
every extension classified MANDATORY/optional and fails loudly on any missing mandatory
one. **Delete it from the server immediately afterwards.**

Also check `max_execution_time` (want ≥ 120 s for payroll and imports) and
`memory_limit` (want ≥ 256 MB).

**Pass:** PHP ≥ 8.2, zero mandatory extensions missing.
**Fail:** PHP < 8.2 → Laravel 12 does not boot.

**→ Option A on failure.**

---

## B5 — Node.js

### What must be proven

Whether Next.js 16 can run as a resident production Node application.

### Why it is *not* fatal

It selects the strategy rather than the viability:

- **Node available → Option B1.** Deploy the existing `output: "standalone"` build. The
  frontend needs **no code change at all**; `middleware.ts` and the `headers()`-reading
  auth layout keep working.
- **Node unavailable → Option B2.** Static export, which is viable but not free — see §7
  of the review request and the route audit below.

### Test

1. Plesk → *Node.js* extension present?
2. Node major version — Next 16 requires **≥ 20.9**.
3. `npm -v`.
4. `npm run build` — note peak memory. A Next 16 production build is memory-hungry and
   may exceed a shared-host per-process cap even where Node itself is available. If so,
   build locally and upload `.next/standalone`.
5. Confirm the process stays resident (Plesk uses Passenger, which recycles apps).

---

## Route/runtime audit for B2 — completed, and it does *not* say "safe"

The review request rightly warns against assuming `"use client"` makes static export
safe. It does not. Here is the actual per-concern finding from the code:

| Concern | Found | Static-export impact |
| --- | --- | --- |
| **API routes** (`route.ts`) | **0** | None |
| **Server actions** (`"use server"`) | **0** | None |
| **`generateStaticParams`** | **0** | The 4 dynamic routes need it added |
| **Route segment config** (`dynamic`/`revalidate`/`runtime`) | **0** | None |
| **ISR** | Not used | None |
| **Server components** | 19 of 126 files lack `"use client"` — but only **one** does real server work | See below |
| **`next/headers` / `next/server`** | **2 files**: `middleware.ts`, `app/(auth)/layout.tsx` | **Both break** |
| **Middleware** | `middleware.ts` — host-based routing | **Not executed** under static export |
| **Cookies** | Handled by the browser + Laravel; the SPA sets none server-side | None |
| **SSR** | Marketing + auth shells only | Marketing pages lose SSR → **SEO impact on `/`, `/features`, `/pricing`, `/faq`, `/contact`** |
| **Authentication** | Sanctum token in an httpOnly cookie, read by Laravel | None — auth never depended on Next SSR |
| **Tenant routing** | Resolved by Laravel from the `Host` header | None at the API layer |

The two genuine blockers:

1. **`middleware.ts`** enforces that `/admin` is served only on `admin.ethr.et` and that
   tenant routes redirect on the platform host. Its own comment states nginx is the
   authoritative control and the middleware exists so the rule also holds in
   development. Under static export it simply does not run, so the rule must be
   reimplemented in `.htaccess` — and it must actually be reimplemented, not dropped.
2. **`app/(auth)/layout.tsx`** reads `headers()` to know the request hostname during SSR.
   It exists specifically to fix React hydration error #418 on tenant login pages. Under
   static export the host must be read client-side, reintroducing a first-paint flash on
   the tenant login page.

**Conclusion:** static export is viable, at a defined and non-zero cost — 4 named files,
an `.htaccess` reimplementation of the host rules and the CSP, and loss of marketing-page
SSR. It should be chosen only if B5 fails, never by default.

---

## Overall gate verdict

**CONDITIONAL GO — one gate passed, one has a known workaround, four still unverified.**

B1a passing is the single most important result so far: wildcard DNS was the risk with
no cheap workaround, and it already works. B2's obstacle is real but has three viable
paths, one of which (HTTP-01 per-tenant) is unlocked *by* B1a passing.

What remains cannot be answered from outside the account:

| Still needed | Where |
| --- | --- |
| B1b — does Plesk accept a subdomain named `*`? | Panel → *Subdomains* |
| B3 — cron: exists? command-type? interval? | Panel → *Scheduled Tasks* |
| B4 — PHP version + extensions | Panel → *PHP Settings*, or run the probe |
| B5 — Node.js extension + version | Panel → *Node.js* |
| H1 — `CREATE TRIGGER` privilege | phpMyAdmin |
| Bronze's real quotas | Panel → subscription overview |
| The Bronze account's **IP address** | Panel → *Hosting Settings*. Needed both to repoint DNS and to let external verification continue |

`scripts/hosting-verification/ethr-hosting-check.php` answers B4 and H1 in one page load
once uploaded. B1b, B3 and B5 are panel questions that take a few minutes each.
