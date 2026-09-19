# Ethio Telecom support request — four asks, one ticket

**Status:** drafted 2026-09-18, **not yet sent.**

This exists because three separate documents name "one support request" as the remedy for
the blockers holding the migration, and none of them contained the text. It is written to
be pasted with minimal editing.

*Title corrected 2026-09-19: it read "three asks" while the body carried four. Ask 4 was
added the day after the draft and the heading was never brought along — which is how a
document tells its own reader to skip a quarter of itself.*

**It is the single highest-value action available.** Asks 1 and 2 are alternative solutions
to the same fatal problem — either one substantially unblocks the migration.

*Ask 1 corrected 2026-09-19: it requested **one** cron line and claimed that line drove the
whole background half. It does not. `routes/console.php` has no `queue:work` entry, and
eleven of its fourteen entries only enqueue, so `schedule:run` alone fills the `jobs` table
and drains nothing. The ask is now two lines. Sending the earlier version would have got
exactly what was asked for and still left every queued job unrun.* Ask 3 is
independent and aborts the database migration by design if refused.

**Ask 4 — added 2026-09-18 — may be worth more than the other three combined.**
`deployment/GATE-0-RESULT.md` records that *"PHP versions / Node / cron granularity /
proxy directives"* often **differ by tier**, and that **G0-A, G0-D and G0-G may all have
different answers on a higher plan**. Those are exactly the three gates blocking this
migration: no custom directives, no Scheduled Tasks, and a Node runtime nobody has read.
The account's tier has never been confirmed. One question about plans can therefore
resolve three gates at once, and it asks the provider to *sell* something rather than to
grant a favour — which is usually the easier conversation.

**Before sending, check nothing here is stale** — particularly the subscription tier, which
this repository has never confirmed.

---

## What must NOT go in the ticket

- No passwords, no `APP_KEY`, no database credentials. None of the four asks needs one.
- No repository URL or source code. The asks are about the hosting account's capabilities.

---

## Account identifiers

| | |
|---|---|
| Hosting username | `ethret` |
| Server | `lin6.ethiotelecom.et` (`213.55.96.154`) |
| Domain | `ethr.et` |

**Spell the username carefully.** This repository wrote it `etrhet` in eleven places for
several weeks — the `hr` transposed. A ticket naming a user that does not exist buys a
round trip and no grant.

---

## Suggested subject

> Hosting account `ethret` (`ethr.et`) — Scheduled Tasks, SSH access, a database
> `TRIGGER` grant, and what the higher plans include

---

## Body

> Hello,
>
> I am preparing to deploy a PHP/Laravel application to hosting account **`ethret`** on
> **`lin6.ethiotelecom.et`**, serving **ethr.et**. Three capabilities the application needs
> are not currently available on the subscription. I would be grateful if you could enable
> them, or tell me which tier provides them. I have a fourth question, about the plans
> themselves, at the end.
>
> **1. Scheduled Tasks (cron)**
>
> The Plesk control panel for this subscription shows no *Scheduled Tasks* section. I
> understand this is controlled by a service-plan permission rather than by the server, so
> I assume it is not enabled on my plan.
>
> The application needs two recurring tasks:
>
> ```
> * * * * *  cd ~/ethr/api && php artisan schedule:run >> /dev/null 2>&1
> * * * * *  cd ~/ethr/api && php artisan queue:work --queue=attendance,notifications,default,exports --stop-when-empty --max-time=50 >> /dev/null 2>&1
> ```
>
> Together these drive the application's entire background half — scheduled billing, leave
> accrual, payslip notifications and queued email. The first decides *when* work is due;
> the second performs it. Without both, those features do not run at all. Neither is
> long-running: the second exits as soon as the queue is empty, and in under a minute
> regardless. A one-minute interval is what the framework expects; if the minimum interval
> on this plan is longer, please tell me what it is, as the application can be adjusted.
>
> If the plan limits how many scheduled tasks a subscription may have, please tell me that
> limit as well — two is the minimum this application needs.
>
> Please also confirm whether tasks can be of the **"Run a command"** type, as opposed to
> "Fetch a URL" only.
>
> **2. SSH / shell access**
>
> *Hosting Settings* shows SSH access as **Forbidden** for this account, and there is no
> Terminal under *Dev Tools*.
>
> Beyond ordinary file management, I need shell access to run four one-off installation
> commands — generating an application key, creating the database schema, seeding initial
> data, and creating the first administrator account. There is no way to perform these from
> the control panel, so without either this or item 1 the application cannot be installed
> at all.
>
> I note that the Plesk **Git** extension on this same account offers *additional
> deployment actions*, which execute shell commands as the subscription user. The platform
> therefore already runs shell commands for this account; what I am asking for is
> interactive access to the same capability. If full SSH is not possible on this plan, item
> 1 alone would be sufficient.
>
> **3. Database privilege: `TRIGGER`**
>
> The application's schema includes database triggers that protect an audit log from being
> modified after the fact. Creating them requires the `TRIGGER` privilege on the
> application's own database, granted to its own database user — no access to any other
> database is needed.
>
> Without this privilege the schema creation stops with an error by design, rather than
> installing a weaker audit trail silently. If the privilege cannot be granted on shared
> hosting, please let me know, as I need to plan around it explicitly.
>
> **4. What the higher service plans provide**
>
> Rather than ask for each capability separately, could you tell me what the plans above
> my current one include — specifically:
>
> - Scheduled Tasks / cron, and the minimum interval
> - SSH access
> - a Node.js runtime
> - the ability to add custom Apache or nginx directives
>
> If a higher plan provides these as standard, upgrading may be simpler for both of us
> than granting them individually, and I am willing to move to the plan that fits.
>
> Thank you very much for your help.

---

## If they decline

| Declined | Consequence | Next step |
|---|---|---|
| **1 and 2 both** | The application cannot be installed on this account as provisioned | The architecture decision reopens — Option A (stay on the VPS) or Option C (shared hosting plus a small VPS for cron and queue), both already costed in `shared-hosting/SHARED_HOSTING_MIGRATION_PLAN.md`. **Owner decision.** |
| **1 only** | Installation is possible via deployment actions; the scheduler and queue still have no runner | Scheduler and queue move behind an authenticated HTTP endpoint — unbuilt, and must be costed before it is promised |
| **2 only** | Installation and operations work; no recurring tasks | As above |
| **3 only** | `migrate` aborts at `2026_07_22_000001` | See `docs/AUDIT_LOG_INTEGRITY_DECISION.md` — this is a deliberate stop, and the decision to proceed without triggers is the owner's to record |
| **4 — "no plan offers cron / Node"** | The three blocking gates are **permanent**, not provisioning | This is the answer that settles it. `SHARED_HOSTING_MIGRATION_PLAN.md` §4's pre-registered rule stands unqualified: **Option A**. Record it and stop spending on Option B |
| **4 — a higher plan provides them** | G0-A, G0-D and G0-G may all flip together | Price it against the VPS in `TCO_COMPARISON.md` before upgrading. Note that **Branch B is still an architectural frontend change** unless the plan also provides a Node runtime — see `SHARED_HOSTING_AUDIT.md` §E *MEASURED 2026-09-18* |

## What this ticket does *not* ask for, and why

- **A wildcard TLS certificate.** The zone is on `ns1`/`ns2.telecom.net.et`, so Plesk does
  not host it and DNS-01 validation is unavailable from the panel. That is a DNS question,
  not a hosting-support one, and it is tracked separately.
- **Custom nginx or Apache directives.** Also gated by a service-plan permission
  (**G0-A**), but unlike the three above it has a documented workaround — static export
  keeps the frontend same-origin without any directives. Asking for everything at once
  weakens the asks that have no workaround.
