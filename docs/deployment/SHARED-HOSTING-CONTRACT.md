# ETHR — Shared-Hosting Deployment Contract

**Set by the owner, 2026-09-22.** This is the authoritative statement of what ETHR may
depend on when deployed to Ethio Telecom Plesk shared hosting, and what it may never
require. It governs `shared-hosting/DEPLOYMENT.md`, `PLESK-SETUP.md` and the blocker
register in [`GATE-0-RESULT.md`](GATE-0-RESULT.md).

It is a **design constraint**, not a status report. Whether a given capability is currently
provisioned on the account is a separate question, answered per element below.

---

## HARD RULE — repository only; hosting settings are not a path

**Set by the owner, 2026-09-25. This rule outranks every table below it**, and where the
two disagree the rule wins and the table is wrong.

> **Work happens in the repository. Hosting settings are not a path, and going through them
> is not a plan.** Leave the panel alone; a default that works is not a setting to confirm,
> it is a setting not to touch.

> **Restated 2026-09-25, because it was first written down wrong.** The rule was originally
> recorded here as *"Plesk defaults only — no procedure may require a panel setting to be
> changed from its default…"*. That is a rule about **how the deployment is designed**. The
> rule the owner set is about **where the work happens**, and the two are not the same: the
> first still ends every task with a list of panel actions, which is exactly what the rule
> exists to stop.

**The consequences below were derived from the mis-stated version and all survive it**,
because a design that needs no panel changes is also one that produces no panel instructions.
They are kept as written rather than re-derived — what changes is the emphasis, and what the
rule counts as finished work.

**What it means in practice, stated as behaviour rather than as principle:**

- **Repository-side work is the deliverable.** Code, tests, documentation, gates. If a task
  can only be completed by someone opening the panel, it is not a task for this repository —
  it is a note in [`../MIGRATION_STATE.md`](../MIGRATION_STATE.md)'s manual queue, and it
  belongs there rather than at the end of every report.
- **Do not route work through hosting settings.** Not as a plan, not as a recommendation,
  not as a closing checklist. The panel is the owner's, and the honest place for anything
  needing it is the queue that already exists for it.
- **Panel *readings* are still evidence.** Recording what a page showed is not the same as
  directing someone through it. Nothing in this rule forbids writing down a measurement.
- **The unavoidable minimum is not pretended away.** A database must exist, an `.env` must
  be filled, files must reach the host. Those are named once in the queue, not repeated as
  instructions. The rule does not claim a deployment can happen with nobody ever opening
  Plesk — it says that is not where this repository's work lives.

Four things follow, each of which has already cost something in this repository:

1. **Where a default is already correct, leave it alone.** The document root is the worked
   example: it reads `/` in *Hosting Settings*, which is Plesk showing it relative to the
   webspace root, and the served directory is `httpdocs/`. That is already right.
   "Correcting" that field is **B-7's Trap 1** — at best a no-op, and a value resolving to
   the home directory web-serves `~/ethr/api/.env`. A guide in this repository told the
   owner to edit it; PR #104 withdrew that. **The rule generalises the lesson: a default
   that works is not a setting to confirm, it is a setting not to touch.**
2. **A Plesk extension is not a default.** The *Node.js* extension is the case in point.
3. **A field that is absent is not a default to configure — it is a dependency to remove.**
   Neither directive textarea appears on *Apache & nginx Settings*, read twice (**G0-A**).
4. **A provider concession is upside, never a prerequisite.** Cron, SSH, higher plans and
   the `TRIGGER` grant may each improve this deployment. **Nothing may be designed to
   require one.** The support request stays worth sending; nothing may wait on it.

### What the rule forbids, with the evidence

| Forbidden as a requirement | Why | Evidence |
|---|---|---|
| **Changing the *Document root* field** | The default is already correct, and editing it is the highest-consequence field in the deployment | Twelve HTTP requests, 2026-09-24 — `GATE-0-RESULT.md` → *Account evidence* |
| **Plesk *Node.js* application** | An extension, and it needs directive support to split `/api/*` from `/` — which rule 3 forbids | Panel 2026-09-22; `SHARED_HOSTING_PLAN.md` §5.1 rows #7 and #8 |
| **Additional nginx / Apache directives** | The field does not exist on this plan | *Apache & nginx Settings*, read twice — **G0-A** |
| **Plesk Laravel integration** | Never observed in an otherwise complete dashboard listing; an extension either way | The evidence table below |
| **SSH, cron grant, `TRIGGER`, a higher plan** | Provider concessions | **B-1**, **G0-D**, **G0-F** |

### The one place this rule collides with something, and it is not mine to resolve

**`migrate` aborts without the `TRIGGER` privilege, deliberately.** The rule forbids
*requiring* that grant. Both of those are load-bearing, and they point opposite ways.

**Why the abort is not negotiable from the code's side.** `AuditLog`'s `update()` and
`delete()` overrides are plain instance methods, so **every mass-operation path bypasses
them** — `DB::table('audit_log')->update()`, `AuditLog::where(...)->delete()`, raw SQL. The
database trigger is the only thing that stops an audit record being edited, and
`tests/Feature/AuditLogImmutabilityTest` asserts exactly that by driving the raw query
builder. An earlier revision downgraded the failure to "log and continue"; it was reverted,
because it put production in a state the test suite contradicts **while CI stayed green**.
Convention #5 — immutable audit log — is Non-Negotiable.

**What the rule changes is the shape of the question, not the answer.** Before it, **Q8**
had three outs: get the grant, accept the downgrade, or don't deploy. **The rule removes the
first.** Waiting on a concession is exactly what it forbids. So:

| Option | What it costs |
|---|---|
| **Accept the downgrade** | Requires building the `AUDIT_LOG_REQUIRE_DB_IMMUTABILITY` flag that `AUDIT_LOG_INTEGRITY_DECISION.md` **deliberately does not build**, and accepting that convention #5 is enforced by instance methods that four code paths walk straight past |
| **ETHR does not deploy on this account** | The honest other half, and the reason the decision was pre-registered as the owner's rather than an engineering one |

**This is Q8 and it stays the owner's.** It is recorded here because the rule narrowed it and
somebody reading the rule alone would not see that. Asking Ethio Telecom for the grant is
still worth doing — under the rule it is upside, and if it arrives this collision
disappears.

### The consequence worth stating once

**Static export is the only frontend path**, and that is now settled by rule rather than
pending a reading. `SHARED_HOSTING_PLAN.md` §3A Option A, costed at ≈8 days (6–11
realistic). Option B (Node on Plesk) is forbidden by rules 2 and 3 together; the plan had
already foreclosed it on routing grounds (§5.1 #8), and this rule removes the "unless G0-A
turns out to work" escape hatch that PR #107 left open.

**Nothing about the frontend *code* changes today.** Step 4 of §5.5 stays gated on the
canary, by the plan's own rule: *"the whole estimate void if step 1 returns B.1 FAIL."*

---

## The contract

### PRIMARY — the deployment path ETHR is built for

| Element | What it carries |
|---|---|
| **Plesk UI** | All account configuration: PHP version and limits, environment variables, SSL, mail |
| **Plesk Git deployment** | Getting code onto the host, and — via *additional deployment actions* — the one-off install commands |
| ~~**Plesk Laravel integration**~~ | **Removed 2026-09-25 by the hard rule** — an extension, and never observed in an otherwise complete dashboard listing |
| ~~**Plesk Scheduled Tasks**~~ | **Moved to OPTIONAL 2026-09-25.** Measured ABSENT on this subscription (**G0-D**), so it cannot be a PRIMARY dependency under the hard rule |
| **An external cron caller** | The scheduler and the queue worker, driven over HTTP: `POST /api/v1/cron/schedule` and `POST /api/v1/cron/queue`, built `b61cb05`. **Both, not one** — eleven of the fourteen scheduled entries only enqueue |
| **Plesk database** | MySQL/MariaDB provisioning, and schema import where a SQL console exists |

### OPTIONAL — used when available, never assumed

| Element | Status under this contract |
|---|---|
| **SSH / shell** | A convenience. Every procedure must have a non-SSH route. Its absence may slow an operation; it may not block one. |
| **Plesk Scheduled Tasks** | Preferable where offered — no token to hold, no request timeout, no public surface — and **absent here**. The external caller above is the route that does not depend on it. |

### NEVER REQUIRED — must not appear in any shared-hosting procedure

- **Docker**
- **systemd**
- **Supervisor**
- **root** / any privilege above the subscription user
- **VPS-only services** — Redis, Horizon, Reverb, MinIO and anything else that needs a
  long-running daemon the panel cannot start
- **Plesk extensions beyond what the subscription ships** — the *Node.js* application in
  particular *(added 2026-09-25 by the hard rule)*
- **Additional nginx / Apache directives** — the field does not exist on this plan
  *(added 2026-09-25 by the hard rule)*
- **Any change to the *Document root* field** — the default is already correct
  *(added 2026-09-25 by the hard rule)*

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
not server capabilities — the same class as SSH, and exactly what the **higher-plans** ask of
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

~~That is why **G0-D remains the one blocker this contract cannot design around.**~~

> **Corrected 2026-09-25 — that sentence was false when written, and stayed for three
> days.** This contract is dated 2026-09-22; the cron endpoints were merged the same day
> (`b61cb05`) and `SHARED_HOSTING_PLAN.md` §5.1 records rows #9 and #10 as
> **RESOLVED — degraded** because of them. The recurring half no longer needs the *host* to
> run a command — it needs *something* to call two URLs, and that something can be anywhere.
> G0-D is still **FAIL** and still costs real cadence, but it is designed around, not
> undesignable.
>
> **What it leaves is not nothing.** The queue drains at the caller's interval rather than
> Plesk's minute, the token is a credential someone holds, and **which caller** is open
> question **Q6** — the owner's, per `SHARED_HOSTING_PLAN.md` §5.3a. Under the hard rule
> above, the one answer that is *forbidden* is "wait for a cron grant".

---

## What this does not change

- **The pre-registered decision.** `shared-hosting/SHARED_HOSTING_MIGRATION_PLAN.md` §4's
  rule fired on G0-D and returned **No-Go → Option A**. Making SSH optional does not
  reverse it, because SSH was never what fired it.
- **Gate statuses.** G0-A stays `NOT VERIFIED`, G0-D stays `FAIL`, G0-G stays `PARTIAL`.
  A contract states intent; only output moves a gate.
- **The VPS assets.** `infrastructure/supervisor.conf` and the compose files stay as they
  are. They belong to Option A and are not measured against this contract.
