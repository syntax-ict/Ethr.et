# Audit-Log Integrity — Security Decision (Phase A gate)

**Date:** 2026-08-29
**Subject:** the Phase A change to
`database/migrations/2026_07_22_000001_restrict_audit_log_to_insert_only.php`,
which converts a failed `CREATE TRIGGER` from a fatal migration error into
"log loudly and continue".

## CLASSIFICATION: 🔴 SECURITY DOWNGRADE

## ✅ DECIDED AND IMPLEMENTED, 2026-08-29 — fail-fast, with a diagnosis

Owner delegated the decision. **Resolution: the migration aborts if the triggers cannot
be created.** Implemented; `AuditLogImmutabilityTest` (4 tests) passes.

The implementation is slightly better than a plain revert. The original code failed with
a bare `SQLSTATE 42000`, which tells an operator nothing. It now catches the failure,
logs and prints the missing privilege plus the exact DROP/CREATE SQL a DBA needs, **and
then rethrows**. Fatal, but actionable.

`down()` keeps its guard — that one is not a security control, it only stops a rollback
from throwing on a trigger that was never created.

### Consequence, stated plainly

With VPS options withdrawn by the owner, this means: **if gate H1 fails, ETHR does not
deploy on this account until the privilege is granted.** That is the intended behaviour,
not an oversight. The escape hatch is not to weaken the migration — it is to ask Ethio
Telecom to grant `TRIGGER` to the `ethret` database user, which is an ordinary support
request and cheaper than any code change.

If they refuse, the decision returns to the owner as an explicit accepted-risk choice,
and the mechanism should then be a config flag (`AUDIT_LOG_REQUIRE_DB_IMMUTABILITY`,
defaulting to `true`) so the weakened mode is deliberate and greppable — never a silent
catch. That work is **not** done and should not be done pre-emptively.

---

*Original recommendation, retained for the reasoning:*

**Revert the change. Default to fatal — migration fails, deployment blocked.** No
compensating control has been technically demonstrated.

This reverses the position taken when the change was written. The evidence below is
what changed it: the codebase's own test suite proves the trigger is load-bearing, and
the application-level enforcement I cited as a mitigation does not cover the paths that
matter.

---

## 1. What security property does the trigger provide?

Two `BEFORE UPDATE` / `BEFORE DELETE` triggers on `audit_log` that raise
`SQLSTATE 45000`, making the table **append-only at the database engine level**
(ETHR convention #5).

The property is *tamper-evidence for privileged actors*. An audit log's value is
precisely that it cannot be edited by someone who has already compromised the
application or holds legitimate database access. Enforcement inside the application is
enforcement by the thing being audited.

This matters concretely for ETHR: `audit_log` records impersonation
(`admin.tenant.impersonated`, `admin.tenant.impersonation_mfa_failed`,
`admin.tenant.impersonation_handoff_issued`), tenant status changes, platform settings
changes, payroll and accounting exports, and API-key creation. These are exactly the
events a malicious platform admin would want to erase.

## 2. What remains if the trigger cannot be created?

Only application-level enforcement, in two places:

| Control | File | Covers |
| --- | --- | --- |
| `AuditLog::update()` → `throw new \LogicException` | `app/Models/AuditLog.php:60` | `$auditLog->update([...])` — **instance method only** |
| `NeverDelete::delete()` / `forceDelete()` → `throw` | `app/Traits/NeverDelete.php` | `$auditLog->delete()` — **instance method only** |

Verified: `AuditLog` registers **no** model event hooks (`booted`, `static::updating`,
`static::deleting`) and **no** custom Eloquent builder (`newEloquentBuilder`). The
guards are plain instance methods.

## 3. Can audit records be UPDATEd or DELETEd without the trigger?

**Yes.** The model override is bypassed by every mass-operation path, because
`Model::update()` and `Builder::update()` are different methods:

```php
DB::table('audit_log')->where(...)->update([...]);   // bypasses the model entirely
DB::table('audit_log')->where(...)->delete();        // bypasses the model entirely
AuditLog::where(...)->update([...]);                 // Builder::update, not Model::update
AuditLog::where(...)->delete();                      // Builder::delete, not Model::delete
DB::statement('UPDATE audit_log SET ...');           // raw SQL
```

None of these touch the overridden instance methods. Without the trigger, all four
succeed.

## 4. Does the application enforce immutability elsewhere?

No. Beyond the two instance-method overrides there is nothing — no policy, no
middleware, no observer, no repository boundary.

**The project's own test suite already establishes that the model guards are
insufficient.** `tests/Feature/AuditLogImmutabilityTest.php` deliberately tests the
raw query-builder path and asserts a database error:

```php
expect(fn () => DB::table('audit_log')
    ->where('action', 'test.immutable')
    ->update(['action' => 'test.tampered']))
    ->toThrow(QueryException::class);
```

Three of that file's four tests assert `QueryException` from `DB::table(...)`. They pass
**only because the trigger exists**. Its header comment is explicit about the intent:
*"enforced by the database, not only by developer discipline."*

This is the decisive fact. Allowing a deployment to proceed without the trigger puts
production into a state in which the repository's own tests would fail — while the CI
run that gates the deployment still passes, because CI runs SQLite where the trigger is
created successfully. The failure is invisible exactly where it matters.

## 5. Does MySQL/Plesk shared hosting actually prevent the trigger?

**NOT VERIFIED — inferred only.** This is the second reason to revert.

The reasoning is: `CREATE TRIGGER` requires the `TRIGGER` privilege, and additionally
`SUPER` (or `log_bin_trust_function_creators=1`) when binary logging is enabled;
managed shared-hosting MySQL commonly enables binlog for backups and withholds `SUPER`,
producing error 1419.

That is a well-known failure mode, but it is a **generic shared-hosting assumption, not
an observation of the Ethio Telecom account**. Per the migration brief's own rule,
UNKNOWN must not be treated as UNSUPPORTED any more than as SUPPORTED.

So the change weakens a real security control to solve a problem that has not been shown
to exist. If the account grants `TRIGGER` — entirely possible; Plesk databases often do,
and binlog is not universal — the change buys nothing and costs the guarantee.

**This single question is cheap to answer** (gate H1 in the verification checklist:
three SQL statements in phpMyAdmin) and should be answered before any code decision.

## 6. Is the trigger privilege issue verified or only inferred?

**Inferred.** No Ethio Telecom account was available to this audit. See
`docs/HOSTING_VERIFICATION_CHECKLIST.md` §Database.

## 7. Is there a supported alternative preserving the same guarantee?

Three candidates. **None is currently demonstrated**, so none can be relied on.

| # | Alternative | Preserves guarantee? | Status |
| --- | --- | --- | --- |
| A1 | **Least-privilege DB user** — application connects as a user holding `SELECT, INSERT` but not `UPDATE, DELETE` on `audit_log` | **Yes — equivalent.** Enforcement stays in the engine and covers every bypass path in §3 | **NOT VERIFIED.** Needs per-table `GRANT`, which needs privilege-admin rights. Plesk normally issues `ALL PRIVILEGES` on the whole database per user, and the migration's own header documents that a table-level `REVOKE` cannot subtract from a database-level `GRANT` on MySQL. Must be tested on the account. |
| A2 | **Extend enforcement to the query builder** — custom Eloquent builder + a `DB` query listener rejecting `UPDATE`/`DELETE` against `audit_log` | **No.** Still enforcement by the audited application; raw PDO and any direct DB client bypass it | Implementable, but a strictly weaker control. Defence in depth, not a replacement. |
| A3 | **Hash-chain / external shipping** — each row carries a hash of its predecessor, or rows are streamed to append-only external storage | Tamper-**evidence** rather than tamper-**prevention** | Significant new work; changes the schema and the write path. Out of scope under the current freeze, and disproportionate to the problem. |

**A1 is the only true equivalent**, and it is exactly one of the things the hosting
verification must test.

---

## Decision

Applying the brief's stated default rule:

> If the trigger is necessary to guarantee audit-log immutability and shared hosting
> cannot support it, do NOT silently weaken the control. The default should be:
> migration fails + deployment blocked.

- The trigger **is** necessary (§3, §4).
- Shared hosting **has not been shown** to be unable to support it (§5, §6).
- No compensating control is **demonstrated** (§7).

Both conditions for weakening therefore fail, and the second one fails on evidence
rather than on judgement.

### Recommended action — for approval, not yet implemented

1. **Revert** the `up()` try/catch in the mysql/mariadb branch to fatal. A deployment
   that cannot guarantee an immutable audit log should stop and say so.
2. **Keep** the `down()` guard. That one is not a security control: `DROP TRIGGER IF
   EXISTS` suppresses "no such trigger" but not "no such privilege", so an unguarded
   rollback would strand the migration in a state neither `migrate` nor
   `migrate:rollback` could leave. It makes rollback work; it protects nothing.
3. **Answer gate H1 first.** If the account grants `TRIGGER`, the entire question is
   moot and nothing needs to change.
4. **If and only if H1 fails**, test alternative A1 on the account. If A1 works, adopt
   it — the guarantee is preserved and no code changes at all.
5. **If H1 and A1 both fail**, that is a genuine architectural finding and it feeds the
   Option A / B / C decision as a **material security degradation** — which under the
   brief's own rule (§8, "if shared hosting causes material security/functionality
   degradation: recommend staying on VPS") points at Option A, not at weakening the
   control.

### If the owner nevertheless wants to proceed without the trigger

Then it should be **explicit and visible**, not a silent catch:

- a config flag (e.g. `AUDIT_LOG_REQUIRE_DB_IMMUTABILITY`, defaulting to `true`) so the
  weakened mode has to be switched on deliberately and is greppable in the deployed
  environment;
- alternative A2 implemented as partial mitigation;
- `AuditLogImmutabilityTest` updated to reflect what is actually guaranteed, so the test
  suite stops asserting a property production does not have;
- the downgrade recorded in `docs/SECURITY.md` and in the production checklist.

That is a larger change than Phase A, and it needs explicit sign-off. It is **not**
recommended.
