# ETHR — Shared-Hosting Deployment Contract

**Set by the owner, 2026-09-22.** This is the authoritative statement of what ETHR may
depend on when deployed to Ethio Telecom Plesk shared hosting, and what it may never
require. It governs `shared-hosting/DEPLOYMENT.md`, `PLESK-SETUP.md` and the blocker
register in [`GATE-0-RESULT.md`](GATE-0-RESULT.md).

It is a **design constraint**, not a status report. Whether a given capability is currently
provisioned on the account is a separate question, answered per element below.

---

## The contract

### PRIMARY — the deployment path ETHR is built for

| Element | What it carries |
|---|---|
| **Plesk UI** | All account configuration: PHP version and limits, environment variables, SSL, mail |
| **Plesk Git deployment** | Getting code onto the host, and — via *additional deployment actions* — the one-off install commands |
| **Plesk Laravel integration** | Framework-aware operations where the panel offers them |
| **Plesk Scheduled Tasks** | The scheduler (`schedule:run`) and the queue worker (`queue:work`) — two recurring commands, not one |
| **Plesk database** | MySQL/MariaDB provisioning, and schema import where a SQL console exists |

### OPTIONAL — used when available, never assumed

| Element | Status under this contract |
|---|---|
| **SSH / shell** | A convenience. Every procedure must have a non-SSH route. Its absence may slow an operation; it may not block one. |

### NEVER REQUIRED — must not appear in any shared-hosting procedure

- **Docker**
- **systemd**
- **Supervisor**
- **root** / any privilege above the subscription user
- **VPS-only services** — Redis, Horizon, Reverb, MinIO and anything else that needs a
  long-running daemon the panel cannot start

These remain in the repository for the VPS fallback (Option A) and are correct there.
They are simply not part of this contract, and a shared-hosting runbook that reaches for
one of them is wrong by definition rather than by circumstance.

---

## Evidence status of each PRIMARY element

**Three of the five are observed on this account. Two are not.** Stating that plainly is
the point of this section: a contract that quietly assumes an unseen capability is how
`GATE-0-RESULT.md` came to carry a row testing "Node.js (build only)" for a panel that
offers a startable application.

| Element | Evidence | Status |
|---|---|---|
| Plesk UI | Subscription dashboard read 2026-09-17 and 2026-09-18 | **OBSERVED** |
| Plesk Git deployment | *Git* present in the dashboard listing; *additional deployment actions* field confirmed present by the owner 2026-09-18 — nothing entered, saved or executed | **OBSERVED** |
| Plesk database | *Databases* present in the dashboard listing | **OBSERVED** (whether it offers a SQL console is unread) |
| **Plesk Scheduled Tasks** | **Measured ABSENT.** No Scheduled Tasks / Task Scheduler / Cron Jobs section exists on the subscription dashboard (owner-read 2026-09-18). This is **G0-D = FAIL** | **NOT AVAILABLE** |
| **Plesk Laravel integration** | **Never observed.** Not in the dashboard listing, which was otherwise complete — Files, Databases, FTP, Backup & Restore, Website Copying, Statistics, Dev Tools, PHP 8.3.33, Logs, Git, PHP Composer, Security/SSL, Imunify, Password Protected Directories. Zero mentions anywhere in this repository | **UNEVIDENCED** |

Neither gap invalidates the contract. Both are **service-plan permissions or extensions**,
not server capabilities — the same class as SSH, and exactly what ask 4 of
[`ETHIO-TELECOM-SUPPORT-REQUEST.md`](ETHIO-TELECOM-SUPPORT-REQUEST.md) exists to resolve.
What they mean is that the PRIMARY path is **not currently executable end to end**, and
saying so is not pessimism; it is the difference between a contract and a wish.

---

## What changes because SSH is OPTIONAL

SSH was previously treated as a hard requirement, and its absence recorded as blockers
**B-1** and **B-4**. Under this contract that framing is wrong: SSH being Forbidden is a
constraint to design around, not a blocker to wait on.

| Operation | Non-SSH route under this contract |
|---|---|
| Deliver code | **Plesk Git deployment** |
| Install dependencies | **Plesk Composer** (present in the dashboard listing) |
| `key:generate`, `migrate`, `db:seed`, `ethr:create-admin` | **Plesk Git *additional deployment actions*** — executes as the subscription user on deploy, which covers one-off install work |
| Import an existing schema | **Plesk database**, via a SQL console if one is offered — unread |
| Scheduler and queue worker | **Plesk Scheduled Tasks** — two recurring commands. *Currently unavailable; see above* |

**The asymmetry that remains, and it is not solved by making SSH optional.** Deployment
actions fire *on deploy*. They cover the one-off install; they cannot drive anything
recurring. The scheduler and the queue worker need Scheduled Tasks, and eleven of the
fourteen entries in `api/routes/console.php` do nothing but enqueue — so without a worker
runner the asynchronous half of the product is inert regardless of how the code arrived.

That is why **G0-D remains the one blocker this contract cannot design around.**

---

## What this does not change

- **The pre-registered decision.** `shared-hosting/SHARED_HOSTING_MIGRATION_PLAN.md` §4's
  rule fired on G0-D and returned **No-Go → Option A**. Making SSH optional does not
  reverse it, because SSH was never what fired it.
- **Gate statuses.** G0-A stays `NOT VERIFIED`, G0-D stays `FAIL`, G0-G stays `PARTIAL`.
  A contract states intent; only output moves a gate.
- **The VPS assets.** `infrastructure/supervisor.conf` and the compose files stay as they
  are. They belong to Option A and are not measured against this contract.
