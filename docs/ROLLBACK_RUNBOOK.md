# Rollback

**On shared hosting a rollback is a revert on `main`**, which produces a new release that
Plesk Git deploys; a deploy never undoes migrations. The runbook is
[`deployment/shared-hosting/rollback.md`](deployment/shared-hosting/rollback.md), and the
on-call entry point is [`operations/ON-CALL.md`](operations/ON-CALL.md).

The runbook that used to live at this path ended every scenario with "repoint DNS at the
VPS". The VPS was removed on 2026-09-26 and there is no such target
([`deployment/VPS-DECOMMISSION.md`](deployment/VPS-DECOMMISSION.md)). It is kept as a record at
[`archive/vps/ROLLBACK_RUNBOOK.md`](archive/vps/ROLLBACK_RUNBOOK.md).
