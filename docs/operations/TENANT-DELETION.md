# Deleting an organisation

| At a glance | |
|---|---|
| **Purpose** | Says what deleting and restoring an organisation do, and why deletion is a closure and not an erasure. |
| **Open it when** | You are about to delete or restore an organisation, or an organisation asks what deletion means. |
| **For** | Platform operator (super admin) |
| **Organisation types** | Same for every kind. [`ORGANISATION-TYPES.md`](ORGANISATION-TYPES.md) |
| **Source of truth** | The code: `destroy` and `restore` in `api/app/Http/Controllers/Api/V1/Admin/AdminTenantController.php`. |

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
- Every `tenant_id` foreign key is `ON DELETE CASCADE`. A hard delete of the
  `tenants` row would cascade through all 68 tenant tables at the database
  level and bypass NeverDelete entirely, and leave the audit log orphaned.
- An employer in Ethiopia must keep payroll and tax records for years after the
  fact. ETHR deleting them on a console click would take that record away from
  an organisation that may still need it.

Permanent erasure, if the owner ever wants it (for example for an organisation
that never had an employee), is a separate decision and a separate, reviewed
piece of work. It is deliberately not in the console.

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
