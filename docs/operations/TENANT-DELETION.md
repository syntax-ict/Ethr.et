# Deleting an organisation

| At a glance | |
|---|---|
| **Purpose** | Says what deleting and restoring an organisation do, why deletion is a closure and not an erasure, and how an organisation that has left is erased after 30 days. |
| **Open it when** | You are about to delete, restore or erase an organisation, or an organisation asks what deletion means or for its data to be destroyed. |
| **For** | Platform operator (super admin) |
| **Organisation types** | Same for every kind. [`ORGANISATION-TYPES.md`](ORGANISATION-TYPES.md) |
| **Source of truth** | The code: `destroy` and `restore` in `api/app/Http/Controllers/Api/V1/Admin/AdminTenantController.php`; erasure in `api/app/Services/Tenancy/OrganisationErasure.php`. |

Added 2026-10-19. The platform console (`admin.ethr.et` → Tenants → an
organisation) can delete an organisation and restore it. This page says what
that does, and why it is built the way it is.

## What "delete" means here

Deleting **closes** an organisation. It does not erase its records.

| Happens | Does not happen |
|---|---|
| The organisation stops resolving: its address, sign-in page and public pages answer "not found" at once (the resolver cache is cleared in the same request) | No employee, payroll, attendance, leave or document record is removed |
| Every signed-in session of its people ends (their API tokens are revoked) | The audit log is untouched; it is append-only by database trigger |
| Its devices, kiosks, API keys and SCIM stop being accepted | Its subdomain is **not** released: it stays reserved, so no one else can register it and receive its people's links |
| It leaves the console's tenant list and the dashboard counts; it is listed under the **Deleted** filter | Nothing is emailed to the organisation |
| An `admin.tenant.deleted` audit entry records who, when, and the reason given | |

**Restore** brings it back exactly as it was before deletion: cancelled, with
its records and address. It is recorded as `admin.tenant.restored`.

## Why it is a closure and not an erasure

- `payroll_runs`, `payroll_entries` and `attendance_records` are NeverDelete
  (convention 14), and `audit_log` refuses UPDATE and DELETE at the database.
- Nearly every `tenant_id` foreign key is `ON DELETE CASCADE`. A hard delete of
  the `tenants` row would cascade through the tenant tables at the database
  level (103 have a `tenant_id` as of 2026-11-25; this said 68) and bypass
  NeverDelete entirely, and leave the audit log, which has no foreign key,
  pointing at nothing.
- An employer in Ethiopia must keep payroll and tax records for years after the
  fact. ETHR deleting them on a console click would take that record away from
  an organisation that may still need it.

Permanent erasure is a separate, later step, run by the owner and
deliberately not in the console: [*Erasing an organisation that has
left*](#erasing-an-organisation-that-has-left) below.

## The safeguards, and why each is there

1. **Cancel first.** Delete is offered only on a cancelled organisation, so it
   is always the second of two deliberate steps. The server refuses anything
   else with 409 `tenant-not-cancelled`.
2. **A reason.** Required, 5 to 500 characters, stored in the audit entry.
3. **The console's usual gates**: super admin, `admin.manage`, platform MFA
   enabled (`RequirePlatformMfa`, so only an admin who signed in with a second
   factor reaches the endpoint), and the platform-admin rate limit.

Two earlier steps were removed by owner decision:

- **The address typed out** (sent as `confirm`), on 2026-10-26. The dialog
  already names the organisation and its address.
- **A current authenticator code at the moment of deletion** (sent as `code`),
  on 2026-10-27. What it protected against is a console session left open and
  unattended. That risk is now carried by the sign-in MFA, the cancel-first
  step, the audited reason, and Restore. `admin.tenant.delete_mfa_failed` is no
  longer written.

A client still sending `confirm` or `code` is not refused for it; both fields
are ignored.

The endpoint is `POST /api/v1/admin/tenants/{publicId}/delete`, not `DELETE`:
the reason travels in a request body, which some proxies drop from a DELETE,
and never in a URL. Restore is `POST …/restore`.

## Before you delete

- If the organisation may ask for its data, press **Backup Data** first. The
  backup still works on a deleted organisation, so it is not too late after.
- Tell the organisation yourself, if that is owed. Deleting sends nothing.

## A message to send first

Deleting emails the organisation nothing, so send this yourself, through a channel you know reaches
its administrators, **before** you delete. The organisation can then take its own copy while it can
still sign in ([`DATA-EXPORT.md`](DATA-EXPORT.md)). Fill in the brackets and keep the rest.

> Subject: Your ETHR organisation is being closed
>
> ETHR will close [organisation name] on [date], [at your request / because [reason]].
>
> From that moment its address, sign-in page and public pages no longer answer. Everyone in the
> organisation is signed out, and its devices, kiosks and API keys stop being accepted.
>
> No record is erased. If you need the organisation back, ask ETHR and it can be restored
> exactly as it was.
>
> Before it closes, your administrator can download a copy of everything the organisation holds
> from Settings, Data export.
>
> Contact [name, channel] with any question.

## Erasing an organisation that has left

Added 2026-11-25, owner decision. The Data Processing Agreement promises an
organisation that its data is destroyed after the contract ends (Proclamation
1321/2024 Art. 50), and a closure keeps everything. Erasure is how that promise
is kept. It cannot be undone except by restoring a backup of every
organisation, so it has three gates and a dry run.

**When.** Only an organisation that is **cancelled**, **deleted**, and was
deleted **at least 30 days ago** (`TENANT_ERASURE_GRACE_DAYS`, default 30). The
30 days are the window the DPA gives the organisation to collect its export
(`DATA-EXPORT.md`), and the time in which a deletion can still be restored.
Export first: an erased organisation has nothing left to export.

**How.** Production has no shell, so through the maintenance endpoint, with the
`MAINTENANCE_TOKEN` (set for the task, emptied afterwards):

```bash
# 1. What would go. Changes nothing.
curl -X POST https://ethr.et/api/v1/maintenance/erase-organisation \
  -H "X-Maintenance-Token: $TOKEN" -H 'Content-Type: application/json' \
  -d '{"address":"acme"}'

# 2. Erase. The address is typed twice.
curl -X POST https://ethr.et/api/v1/maintenance/erase-organisation \
  -H "X-Maintenance-Token: $TOKEN" -H 'Content-Type: application/json' \
  -d '{"address":"acme","execute":true,"confirm":"acme"}'
```

Locally, or wherever there is a shell: `php artisan ethr:erase-organisation
acme`, then `--execute --confirm=acme`.

| Answer | Means |
|---|---|
| 200 `dry-run`, `would_erase` | Rows per table, sign-in rows, files, and what is kept |
| 200 `ok`, `certificate` | Erased. Send the certificate to the organisation |
| 404 `not_found` | No **deleted** organisation has that address: delete it in the console first |
| 409 `not_cancelled`, `in_grace_period` (with `eligible_from`), `already_erased` | Not now, and why |
| 422 on `confirm` | `execute` was sent without the address repeated |

**What goes.**

- Every row, in every table with a `tenant_id` column, that belongs to the
  organisation. The tables are read from the schema at the time, not listed by
  hand, so a table added later is erased too.
- Rows of a table with no `tenant_id` that belong to the organisation through
  a parent, such as a custom role's permissions. The foreign keys are switched
  off for the deletion, so what each key's `ON DELETE CASCADE` or `SET NULL`
  would have done is done by hand. A `RESTRICT` key is left alone and has to be
  handled on purpose.
- Its users' API tokens, sessions, trusted devices, sign-in codes, and
  password-reset and activation tokens.
- Its files: `tenants/{public_id}/`, `exports/{public_id}/` and the console's
  per-organisation backups in `backups/{address}/`.

The records, the tombstone, the certificate and the audit row are one
transaction, children before parents, so they are erased all together or not
at all. That transaction re-reads the organisation under a lock, so a second
erasure started before the first finished is refused instead of run again.

The files go after it commits, because a disk cannot roll back. If some cannot
be removed, the answer says the records **were** erased and the files were not
(`ErasureIncomplete`, 500 `failed`). The certificate carries `files_pending`
until they are gone, and the organisation is accepted again until then: send
the same request to remove what is left.

**What stays, on purpose:**

| Kept | Why |
|---|---|
| `audit_log` rows | The table refuses DELETE by an earlier owner decision ([`../AUDIT_LOG_INTEGRITY_DECISION.md`](../AUDIT_LOG_INTEGRITY_DECISION.md)), and the owner chose on 2026-11-25 to keep it so. They are ETHR's own record of who did what, and the erasure is one of them (`admin.tenant.erased`). They can hold names and IP addresses in their payloads; the certificate says how many were kept |
| `invoices`, `subscriptions` | ETHR's own billing and tax records, of which ETHR is controller. Amounts and dates, no employee data |
| `tenant_subdomain_aliases` | So an address the organisation retired is never reissued |
| The `tenants` row, as a tombstone | Renamed *Erased organisation*, every other nullable field cleared (its own domain is released), still deleted. Its address stays reserved, so nobody else can register it and receive mail or links meant for the old organisation, and the audit log still names a row |
| Queued and failed jobs (`jobs`, `failed_jobs`) | **A gap, not a choice.** A payload names models by id, and a job that runs after the erasure finds nothing for its tenant. But a failed job's stored exception text can quote a row, and nothing removes it. It is not in the certificate |
| Copies inside backups | One archive holds every organisation, so it cannot be edited. They age out with rotation, and the certificate's `backups_rotate_out_by` is the date the last one is gone (the longer of `BACKUP_KEEP` and `BACKUP_OFF_HOST_KEEP` days) |

**The certificate** is stored on the tombstone (`erasure_certificate`) and
returned in the answer: the organisation's id, address and name before
erasure, when it was deleted and erased, rows per table, files, what was kept
and why, and when the backups rotate out.

**Not proven yet on MariaDB.** The suite runs it on SQLite
(`api/tests/Feature/Tenancy/OrganisationErasureTest.php`), which also proves
another organisation is left untouched to the row. Run `./scripts/gates.sh
mysql` before the first real erasure, and take a backup first.

## Organisation types

Deletion and restore behave the same for every kind of organisation. The kind and its dated history
(`tenant_profile_assignments`) are kept with every other record: the delete path revokes the
organisation's sign-in tokens and soft-deletes the organisation's row
(`AdminTenantController::destroy`), and touches nothing about its kind. Restore brings both back as
they were. See [`ORGANISATION-TYPES.md`](ORGANISATION-TYPES.md).

## Two things fixed alongside

- An unpaid invoice no longer turns a **cancelled** organisation back into a
  suspended one. The 60-day dunning tier now suspends only an active or trial
  organisation (`HandleOverdueInvoicesJob`).
- Device paths (ADMS, the vendor webhooks, the pull and command jobs) now
  refuse a device whose organisation is gone, instead of running with no
  organisation set.
