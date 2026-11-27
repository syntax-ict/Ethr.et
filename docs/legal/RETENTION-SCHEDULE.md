# Retention schedule

> **DRAFT — NOT LEGAL ADVICE.** Read out of the code on 2026-11-25. Counsel has not confirmed it. Linked from register rows L12 and L21.

How long ETHR keeps each kind of data, **as built**. Proclamation 1321/2024 requires personal data to be kept no longer than the purpose needs (Arts. 6(5) and 15(1), class 1). It allows longer retention where the law requires it (Art. 15(2), class 1). No statutory retention period for employment records was found ([`../EMPLOYMENT-LAW-REGISTER.md`](../EMPLOYMENT-LAW-REGISTER.md)). Tax and pension record-keeping periods were not checked (class 3).

**A change to any period in the code changes this table in the same pull request.** `tests/Feature/DataRetentionSweepTest.php` pins the automatic deletions.

## Deleted automatically

| Data | Kept for | Then | Source |
|---|---|---|---|
| Attendance selfies and their hash | The organisation's period after the record's last punch: **7 days** by default, 1 to 90 allowed, then a 48-hour review | Files permanently deleted unless held; each deletion audit-logged. The attendance record stays | `App\Services\Attendance\PhotoRetention`; [`../operations/ATTENDANCE-PHOTO-RETENTION.md`](../operations/ATTENDANCE-PHOTO-RETENTION.md) |
| Presence checks (location during a shift) | 90 days | Deleted | `PresenceChecks::RETENTION_DAYS` |
| Raw device events | Until processed or dismissed, then 7 days. The raw text is nulled on processing | Deleted | `CleanupExpiredDataJob`; `ProcessDeviceInboundPunchesJob` |
| In-app notifications | 90 days | Deleted | `CleanupExpiredDataJob` |
| Webhook delivery logs | 30 days | Deleted | `CleanupExpiredDataJob` |
| Import staging data | 30 days after its last activity | Deleted | `MigrationBatch::RETENTION_DAYS` |
| Organisation data exports | 7 days | File and row deleted | `TenantExport::EXPIRES_DAYS` |
| Activation codes, device challenges, overdue starting passwords | When expired | Deleted | Daily purges |
| On-host backups | The newest `BACKUP_KEEP` (7 in production since 2026-11-02) | Older archives deleted | `api/config/backup.php`; [`../deployment/BACKUP-RESTORE.md`](../deployment/BACKUP-RESTORE.md) |

## Kept, on purpose

| Data | Why | Source |
|---|---|---|
| Attendance records | Payroll and the attendance audit depend on them | Soft-delete policy, [`../CLAUDE.md`](../CLAUDE.md) |
| Payroll runs and entries, with their calculation log | Financial audit; tax and pension filings | Same |
| Audit log | Integrity. The table refuses UPDATE and DELETE at database level | `2026_07_22_000001_restrict_audit_log_to_insert_only.php` |
| Employees, documents, leave requests | Soft-deleted, so history stays consistent | Same |
| Sign-in history | Account security | `login_histories`; no purge exists |

## Gaps the owner must decide

1. **Closing an organisation is not erasure, but erasure now exists.** Closing suspends access and keeps the records. From 30 days after closing, the owner can erase them (`OrganisationErasure`, [`../operations/TENANT-DELETION.md`](../operations/TENANT-DELETION.md)), which keeps only the audit log, invoices and a tombstone. Nothing erases automatically: it is an owner action, on the organisation's request or at the end of its contract.
2. **No period exists for records that are kept, on purpose, without limit.** "Never delete" is right while an organisation is a customer. It needs an end once it is not: `[confirm: the tax and pension record-keeping period, with the accountant]`.
3. **Sign-in history has no limit.** `[confirm: a period, e.g. one year]`.
4. **There is no per-employee export or erasure** of an active employee's record. Requests are handled by ETHR by hand, on the organisation's instruction.
