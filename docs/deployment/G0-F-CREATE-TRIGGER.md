# G0-F — the `TRIGGER` privilege: what ETHR needs and why it cannot be waived

**Status: `HOSTING ACTION REQUIRED`. NOT VERIFIED on the Ethio Telecom account.**
**Decision, 2026-09-27: request the grant. Do not weaken the migration.**

This exists because the requirement was recorded in five places at three levels of
detail, and an Ethio Telecom support conversation needs one page that states it
precisely. Nothing here is new; it is the same requirement, assembled.

---

## 1. The exact requirement

| | |
|---|---|
| **Privilege** | `TRIGGER` on the application's own schema — `GRANT TRIGGER ON <DB_NAME>.* TO '<DB_USER>'@'<HOST>'` |
| **Not required** | `SUPER`. No global privilege of any kind |
| **Already implied by** | `ALL PRIVILEGES` on a schema, which is what Plesk grants by default to a database user it creates |
| **Engine** | MySQL 5.7.9+ / MariaDB 10.2.7+ (the project's floor for other reasons too) |
| **When it is exercised** | Once, during `php artisan migrate`, at migration `2026_07_22_000001` |
| **Also needed later** | Nothing at runtime. The triggers fire on the server; the application needs no privilege to benefit from them |

**The favourable prior, stated as a prior and not as evidence.** `TRIGGER` is part of
`ALL PRIVILEGES` on a schema, it needs no `SUPER`, and Plesk's default for a
panel-created database user is `ALL`. `GATE-0-RESULT.md:1158` records that argument
and then declines to treat it as a measurement, which is the right call: it is likely
granted and nobody has looked.

---

## 2. Which migration, and exactly what it creates

**`api/database/migrations/2026_07_22_000001_restrict_audit_log_to_insert_only.php`** —
the only migration in the project that needs the privilege.

On MySQL/MariaDB it creates **two** triggers on the `audit_log` table:

```sql
CREATE TRIGGER `audit_log_no_update` BEFORE UPDATE ON `audit_log` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only (ETHR convention #5)';

CREATE TRIGGER `audit_log_no_delete` BEFORE DELETE ON `audit_log` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only (ETHR convention #5)';
```

That is the whole of it: two `BEFORE` triggers on one table, raising an error. They
create nothing, write nothing and call nothing. On SQLite the same guards are created
with `RAISE(ABORT, …)`; on PostgreSQL a per-table `REVOKE` is used instead, because
PostgreSQL privileges are genuinely per-table.

**The migration aborts the entire run if either `CREATE TRIGGER` fails.** It catches
the exception only to add a diagnosis, then rethrows. That is deliberate and is
pinned by `api/tests/Feature/Security/AuditTriggerMigrationIsFatalTest.php`.

---

## 3. Why it cannot be waived

**Because the application-level guard does not hold, and the test suite asserts the
database-level one.**

`App\Models\AuditLog` overrides `update()` and `delete()` to throw. Those are plain
instance methods, so **every mass-operation path walks straight past them**:

```php
DB::table('audit_log')->update([...]);      // no model involved
AuditLog::where(...)->delete();             // builder, not instance
DB::statement('UPDATE audit_log SET ...');  // raw
```

`api/tests/Feature/AuditLogImmutabilityTest.php` asserts immutability **by driving the
raw query builder** — precisely the path the model cannot defend. So a deployment
whose triggers silently failed to install would be in a state the test suite
contradicts, **while CI stayed green**, because CI runs SQLite where the triggers *are*
created.

Convention #5 — immutable audit log — is one of the 15 Non-Negotiable Conventions
(`docs/CLAUDE.md`). `docs/AUDIT_LOG_INTEGRITY_DECISION.md` records that an earlier
revision downgraded this failure to *"log and continue"* and that it was reverted for
this reason.

**Audit records are the evidence that HR and payroll decisions were made by whom they
appear to have been made by.** A mutable audit log is not a degraded feature; it is a
compliance claim the product would be making falsely.

---

## 4. How the host operator verifies it

### 4a. The intended route — probe `DB4`

`scripts/hosting-verification/ethr-hosting-check.php` already tests this (**manual
queue M2**). Its test is **safe and fully reversible**: scratch table → create trigger
→ `DROP TRIGGER` → `DROP TABLE`. It touches no application table and leaves nothing
behind.

It also runs **`DB6`**, which tests the compensating control in §6 below, so one run
answers both questions.

### 4b. If the probe cannot be placed — four statements by hand

Any SQL console on the account, as the application's database user:

```sql
CREATE TABLE ethr_trigger_probe (id INT PRIMARY KEY);

CREATE TRIGGER ethr_trigger_probe_guard BEFORE UPDATE ON ethr_trigger_probe
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'probe';

DROP TRIGGER ethr_trigger_probe_guard;
DROP TABLE ethr_trigger_probe;
```

- **All four succeed** → the privilege is held.
- **Statement 2 fails with `1142 TRIGGER command denied`** → it is not. Record the
  error number verbatim; it is what the provider needs.

You can also read the grant directly:

```sql
SHOW GRANTS FOR CURRENT_USER();
```

Look for `TRIGGER` in the privilege list, or `ALL PRIVILEGES` on the schema.

### 4c. After a successful `migrate`

```sql
SHOW TRIGGERS LIKE 'audit_log';
```

Both `audit_log_no_update` and `audit_log_no_delete` must be listed. Their absence
after a `migrate` that reported success would mean the migration did not run, not that
it skipped the triggers — it cannot skip them.

---

## 5. What closes G0-F

**All three, and the third is the one usually forgotten:**

1. **`DB4` = `VERIFIED`** from a probe run on the Ethio Telecom account, or the
   four-statement check in §4b succeeding, with the date.
2. **`SHOW TRIGGERS LIKE 'audit_log'` lists both triggers** after `migrate` on the
   real database.
3. **One `UPDATE` against `audit_log` is refused.** A trigger that exists and a
   trigger that fires are different claims:
   ```sql
   -- expect: ERROR 1644 (45000): audit_log is append-only (ETHR convention #5)
   UPDATE audit_log SET action = 'x' WHERE id = (SELECT MIN(id) FROM audit_log);
   ```
   If this succeeds, G0-F is **FAILED** no matter what the grant says.

Record all three in `GATE-0-RESULT.md` → *Gate status reconciliation*, replacing
`HOSTING ACTION REQUIRED` with `VERIFIED` **and the date**.

**Do not mark G0-F verified from `SHOW GRANTS` alone.** The grant is the input; the
refused `UPDATE` is the outcome.

---

## 6. If Ethio Telecom denies it

**Stop short of production cutover and record the limitation. Do not redesign the
database behaviour.**

`migrate` will abort at `2026_07_22_000001` with a diagnosis naming the missing
privilege. That is the designed outcome, and there is no flag to switch it off —
`AuditTriggerMigrationIsFatalTest` asserts that none exists, under four plausible
names, and that the migration reads no environment variable at all.

Three things are then true, and the choice between them is **the owner's — open
question Q8** (`SHARED-HOSTING-CONTRACT.md`, *"The one place this rule collides with
something"*):

| Option | What it costs |
|---|---|
| **Escalate the grant request** | Time. It is one line of SQL for the provider and needs no `SUPER`, so a refusal is a policy decision rather than a technical limit — worth naming as such when asking |
| **Accept the downgrade** | Requires building the `AUDIT_LOG_REQUIRE_DB_IMMUTABILITY` flag that `AUDIT_LOG_INTEGRITY_DECISION.md` **deliberately does not build**, and accepting that convention #5 is then enforced only by instance methods that four code paths bypass. A recorded owner decision, not a config change |
| **ETHR does not deploy on this account** | The honest other half, and the reason the decision was pre-registered as the owner's rather than an engineering one |

### 6a. The compensating control, and why it is not equivalent

Probe `DB6` tests whether a **per-table `GRANT SELECT, INSERT`** on `audit_log` can
stand in. If the account allows privilege administration it may — and it is weaker in a
specific way worth stating: it protects the table from *that user*, whereas the trigger
protects the table from *any* writer including a future user, a restore script and a
panel-driven repair. It also cannot coexist with `GRANT ALL PRIVILEGES` on the schema,
for the reason §2's migration docblock records: a table-level `REVOKE` cannot subtract
from a database-level `GRANT`, and MariaDB reports *"There is no such grant defined"*.

Treat it as a fallback to discuss, not a path to take unilaterally.

---

## 7. A separate hazard the grant does not fix — read before any restore

**The triggers carry no explicit `DEFINER`, so MySQL stores `DEFINER = CURRENT_USER`.**

Panel-driven backup and restore commonly recreates a database under a *different*
user. When the definer no longer exists, **every `INSERT` into `audit_log` fails** —
and `AuditLog::record()` is called from 206 sites inside mutation transactions. The
symptom is not "auditing stopped"; it is **every create and update in the product
returning 500 after a restore that appeared to succeed.**

Measured on MariaDB 10.4.32 against a restricted user — `docs/audit/BASELINE.md` §13b,
which records that the reality is *worse in a different way* than the original
reasoning suggested.

**Consequences for the migration plan:**

- This is a reason the **M6 restore rehearsal is a hard blocker**, not a formality.
  A restore that boots but cannot write is exactly what it is there to find.
- After any restore, run the §5 step-3 check *and* one real write through the
  application, not just a page load.
- **Operational lock-in** is the other half: `audit_log` is permanently append-only at
  engine level, so any assisted repair touching it requires dropping the trigger first.
  Whoever holds the account should know that before they need to.

---

## Related

- `api/database/migrations/2026_07_22_000001_restrict_audit_log_to_insert_only.php`
- [`../AUDIT_LOG_INTEGRITY_DECISION.md`](../AUDIT_LOG_INTEGRITY_DECISION.md) — why failure is fatal
- [`GATE-0-RESULT.md`](GATE-0-RESULT.md) → *Gate status reconciliation*, row **G0-F**
- [`ETHIO-TELECOM-SUPPORT-REQUEST.md`](ETHIO-TELECOM-SUPPORT-REQUEST.md) — **ask 3** is this grant
- [`SHARED-HOSTING-CONTRACT.md`](SHARED-HOSTING-CONTRACT.md) — the rule this collides with, and **Q8**
- [`../audit/BASELINE.md`](../audit/BASELINE.md) §13b — the `DEFINER` measurement
- `api/tests/Feature/Security/AuditTriggerMigrationIsFatalTest.php` · `api/tests/Feature/AuditLogImmutabilityTest.php`
