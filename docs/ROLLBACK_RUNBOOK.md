# Rollback Runbook — Shared Hosting Migration

Three scenarios, ordered by how much has actually happened — read down to the one that
matches reality, not from the top unconditionally.

> ## ⚠ READ FIRST — corrected 2026-09-18
>
> This runbook says to *"read down to the one that matches reality."* **Scenario A below
> claimed to be that reality, and is not.** A reader following the instruction stops at A
> and concludes there is nothing to roll back.
>
> **`ethr.et` no longer resolves to the VPS.** It resolves to the Plesk host
> `213.55.96.154` — measured twice on 2026-09-17. DNS cutover has, in effect, already
> happened, out of order and with `deploy-checklist.md` unrun. So **the current state is
> Scenario B or C, not A.**
>
> **And Scenario B is not executable.** It says to repoint at the VPS. **0a has now been
> run — 2026-09-18 — and the VPS serves nothing:** port 80 fails to connect, port 443
> resets. Two controls in the same pass (`1.1.1.1` → 301, the Plesk host by IP → 200)
> prove the vantage point reaches arbitrary hosts, so this is the VPS's state, not a
> sandbox limitation. It agrees with the 2026-08-29 measurement, 20 days on and by a
> different route.
>
> **So repointing today converts one outage into two**, and the TTL caches the bad answer.
> Scenario B has no target. The real options are to bring the VPS back up *first*, or to
> fix forward on Plesk — an owner decision, taken before an incident, not during one.
>
> **What `ethr.et` serves right now**, measured in the same pass: the apex and `www`
> return **`404`** (Plesk's error page), and a never-configured wildcard name
> (`zzq7x.ethr.et`) returns **`200`** with Plesk's default page. The domain is live
> against nothing, and because the wildcard answers 200, **any check that asserts "the
> tenant subdomain returns 200" passes against nothing** — assert on content.
>
> One caveat, so the negative is not over-trusted: a source-IP firewall would look
> identical from one vantage point. Confirm from your own network before acting on it in
> an incident. But two vantage points 20 days apart both find it closed, so treat the
> rollback target as **absent until shown otherwise**. See `docs/MIGRATION_STATE.md` → B-6.

## ~~Scenario A — before DNS cutover (the current state, as of this writing)~~

**Superseded 2026-09-18 — this is no longer the current state.** Retained because it
remains the correct procedure *if* a future deployment is ever again pre-cutover, and
because the reasoning in its last sentence still holds.

~~Nothing to roll back. `ethr.et` still points at the VPS~~ (dormant — no live traffic;
verified in `docs/B1-B5_GATE_REPORT.md`). The shared-hosting deployment exists in
isolation, reachable only by IP or a manually-resolved hostname during verification.
Abandoning it means: stop, delete the account's files if desired, done. No DNS change,
no data reconciliation, no user impact — this is why
`docs/deployment/shared-hosting/DEPLOYMENT.md` puts verification (steps 1–7) before cutover
(step 8) rather than the reverse.

That last point is worth keeping in view precisely because it was not followed: cutover
appears to have preceded verification here, which is what moved this document from
"reference" to "load-bearing".

## Scenario B — after DNS cutover, before any new write on the shared-hosting side

This is the expected state for the first stretch after cutover if traffic is being
watched rather than assumed fine.

```
Repoint the A/AAAA records for ethr.et, www.ethr.et, and *.ethr.et back to the VPS IP.
```

> **Do not run this line. 0a was answered on 2026-09-18 and the answer is no.** The VPS
> does not serve: port 80 fails to connect, port 443 resets, with controls in the same
> pass proving the vantage point reaches other hosts. Repointing at it does not restore
> the previous state — it replaces a questionable deployment with no deployment, and the
> TTL below then works against you, because the bad answer is what gets cached.
>
> **This scenario has no target until the VPS is brought back up.** The real options are
> to do that first, or to fix forward on Plesk. That is an owner decision, and it should
> be taken now rather than during an incident — which is the point of recording it here
> instead of leaving the instruction to be discovered mid-outage.
>
> Re-verify from your own network before relying on this in an incident; a source-IP
> firewall looks the same from one vantage point. See `docs/MIGRATION_STATE.md` → B-6.

DNS TTL determines how long this takes to fully propagate — check what TTL was set
before cutover and expect stragglers up to that long. The VPS is untouched by anything
in the shared-hosting deployment (it's a separate host with its own database), so
**if it were serving**, the moment DNS propagated back the application would be exactly
as it was before this migration started, with nothing to undo on the VPS side.

**That conditional is doing all the work, and as of 2026-09-18 it does not hold.**
"Untouched" was written to mean "intact and ready", and the host answering nothing on
either port is the difference between those two readings. Nothing in the shared-hosting
work broke it; it simply is not up.

**If the shared-hosting database received zero writes** (no new tenant signup, no
login, nothing) during the window it was live, this scenario applies even if some time
has passed — check `SELECT MAX(updated_at) FROM tenants` (and the equivalent on a few
other high-traffic tables) against the cutover timestamp before assuming Scenario C.
**This is SQL, and no route to run SQL on the Plesk account has been verified** (B-4;
phpMyAdmin has not been confirmed present). Establish that route before it is needed —
during an incident is the wrong time to discover that the deciding query cannot be run.

## Scenario C — after real writes on the shared-hosting side

The DNS revert in Scenario B is still step one — stop new traffic from reaching the
shared-hosting deployment immediately, then decide what to do with what it accepted:

1. **Export what changed.** `mysqldump` the shared-hosting database, or at minimum the
   tables that could plausibly have new rows (`users`, `tenants`, `employees`,
   `attendance_records`, `leave_requests` — whatever the live window's traffic touched).
2. **Decide: merge or discard.** If it's a handful of test signups from verification
   traffic that leaked through, discard — restore the VPS from its own last backup
   (`scripts/restore.sh`) and treat the shared-hosting writes as void. If real tenant
   data was created (a customer signed up, an employee clocked in), it needs manual
   reconciliation against the VPS's database — this is a business decision about which
   copy is authoritative, not a scripted step, and depends entirely on what actually
   happened during the live window.
3. **Audit log survives regardless.** Whatever choice is made, the `audit_log` table on
   the shared-hosting side is itself immutable (assuming H1 passed) and is evidence of
   exactly what happened during the live window — pull it before discarding anything.

This scenario is why `docs/deployment/shared-hosting/deploy-checklist.md` exists: the
whole point is to make Scenario C unlikely by catching problems in Scenario A's
window, where rollback costs nothing.

## Code-level rollback (independent of the DNS/data scenarios above)

Every merge to `main` from this migration effort is `--no-ff`, specifically so each has
a single revert point:

```bash
git revert -m 1 <merge-commit-sha>
```

This does not need to happen in the same order as, or be triggered by, a DNS-level
rollback above — reverting the driver-swap commits (`CACHE_STORE=database` etc.) only
matters if going back to needing the VPS's Redis/MinIO/Reverb, and even then those
commits made the code *support* either configuration via `.env`, not *require* the
non-VPS one — so a VPS rollback needs no code revert at all, only pointing `.env` back
at the VPS's own values. See `docs/SHARED_HOSTING_AUDIT.md` §D: none of the changed
code became VPS-incompatible, it became configuration-driven.

## What is deliberately not automated

There is no single rollback script for this migration, unlike `scripts/rollback.sh`
for a VPS same-host redeploy. A cross-host migration's rollback is a DNS change plus a
data decision, not a container restart — automating the data-reconciliation half of
Scenario C would mean guessing what "correct" means for data that hasn't been created
yet, which is a decision for whoever is on call at the time, not one to encode in
advance.
