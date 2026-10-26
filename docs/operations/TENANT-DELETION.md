# Deleting an organisation

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
3. **A current authenticator code.** Like impersonation: an unattended console
   session is not enough. A wrong code is recorded as
   `admin.tenant.delete_mfa_failed`.
4. **The console's usual gates**: super admin, `admin.manage`, platform MFA
   enabled, and the platform-admin rate limit.

Until 2026-10-26 the dialog also asked for the organisation's address typed
out, sent as `confirm`. The owner removed that step: the dialog already names
the organisation and its address, it must already be cancelled, and the code
is what proves a person is at the console. A client still sending `confirm` is
not refused for it; the field is ignored.

The endpoint is `POST /api/v1/admin/tenants/{publicId}/delete`, not `DELETE`:
the code and reason travel in a request body, which some proxies drop from a
DELETE, and never in a URL. Restore is `POST …/restore`.

## Before you delete

- If the organisation may ask for its data, press **Backup Data** first. The
  backup still works on a deleted organisation, so it is not too late after.
- Tell the organisation yourself, if that is owed. Deleting sends nothing.

## Two things fixed alongside

- An unpaid invoice no longer turns a **cancelled** organisation back into a
  suspended one. The 60-day dunning tier now suspends only an active or trial
  organisation (`HandleOverdueInvoicesJob`).
- Device paths (ADMS, the vendor webhooks, the pull and command jobs) now
  refuse a device whose organisation is gone, instead of running with no
  organisation set.
