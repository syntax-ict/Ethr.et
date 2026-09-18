# Ethio Telecom support request — three asks, one ticket

**Status:** drafted 2026-09-18, **not yet sent.**

This exists because three separate documents name "one support request" as the remedy for
the blockers holding the migration, and none of them contained the text. It is written to
be pasted with minimal editing.

**It is the single highest-value action available.** Asks 1 and 2 are alternative solutions
to the same fatal problem — either one substantially unblocks the migration. Ask 3 is
independent and aborts the database migration by design if refused.

**Before sending, check nothing here is stale** — particularly the subscription tier, which
this repository has never confirmed.

---

## What must NOT go in the ticket

- No passwords, no `APP_KEY`, no database credentials. None of the three asks needs one.
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

> Hosting account `ethret` (`ethr.et`) — requesting Scheduled Tasks, SSH access, and a
> database `TRIGGER` grant

---

## Body

> Hello,
>
> I am preparing to deploy a PHP/Laravel application to hosting account **`ethret`** on
> **`lin6.ethiotelecom.et`**, serving **ethr.et**. Three capabilities the application needs
> are not currently available on the subscription. I would be grateful if you could enable
> them, or tell me which tier provides them.
>
> **1. Scheduled Tasks (cron)**
>
> The Plesk control panel for this subscription shows no *Scheduled Tasks* section. I
> understand this is controlled by a service-plan permission rather than by the server, so
> I assume it is not enabled on my plan.
>
> The application needs one recurring task:
>
> ```
> * * * * *  cd ~/ethr/api && php artisan schedule:run >> /dev/null 2>&1
> ```
>
> This single line drives the application's entire background half — scheduled billing,
> leave accrual, payslip notifications and queued email. Without it those features do not
> run at all. A one-minute interval is what the framework expects; if the minimum interval
> on this plan is longer, please tell me what it is, as the application can be adjusted.
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
> Thank you very much for your help.

---

## If they decline

| Declined | Consequence | Next step |
|---|---|---|
| **1 and 2 both** | The application cannot be installed on this account as provisioned | The architecture decision reopens — Option A (stay on the VPS) or Option C (shared hosting plus a small VPS for cron and queue), both already costed in `shared-hosting/SHARED_HOSTING_MIGRATION_PLAN.md`. **Owner decision.** |
| **1 only** | Installation is possible via deployment actions; the scheduler and queue still have no runner | Scheduler and queue move behind an authenticated HTTP endpoint — unbuilt, and must be costed before it is promised |
| **2 only** | Installation and operations work; no recurring tasks | As above |
| **3 only** | `migrate` aborts at `2026_07_22_000001` | See `docs/AUDIT_LOG_INTEGRITY_DECISION.md` — this is a deliberate stop, and the decision to proceed without triggers is the owner's to record |

## What this ticket does *not* ask for, and why

- **A wildcard TLS certificate.** The zone is on `ns1`/`ns2.telecom.net.et`, so Plesk does
  not host it and DNS-01 validation is unavailable from the panel. That is a DNS question,
  not a hosting-support one, and it is tracked separately.
- **Custom nginx or Apache directives.** Also gated by a service-plan permission
  (**G0-A**), but unlike the three above it has a documented workaround — static export
  keeps the frontend same-origin without any directives. Asking for everything at once
  weakens the asks that have no workaround.
