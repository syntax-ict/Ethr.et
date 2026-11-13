# Operations

**Start here.** This folder is 28 documents; this page routes by the situation you are in, not by
the document you might need. Added 2026-11-13, because until then a new operator had a list of
filenames and no order to read them in.

**Links only, on purpose.** Not a figure, not a threshold, not a command. Every fact restated
here is a fact that can drift out of step with the document that owns it, and that is exactly how
three documents in this folder came to describe a 5 GB host, a crontab entry and an unchosen
scheduler.

## Something is wrong now

| The situation | Go to |
|---|---|
| **Something is broken and I do not know what** | [`ON-CALL.md`](ON-CALL.md) — symptom, likely cause, action, on a host with no shell. The first table is the first five minutes |
| **I can sign in and want the whole picture** | [`OPERATIONS-CENTRE.md`](OPERATIONS-CENTRE.md) — reading `/admin/operations`: every state, every reason code, what to do about each |
| **Nothing asynchronous is happening** — no emails, no reports, no device sync, no backup | [`OPERATIONS-CENTRE.md`](OPERATIONS-CENTRE.md) → *Background work*, then [`QUEUE-MONITORING.md`](QUEUE-MONITORING.md) for how the callers work and why a stale heartbeat alone is routine here |
| **Data is wrong, or gone** | [`ON-CALL.md`](ON-CALL.md) → *Data is wrong or lost*, then [`BACKUP-RESTORE.md`](../deployment/BACKUP-RESTORE.md). Read both before touching a dump: restoring through phpMyAdmin breaks the audit log's triggers and every save afterwards returns 500 |
| **Email is not arriving** | [`OPERATIONS-CENTRE.md`](OPERATIONS-CENTRE.md) → *Delivery* |
| **Text messages are not arriving** | [`SMS-DELIVERY.md`](SMS-DELIVERY.md) |
| **A release went wrong** | [`rollback.md`](../deployment/shared-hosting/rollback.md), and [`OPERATIONS-CENTRE.md`](OPERATIONS-CENTRE.md) → *Release* for the steps a deploy may have missed |
| **A device or terminal stopped reporting** | [`../DEVICE_INTEGRATION.md`](../DEVICE_INTEGRATION.md) |

## Personal data, and the law

| The situation | Go to |
|---|---|
| **Personal data may have been seen, changed, lost or taken** | [`DATA-BREACH-RESPONSE.md`](DATA-BREACH-RESPONSE.md) — the first hour, the 24-hour duty to the organisations, the 72-hour clock. **Read it before changing anything**, because containment can destroy the evidence |
| An employee asks about their attendance selfie | [`ATTENDANCE-PHOTO-CONSENT.md`](ATTENDANCE-PHOTO-CONSENT.md), and [`ATTENDANCE-PHOTO-RETENTION.md`](ATTENDANCE-PHOTO-RETENTION.md) for how long it is kept and who may hold it back |
| An organisation asks what ETHR holds about its people | [`DATA-EXPORT.md`](DATA-EXPORT.md) |
| I need to look inside an organisation | [`IMPERSONATION.md`](IMPERSONATION.md) — the only sanctioned way, and what it refuses to let you do |
| What the employment law register says | [`../EMPLOYMENT-LAW-REGISTER.md`](../EMPLOYMENT-LAW-REGISTER.md) |

## A customer needs something

| The situation | Go to |
|---|---|
| **An organisation asked for help** | [`SUPPORT.md`](SUPPORT.md) — how it reached ETHR, who can see it, and where to answer |
| **An organisation looks stuck**, or I want to know who is actually using ETHR | [`TENANT-JOURNEY.md`](TENANT-JOURNEY.md) — the stages, and the health reason codes behind the console's badges |
| An organisation wants its data | [`DATA-EXPORT.md`](DATA-EXPORT.md) |
| **An organisation is leaving** | [`DATA-EXPORT.md`](DATA-EXPORT.md) first, then [`TENANT-DELETION.md`](TENANT-DELETION.md). Deletion keeps every record and emails nobody |
| An organisation wants to change its address | [`ORGANISATION-ADDRESS-RENAME.md`](ORGANISATION-ADDRESS-RENAME.md) |
| An organisation wants to be named on the ETHR site | [`TRUSTED-BY.md`](TRUSTED-BY.md) |
| An organisation asks about its own public pages | [`ORGANISATION-NEWS.md`](ORGANISATION-NEWS.md), [`ORGANISATION-FAQ.md`](ORGANISATION-FAQ.md), [`ORGANISATION-SERVICES.md`](ORGANISATION-SERVICES.md) |
| A figure on a dashboard is being questioned | [`METRIC-DEFINITIONS.md`](METRIC-DEFINITIONS.md) |

## Doing the work

| The situation | Go to |
|---|---|
| **Releasing today** | [`PLESK-GO-LIVE.md`](../deployment/PLESK-GO-LIVE.md). Deploy and `migrate` are one step. Afterwards, [`OPERATIONS-CENTRE.md`](OPERATIONS-CENTRE.md) → *Release* names any step that was missed |
| **Can we go live?** | [`CUTOVER-CHECKLIST.md`](../deployment/CUTOVER-CHECKLIST.md) — the one register that decides. A green CI run moves none of it |
| What is in the console, and what each page does | [`PLATFORM-CONSOLE.md`](PLATFORM-CONSOLE.md) |
| Which command does what, and what runs when | [`COMMANDS-AND-SCHEDULE.md`](COMMANDS-AND-SCHEDULE.md) |
| **A credential holder is unreachable** | [`CUSTODY.md`](CUSTODY.md) — who holds what, and whom to ask. Names and places, never values |
| I am new to maintaining this | [`../ENGINEERING-ONBOARDING.md`](../ENGINEERING-ONBOARDING.md), then [`ON-CALL.md`](ON-CALL.md) and [`CUSTODY.md`](CUSTODY.md) |
| Getting ETHR found in search | [`SEARCH-LISTINGS.md`](SEARCH-LISTINGS.md) |
| Editing the public site | [`MARKETING-SITE.md`](MARKETING-SITE.md), [`MARKETING-VISUALS.md`](MARKETING-VISUALS.md) |
| Selling it | [`marketing-deck/README.md`](marketing-deck/README.md), [`social-kit/README.md`](social-kit/README.md) |

## The routine calendar

Nothing here is automatic. Each row is work a person has to remember, which is why it is written
down; the documents hold the detail.

| When | What | Where |
|---|---|---|
| **Every release** | `migrate` in the same step as the deploy, then `clear` and `optimize`; empty `MAINTENANCE_TOKEN` afterwards. Check the release area is green | [`PLESK-GO-LIVE.md`](../deployment/PLESK-GO-LIVE.md), [`OPERATIONS-CENTRE.md`](OPERATIONS-CENTRE.md) |
| **Daily, passively** | The off-host monitor polls the health and backup endpoints and emails on failure. If those emails stop arriving, nothing is watching | [`QUEUE-MONITORING.md`](QUEUE-MONITORING.md) |
| **Mondays** | The custom-domain recheck runs and tells nobody. It writes to the platform audit log | [`ON-CALL.md`](ON-CALL.md), [`COMMANDS-AND-SCHEDULE.md`](COMMANDS-AND-SCHEDULE.md) |
| **1st of the month** | Invoices are generated, and next year's holidays roll forward | [`COMMANDS-AND-SCHEDULE.md`](COMMANDS-AND-SCHEDULE.md) |
| **Each organisation's own leave year** | Carry-forward runs in its first seven days, which is not 1 January for every organisation | [`COMMANDS-AND-SCHEDULE.md`](COMMANDS-AND-SCHEDULE.md) |
| **Quarterly, and whenever someone joins or leaves** | Confirm two named holders can actually use every credential, and date the review. **No review has been recorded yet** | [`CUSTODY.md`](CUSTODY.md) |
| **When the mail provider renews its certificate** | The pin can be removed; the log says so, and delivery returns to `verified` by itself | [`OPERATIONS-CENTRE.md`](OPERATIONS-CENTRE.md) |
| **Outstanding, not scheduled** | The restore path has never been rehearsed on this host. A backup that has never been restored is not a backup | [`ON-CALL.md`](ON-CALL.md), [`M6-RESTORE-REHEARSAL.md`](../deployment/M6-RESTORE-REHEARSAL.md) |

## What is waiting on the owner

Recorded so it is not rediscovered each time. None of it is a code task.

| | Where it is recorded |
|---|---|
| The *Holders* column is still placeholders, and no quarterly review has been dated | [`CUSTODY.md`](CUSTODY.md) |
| Adopting the breach procedure, counsel confirming its reading of Proclamation 1321/2024, ECA registration, and a monitored address to publish in `SECURITY.md` | [`DATA-BREACH-RESPONSE.md`](DATA-BREACH-RESPONSE.md) |
| Choosing an off-host backup destination. The mechanism is built and set in the console; nothing is chosen, so the backups area reads a warning | [`PLATFORM-CONSOLE.md`](PLATFORM-CONSOLE.md), [`OPERATIONS-CENTRE-SPEC.md`](OPERATIONS-CENTRE-SPEC.md) G14 |
| Rehearsing the restore on this host | [`M6-RESTORE-REHEARSAL.md`](../deployment/M6-RESTORE-REHEARSAL.md) |
| The mailbox's send cap, which only Ethio Telecom can state | [`ETHIO-TELECOM-SUPPORT-REQUEST.md`](../deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md) |
| An SMS subscription. SMS is configured but off, and invitations by text need a "set a password" step first | [`SMS-DELIVERY.md`](SMS-DELIVERY.md) |

## Everything in this folder

Grouped by what it is, for when routing by situation is not what you want.

**Runbooks** — [`ON-CALL.md`](ON-CALL.md) ·
[`OPERATIONS-CENTRE.md`](OPERATIONS-CENTRE.md) ·
[`PLATFORM-CONSOLE.md`](PLATFORM-CONSOLE.md) ·
[`COMMANDS-AND-SCHEDULE.md`](COMMANDS-AND-SCHEDULE.md) ·
[`QUEUE-MONITORING.md`](QUEUE-MONITORING.md) ·
[`DATA-BREACH-RESPONSE.md`](DATA-BREACH-RESPONSE.md) ·
[`CUSTODY.md`](CUSTODY.md)

**How a feature works, and what deploying it needs** —
[`TENANT-JOURNEY.md`](TENANT-JOURNEY.md) ·
[`TENANT-DELETION.md`](TENANT-DELETION.md) ·
[`DATA-EXPORT.md`](DATA-EXPORT.md) ·
[`SUPPORT.md`](SUPPORT.md) ·
[`IMPERSONATION.md`](IMPERSONATION.md) ·
[`SMS-DELIVERY.md`](SMS-DELIVERY.md) ·
[`ORGANISATION-ADDRESS-RENAME.md`](ORGANISATION-ADDRESS-RENAME.md) ·
[`ORGANISATION-NEWS.md`](ORGANISATION-NEWS.md) ·
[`ORGANISATION-FAQ.md`](ORGANISATION-FAQ.md) ·
[`ORGANISATION-SERVICES.md`](ORGANISATION-SERVICES.md) ·
[`TRUSTED-BY.md`](TRUSTED-BY.md)

**Policy** — [`ATTENDANCE-PHOTO-CONSENT.md`](ATTENDANCE-PHOTO-CONSENT.md) ·
[`ATTENDANCE-PHOTO-RETENTION.md`](ATTENDANCE-PHOTO-RETENTION.md)

**Reference** — [`METRIC-DEFINITIONS.md`](METRIC-DEFINITIONS.md) ·
[`OPERATIONS-CENTRE-SPEC.md`](OPERATIONS-CENTRE-SPEC.md) (design and gap list, not a runbook)

**Getting ETHR known** — [`SEARCH-LISTINGS.md`](SEARCH-LISTINGS.md) ·
[`MARKETING-SITE.md`](MARKETING-SITE.md) ·
[`MARKETING-VISUALS.md`](MARKETING-VISUALS.md) ·
[`marketing-deck/README.md`](marketing-deck/README.md) ·
[`social-kit/README.md`](social-kit/README.md)

## Three things about this host that shape every answer here

Stated once, because an instruction that assumes otherwise will mislead. The detail is in
[`ON-CALL.md`](ON-CALL.md) and the root [`CLAUDE.md`](../../CLAUDE.md).

1. **There is no shell and no worker process.** Release tasks run through a token-guarded HTTP
   endpoint; background work runs when something reaches Laravel.
2. **The console cannot tell you the site is down.** A dead host cannot render the page that says
   so. That is what the off-host monitor is for.
3. **Nothing in the product emails an operator about infrastructure.** Two HTTP endpoints and an
   off-host monitor are the whole alerting system
   ([`OPERATIONS-CENTRE.md`](OPERATIONS-CENTRE.md), *What actually reaches a human*).
