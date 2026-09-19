# Total Cost of Ownership — Architecture Comparison

**Date:** 2026-08-29
**Currency:** ETB. **All third-party figures require confirmation on the Ethio Telecom
portal before any decision is made on them.**

---

## Finding 0, added 2026-09-18 — Option B may not be *possible*, not merely dearer

This document compares options on **cost**. Account evidence gathered 2026-09-17/18 has
since moved Option B's problem from the cost column to the feasibility column, which no
row below expresses.

**SSH access on the shared plan is `Forbidden`** (Hosting Settings, measured). That is not
a convenience difference from Option A's root VPS, it is a capability one:

| Consequence | Blocker |
| --- | --- |
| No route runs `php artisan` — so no `key:generate`, `migrate`, `db:seed`, `ethr:create-admin` | **B-4** |
| No route imports a SQL dump (`mysql < dump.sql` needs a shell on the target) | **B-5** |
| `rsync` deployment is unavailable; Git + Composer extensions are the remaining route | — |

**So Option B's true cost is currently unbounded, because it is unknown whether it can be
completed at all.** That hinges on one unread panel page: if *Scheduled Tasks* offers a
command-type task, `artisan` has a runner and B-4/B-5 resolve. If it offers URL-fetch only,
**there is no documented way to deploy ETHR to this account**, and Option B is not a
cheaper architecture — it is not an available one.

> **THE PANEL PAGE WAS READ — 2026-09-18. The answer is worse than either branch above.**
>
> There is **no *Scheduled Tasks* section at all** on this subscription — not URL-fetch
> only, not a longer minimum interval: absent. The dashboard listing is otherwise complete
> and *Dev Tools* offers PHP, Git and Composer with no Terminal. **G0-D = FAIL.**
>
> So the "if" above resolves to the second branch, and with it:
>
> - `SHARED_HOSTING_MIGRATION_PLAN.md` §4's **pre-registered decision rule has fired** —
>   *B3 cron fails → No-Go → Option A*. It was written down before any evidence existed
>   precisely so it could not be renegotiated once the answer became inconvenient.
> - **This table's premise is now the question, not the background.** Comparing the annual
>   cost of Option A against Option B is comparing a running architecture against one with
>   no demonstrated route to installation.
> - What could still reopen it: ask 1 or ask 2 of
>   [`deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md`](deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md)
>   (cron, or SSH), or ask 4 — the tiers above Bronze may carry *Scheduled Tasks*,
>   custom directives and Node, which is three gates on one answer. **The request is
>   drafted and not sent.**
>
> The costs in the rest of this document are not restated here and have not been
> re-measured. Read them as *"if Option B becomes available"*, which is not currently
> established. Full record: `MIGRATION_STATE.md` → *G0-D ANSWERED 2026-09-18* and
> *THE PRE-REGISTERED DECISION RULE HAS FIRED*.

Three smaller corrections to the rows below, same evidence:

- **Option B, "Operational complexity: high to get running"** — understated. The deploy
  tooling does not merely need rebuilding; two of its steps have no known mechanism.
- **Option B, "Functionality ~95%"** — G0-A shows no custom-directive field on the
  Apache & nginx page, which forecloses the Node branch and forces static export. That is
  an **architectural frontend deployment change** — re-costed 2026-09-18 (`7aed9d2`) after the export was attempted and failed to build; see `SHARED_HOSTING_AUDIT.md` §E *MEASURED 2026-09-18*, which withdraws the earlier "bounded, already-scoped" framing — and it is engineering cost
  this table does not carry, and it moves marketing SSR from "under B2 also" to "yes".
- **Option A, "Remain on VPS"** — assumes the VPS is running. It is recorded as dormant
  with all ports closed, `ethr.et` already resolves to the Plesk host, and the owner
  reports the VPS is "stored on Git" — which covers code but **not** the database or the
  `APP_KEY` that decrypts `tin`/`national_id`. Option A may need standing back up before
  it is an option, and that is not costed either.

**None of this changes the pricing questions below, which remain the decisive ones.** It
adds a prior question: *can* Option B be completed. Answer that first — it is one panel
page — because a cost comparison between an expensive option and an impossible one is not
a comparison.

*That page was read on 2026-09-18 and the answer is in the block above: **no**, not as this
subscription is provisioned. The prior question is therefore answered, and answered against
Option B, until the support request returns something.*

---

## ⚠️ Two findings that may invalidate the premise of this migration

### Finding 1 — the shared plan may cost *more* than the VPS

Public sources disagree, and the disagreement is not a rounding error:

| Source | Plan | Figure | Period stated |
| --- | --- | --- | --- |
| Ethio Telecom portal (search summary, 2026) | Linux Web Hosting tiers | ETB **452 – 1,009** | **not stated** |
| whtop directory (data dated 2020) | Linux **Bronze** | ETB **650** | **per month** |
| whtop directory (data dated 2020) | Linux **Silver** | ETB **2,000** | **per month** |
| whtop directory (data dated 2020) | **VPS Gold** (4 GB / 50 GB, root) | ETB **10,379** | **per year** |
| whtop directory (data dated 2020) | **VPS Platinum** (8 GB / 100 GB, root) | ETB **15,845** | **per year** |

If the monthly reading is right, Linux Silver is **ETB 24,000/year** — **2.3× the cost of
VPS Gold**, which is a full root server that runs ETHR's existing architecture unchanged.

Under that reading the migration costs more money, loses functionality, and adds risk.
The entire cost rationale disappears.

I cannot resolve this from public data: both Ethio Telecom web properties fail TLS
certificate verification when fetched, so their live price list could not be read
first-hand, and the directory data is six years old.

**→ Confirm current pricing and billing period for both the shared tiers and the VPS
tiers before anything else. This single question may decide the whole migration.**

### Finding 2 — the lower shared tiers cap subdomains numerically

The same directory listing gives **Linux Bronze: 5 subdomains** and **Linux Silver: 10
subdomains**. Only the top tier advertises "unlimited".

A numeric cap is not merely tight for ETHR — it is **structurally incompatible** with the
product. ETHR provisions tenants from self-service signup with no operator action, and
each tenant is a subdomain. A 5- or 10-tenant ceiling is not a SaaS platform.

And "unlimited" on the top tier still does not mean **wildcard** — see gate B1. Unlimited
manually-created subdomains and one `*` vhost are different capabilities, and only the
second one works here.

---

## Cost model

### What is NOT known

- **Current VPS cost.** Not recorded anywhere in this repository and not supplied. Every
  comparison against "today" is therefore incomplete. **Please provide it.**
- Current storage consumption and growth rate (drives the plan tier and any external
  object-storage bill).
- Whether existing SMTP is paid or bundled.

### Option A — Remain on VPS (current)

| Line | Cost | Note |
| --- | --- | --- |
| VPS | **UNKNOWN** | Runs all 13 services |
| Object storage | 0 | MinIO, self-hosted |
| Redis | 0 | Self-hosted |
| Workers / WebSockets | 0 | Self-hosted |
| Database | 0 | MariaDB + replica, self-hosted |
| Backup | Storage cost only | `scripts/backup.sh` exists |
| SMTP | External relay | Unchanged in every option |
| **Operational complexity** | **Medium** | Docker Compose, deploy/rollback scripts exist and work |
| **Functionality** | **100%** | |
| **Security posture** | **Full** | Audit-log triggers enforced; verified working (`log_bin=0`) |

### Option B — Ethio Telecom shared hosting

| Line | Cost | Note |
| --- | --- | --- |
| Shared plan | **ETB 650–2,000/mo** *or* **452–1,009 one-off** — unresolved | Tier 3–4 needed for storage headroom, and only the top tier claims unlimited subdomains |
| Object storage | 0, or external S3 | Local disk works (`temporaryUrl` is supported on `local` via `serve => true`) but consumes the plan quota |
| Redis | 0 | Removed — drivers move to `database` |
| Workers | 0 | Cron-driven `queue:work` |
| WebSockets | 0 | Removed — degrades to the existing 30 s poll |
| Database | Included, 1–10 DBs | ETHR needs **1** (single-DB tenancy, verified) |
| Backup | **Manual/unknown** | Every existing backup script assumes Docker + SSH |
| **Operational complexity** | **Low once running, high to get running** | All deploy tooling must be rebuilt |
| **Functionality** | **~95%** | Loses Horizon dashboard, live push, sub-minute queue latency, read replica; under B2 also marketing SSR |
| **Security posture** | **Possibly degraded** | Audit-log triggers unverified (H1); compensating control A1 measured as unavailable even on our own dev DB user (`1142 GRANT command denied`) |

### Option C — Hybrid (shared web tier + ET VPS for infrastructure)

| Line | Cost | Note |
| --- | --- | --- |
| Shared plan | as Option B | |
| VPS Gold | **ETB 10,379/yr** (unconfirmed) | Carries Redis, Horizon, Reverb, MinIO, scheduler |
| **Total** | **shared + VPS** | Strictly more than either alone |
| **Operational complexity** | **High** | Two environments, two deploy paths, two backup regimes |
| **Reliability** | **Worst** | Cross-provider network on the hot path; DB or Redis exposed across the internet |
| **Functionality** | ~100% | |

### Option D — Ethio Telecom VPS (not previously listed; worth stating)

| Line | Cost | Note |
| --- | --- | --- |
| VPS Gold 4 GB / 50 GB, root | **ETB 10,379/yr** (unconfirmed) | |
| VPS Platinum 8 GB / 100 GB, root | **ETB 15,845/yr** (unconfirmed) | |
| Everything else | 0 | Self-hosted, exactly as today |
| **Operational complexity** | **Medium** | Identical to today — `docker-compose.prod.yml` runs unchanged |
| **Functionality** | **100%** | |
| **Security posture** | **Full** | Root access; triggers work |

**This option deserves serious consideration and was under-weighted in the original
plan.** It satisfies any "host with Ethio Telecom" requirement, keeps 100% of
functionality, requires **zero** application changes, has no unverified gates, and — on
the monthly reading of the shared pricing — may be *cheaper* than the shared plan.

The current architecture is already sized for it: `docker-compose.lowmem.yml` exists in
the repository, so a 4 GB target has been considered before.

---

## Comparison

| | **A — Current VPS** | **B — ET Shared** | **C — Hybrid** | **D — ET VPS** |
| --- | --- | --- | --- | --- |
| Infrastructure cost | UNKNOWN | Disputed (may exceed D) | Highest | ETB 10,379–15,845/yr* |
| Application code changed | 0 | 3 files (+4 if static export) | 3 files | **0** |
| Unverified gates | **0** | **5** | 5 | **0** |
| Functionality | 100% | ~95% | ~100% | **100%** |
| Realtime push | Native | Poll fallback | Native | Native |
| Queue latency | Sub-second | 1–5 min | Sub-second | Sub-second |
| Queue observability | Horizon | `failed_jobs` + logs | Horizon | Horizon |
| Audit-log DB triggers | **Enforced** | **At risk (H1)** | Enforced | **Enforced** |
| Wildcard tenant subdomains | Works | **At risk (B1)** | At risk | Works |
| Scalability | Vertical, in your control | Plan-capped | Mixed | Vertical, in your control |
| Reliability | Single VPS | Provider-managed | **Worst** — two providers on the hot path | Single VPS |
| Backup / restore | **Scripts exist and are tested** | Must be rebuilt | Two regimes | **Scripts work unchanged** |
| Operational complexity | Medium | Low-running / high-to-build | **High** | Medium |
| Migration risk | **None** | High | High | **Low** |

\* unconfirmed, 2020 directory data.

---

## Reading

The brief's own rule is that the cheapest architecture is not automatically the best, and
that material security or functionality degradation points back to the VPS. Applying it:

1. **Option C (hybrid) should be rejected.** Once a VPS carries Redis, workers,
   WebSockets and storage, the shared host contributes only PHP execution the same VPS
   could do for free — while adding a second provider, a second deploy path, and a
   cross-internet dependency on the hot path. It costs the most and is the least
   reliable.

2. **Option B's advantage is entirely financial, and that advantage is currently
   unproven** — possibly inverted. It also carries five unverified gates, a possible
   security downgrade, a possible structural blocker (subdomain caps), and requires
   rebuilding all deployment and backup tooling.

3. **Option D deserves a decision, not an assumption.** If the goal behind this migration
   is "host with Ethio Telecom", Option D achieves it at zero functional cost, zero code
   change and zero unverified gates. If the goal is purely "spend less", the pricing
   question must be settled first — because on one reading of the public data, shared
   hosting is the more expensive choice.

**No cost-based recommendation can responsibly be made until the pricing question and
the current VPS cost are both answered.** Those are two questions, and neither requires
engineering work.
