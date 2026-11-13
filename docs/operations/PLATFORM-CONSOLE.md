# The platform console, page by page

**What each page under `/admin` is for, and where it is documented in depth.** Written
2026-11-13, because five of the ten pages had no operations document of any kind and an
operator's only way to learn what they did was to open them and guess.

This is a tour, not a re-description of each feature. Where a page has its own document, this
page says so in one line and gets out of the way.

## Who reaches it, and on which host

Every route behind these pages sits behind three middlewares, in order
(`api/routes/api.php`):

| | |
|---|---|
| `EnsurePlatformContext` | The **platform host** only. With `APP_DOMAIN` set, a request on a tenant host gets **404** — not 403, so the console does not advertise its existence from a tenant's address |
| `RequirePlatformMfa` | A super admin who signed in **with a second factor**. Having MFA enabled is not enough; the session must have used it |
| `throttle:platform-admin` | The platform-admin rate limit |

Plus `admin.manage` on the pages that change anything. A platform admin's own session is
**refused on tenant hosts** by `EnsureUserBelongsToTenant`, deliberately: the sanctioned way to
see an organisation's data is impersonation, which is MFA-gated, time-boxed and audited, and
roaming tenant hosts directly would bypass all three
([`IMPERSONATION.md`](IMPERSONATION.md)).

`ethr:reset-mfa` exists for an admin who lost their authenticator, and it needs someone who can
reach the host.

---

## `/admin` — console home

Revenue, the health card, failed jobs with retry and dismiss, a tail of the platform audit log,
and the operations strip that links into each area of the page below.

## `/admin/operations` — operations centre

**Is ETHR doing its work right now, for every organisation?** Six areas — background work,
failed jobs, email delivery, release and configuration, services, backups and disk — each with a
state, reason codes and the time of its last evidence.

Read it with [`OPERATIONS-CENTRE.md`](OPERATIONS-CENTRE.md), which covers every state and reason
it can show. Its design and remaining gaps are in
[`OPERATIONS-CENTRE-SPEC.md`](OPERATIONS-CENTRE-SPEC.md).

## `/admin/tenants` and `/admin/tenants/{id}` — organisations

The list carries the funnel, stage and health columns and their filters. **The per-organisation
page is where every consequential operator action lives**, which is worth knowing before you go
looking for them elsewhere:

| Action | Consequence | Documented in |
|---|---|---|
| Change status (active, suspended, cancelled) | Suspension stops the organisation resolving and ends its people's sessions | |
| Extend trial | Moves the trial end date | |
| Mark invoice paid | Puts a past-due subscription back to active once nothing is 30 days overdue | |
| **Impersonate** | Acts as that organisation's admin for 30 minutes. MFA re-checked, every action audited and tagged `impersonated_by` | [`IMPERSONATION.md`](IMPERSONATION.md) |
| Assign or verify a **custom domain** | Needs the `custom_domain` add-on; resolves only after the TXT token and CNAME check out | [`ORGANISATION-ADDRESS-RENAME.md`](ORGANISATION-ADDRESS-RENAME.md) |
| **Backup Data** | A per-organisation backup, queued. Still works on a deleted organisation | [`TENANT-DELETION.md`](TENANT-DELETION.md) |
| **Delete** | Closes the organisation and keeps every record. Offered only on a cancelled one, needs a reason, reversible with Restore | [`TENANT-DELETION.md`](TENANT-DELETION.md) |
| **Restore** | Brings it back exactly as it was before deletion | [`TENANT-DELETION.md`](TENANT-DELETION.md) |
| On the ETHR website | A platform veto on naming this organisation publicly; its own consent is untouched | [`TRUSTED-BY.md`](TRUSTED-BY.md) |
| The Journey card | Where this organisation is between sign-up and using ETHR | [`TENANT-JOURNEY.md`](TENANT-JOURNEY.md) |

**Before deleting anything**, note that deletion sends the organisation no email at all. Tell
them yourself if that is owed, and point them at Settings → Data export first
([`DATA-EXPORT.md`](DATA-EXPORT.md)).

## `/admin/support` — the support desk

Every help request an organisation passed on to ETHR, from every organisation, with the
requester, the colleague who passed it on and their private note, the technical details and the
conversation. [`SUPPORT.md`](SUPPORT.md) covers who sees what and the status flow.

## `/admin/audit` — the platform audit trail

**The actions with the largest blast radius are the ones only this page can show.** Impersonation,
organisation suspension and platform bank-account changes are recorded outside any single
organisation's scope, so the tenant-scoped view at `/settings/audit-logs` cannot see them; before
this page existed, nobody could review them.

The audit log is append-only at the database — triggers refuse UPDATE and DELETE — so it is the
evidence that survives a change of mind, and it is where
[`DATA-BREACH-RESPONSE.md`](DATA-BREACH-RESPONSE.md) says to start assessing scope. Note that
`audit_log` is **not** restorable from a dump taken under another database user: the triggers'
definer goes missing and every save in the product then returns 500.

## `/admin/backups` — off-host backup storage

**Where the off-host destination is configured, since 2026-11-01 — in the console, not `.env`.**
The bucket, a connection test before anything relies on it, the generated archive key (revealable
once), and the on/off switch. No deploy and no file edit.

While no destination is set, the operations centre reads `off_host_not_configured`, which is a
**warning by design** rather than an alarm: the on-host backups are fine, and choosing a
destination is the owner's call. That is the state today. The nightly run copies an encrypted
archive whenever both storage and a key are set.

Keep the archive key somewhere off the host. Without it, no off-host archive opens — and without
the `APP_KEY` a backup was taken under, `Employee.tin` and `national_id` are unreadable even from
a backup that restores cleanly. [`BACKUP-RESTORE.md`](../deployment/BACKUP-RESTORE.md).

## `/admin/features` — feature control

Every catalogued feature with its platform state, the reason given for the last change, and how
many organisations hold a grant or a revoke. Two levels: the **platform** switch for everyone,
and per-organisation grants and revokes.

**Every change takes a reason**, and the reason is stored. A feature that is `overridable` can be
set per organisation; one that is `switchable` can be turned off platform-wide. Treat a
platform-wide switch as a release-sized action: it changes what every organisation can do, at
once, with no deploy to review it.

## `/admin/plans` — the plan catalogue

The plans the public pricing page renders, including creating one, editing one and retiring one.
A plan carries its feature set, so editing one changes what its subscribers can do.

**The public page reads a snapshot**, so a plan change needs the snapshot step every release
depends on — see [`MARKETING-SITE.md`](MARKETING-SITE.md). A plan edited here and not snapshotted
is live for billing and stale on the pricing page.

## `/admin/platform-settings` — ETHR's own details

The platform's own identity and the things customers see about ETHR rather than about
themselves: **the bank account organisations pay into** and the payment instructions, the
platform name and tagline, the logo, contact email and phone, the office address, social links,
the headline metrics, the testimonial and its recorded consent date, the Google Maps key, and
the *Powered by ETHR* product mark that tenant sidebars carry.

Most fields come in `en`, `am` and `om`. Two carry more weight than their neighbours: the **bank
account**, because changing it redirects where customers send money and is audited on the
platform trail for that reason; and the **testimonial**, which is published only with a recorded
consent date, the same rule the customer list follows
([`TRUSTED-BY.md`](TRUSTED-BY.md)).

## `/admin/sms` — SMS delivery

Gateways, the routing between the two Ethiopian networks, the test send, the kill switch and the
daily cap. Configured here rather than in `.env`, and a gateway can only be switched on after a
test send has passed. [`SMS-DELIVERY.md`](SMS-DELIVERY.md), including what to do on the day ETHR
subscribes to a provider.

## `/admin/marketing` — the public landing page

Which sections and rows the public site shows, their text and pictures. The server refuses claims
the site may not make (`PermittedMarketingClaim`). [`MARKETING-SITE.md`](MARKETING-SITE.md), and
[`MARKETING-VISUALS.md`](MARKETING-VISUALS.md) for the illustrations.

---

## What is not in the console, and deliberately

| | Where it is instead |
|---|---|
| Release steps — `migrate`, `seed`, `optimize`, `clear`, `mail-probe`, `backups`, `backup`, `restore` | The token-guarded maintenance endpoint. Registered in `api/bootstrap/app.php`, **not** `api/routes/api.php`, because its throttle's cache table does not exist until `migrate` has run. [`PLESK-GO-LIVE.md`](../deployment/PLESK-GO-LIVE.md) |
| Permanent erasure of an organisation | Not built. Delete is a closure that keeps every record, and erasure would need its own decision and its own review ([`TENANT-DELETION.md`](TENANT-DELETION.md)) |
| A platform super admin's first account | `ethr:create-admin` on the host, or the `create-admin` maintenance task |
| Resetting a lost authenticator | `ethr:reset-mfa` on the host |
| Draining the queue or running a task now | Nothing. The console is read-only here (`OPERATIONS-CENTRE-SPEC.md`, G11) |

## Related

| | |
|---|---|
| Where to start for any situation | [`README.md`](README.md) |
| Symptom to action | [`ON-CALL.md`](ON-CALL.md) |
| Reading the operations page | [`OPERATIONS-CENTRE.md`](OPERATIONS-CENTRE.md) |
| Who holds which credential | [`CUSTODY.md`](CUSTODY.md) |
| What each role may do inside an organisation | [`../PERMISSIONS.md`](../PERMISSIONS.md) |
