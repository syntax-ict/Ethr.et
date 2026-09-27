# Host evidence — one session, one file, one validator

**Every mandatory cutover gate still open is a HOST gate.** None can be closed from
the repository or from CI. This directory exists so that the one session someone
spends on the Ethio Telecom account produces evidence the repository can check,
rather than prose somebody later transcribes into a register.

```bash
# What is outstanding right now
./scripts/gates.sh evidence

# After the session
cp docs/deployment/host-evidence/HOST-EVIDENCE.template.json \
   docs/deployment/host-evidence/HOST-EVIDENCE.json
#   ... fill it ...
php scripts/hosting-verification/validate-host-evidence.php     # or: ./scripts/gates.sh evidence
```

---

## Why a file and a validator rather than a checklist

Because each of these has already happened here:

| On record | What the validator now refuses |
|---|---|
| `GET /secret.env.probe` → **404** on 2026-09-25, because the bait was absent. `RUN-SHEET.md` §4a had to say in capitals that a 404 is not a pass | a `PASS` whose result mentions 404 |
| **G0-B.6** became a requirement on 2026-09-26 and had no row anywhere until 2026-09-27 | a mandatory gate silently absent from the file |
| **G0-J** had no row in the cutover checklist until 2026-09-27 — it was reachable only through M2's *"closes … most of G0-J"* | the same, and a drift test now pins the gate list against the checklist |
| Five G0-B rows read `NOT VERIFIED` for two days after being measured `VERIFIED` | — (this is what the file fixes by being the single record) |
| CI green on MariaDB-in-a-container being read as evidence about the host | a host gate whose `method` mentions CI, a pipeline, "inferred", "assumed" or "prior" |

The validator is **not** a substitute for the measurement. It cannot tell whether
`403` was true. It can tell that a `404` was filed as a pass, that a gate was
dropped, that a composite gate stopped at its first green, and that a file claimed
`cutover_ready` it had not earned.

---

## The file

Copy the template, fill it, commit it. It is **evidence**, so per
`SHARED-HOSTING-CONTRACT.md` HARD RULE 2 it keeps its literals — a reading with its
values templated out is not a reading. Secrets are a different matter and were never
permitted: **no password, no `APP_KEY`, no `CRON_TOKEN` goes in this file.** Record
*"the token was accepted"*, never the token.

Each gate takes:

| Field | |
|---|---|
| `state` | exactly one of `PASS` · `FAIL` · `HOST ACTION REQUIRED` · `OWNER DECISION` · `BLOCKED` |
| `measured_at` | the date it was measured |
| `method` | how, on the host. Not "CI", not "assumed" |
| `result` | **verbatim.** Paste what the host returned |
| `parts` | for composite gates — M1, G0-F, M3 — every named part |

**Leave a gate open rather than guessing.** An honest `HOST ACTION REQUIRED` costs
another session; a wrong `PASS` on G0-F costs the audit log's integrity, and nobody
finds out until an audit record has already been edited.

---

## The session, in dependency order

Run it in this order because each step's result changes what the next one means.
Full procedures are linked; this is the ordering and the reason for it.

### 1 · M1 — the canary (`RUN-SHEET.md` §0)

**First, because it is the only outstanding result that blocks deployment on every
branch**, and because it is one upload and two fetches.

Replace `httpdocs/ethr-canary/` entirely, then **confirm the revision before
trusting anything**: the response must carry **7** security headers. Three means the
2026-09-18 file is still there and both readings below are non-answers.

- `secret.env.probe` → **403 = pass · 200 = stop, `APP_KEY` is web-readable · 404 = NOT RUN**
- `canary.php` headers → is the CSP present and untruncated?

### 2 · M2 — the probe, with database credentials

Closes the most per unit of effort: G0-E's extensions and limits, and it feeds
**G0-F**, **G0-H**, **G0-I** and **G0-J**.

```bash
php ~/ethr-hosting-check.php --json \
    --db-host=localhost --db-name=<DB_NAME> --db-user=<DB_USER> --db-pass=<…> \
  > ~/probe.json
```

`--json` was added on 2026-09-27 precisely so this is **attached, not retyped**.
Save it here as `probe-<date>.json` and cite the filename in `M2.result`. The
password is never included in the output — verified.

Then **delete the probe from the server.**

### 3 · G0-I — settle `DB_CONNECTION` before anything migrates

From the probe's `SELECT VERSION()`. A MariaDB banner → `mariadb`; a MySQL banner →
`mysql`. `api/.env.shared-hosting.example` ships `mariadb` annotated *"VERIFY against
the host"*, and the two connections differ in version detection and some grammar —
a mismatch surfaces on one query rather than at boot.

### 4 · G0-F — `CREATE TRIGGER`, all three readings

[`../G0-F-CREATE-TRIGGER.md`](../G0-F-CREATE-TRIGGER.md) §5. The probe's `DB4` is
the first; the other two come after `migrate`. **A grant is the input; a refused
`UPDATE` is the outcome** — do not close this from `SHOW GRANTS`.

If it is denied: **stop**. Do not soften the migration. That is **Q8** and it is the
owner's.

### 5 · G0-B.6 — `AllowOverride Options`

Only answerable once the rendered `.htaccess` is in place. Fetch `/admin` **and**
`/dashboard`: without `DirectorySlash Off`, `mod_dir` 301s before the SPA rules and
20+ routes are dead. The local harness ran under `AllowOverride All`, the permissive
case, so it proves nothing here. **No workaround exists.**

### 6 · Q6-x — the cron caller

Add `ETHR_CRON_TOKEN` and `ETHR_CRON_BASE_URL` to the GitHub `production`
environment, then **`workflow_dispatch`** and paste **both** endpoint responses.

Record as two separate facts: the manual dispatch worked, **and** the `schedule:`
trigger does not fire until `cron.yml` is on the default branch. A green dispatch is
not evidence the schedule runs.

### 7 · G0-H, G0-J, Quotas

Probe output plus panel readings. For quotas, record the deployment's footprint
against the figure, not just the figure — `BACKUP_KEEP=2` with prune-after-create
means four copies of the document store at peak against 5 GB.

### 8 · M3 — the wildcard, all four parts

DNS and the certificate **already pass in isolation and neither is the gate.** What
is absent is the vhost binding: a tenant hostname currently 303s to `/login.php`.
Record `dns`, `vhost`, `served_certificate` and `tenant_routing` separately.

### 9 · M6 — the restore rehearsal, last and blocking

[`../M6-RESTORE-REHEARSAL.md`](../M6-RESTORE-REHEARSAL.md), nine steps. Last because
it needs a deployed application and a database to rehearse against; **blocking**
because `3db9904` removed the VPS, so `ethr:restore` is the only recovery capability
this deployment will have.

Step 7 is the one a smoke test cannot replace: the `DEFINER` failure **boots fine and
fails on write.** Do one real write through the application.

---

## After the session

```bash
./scripts/gates.sh evidence
```

Then reflect the outcome into
[`../GATE-0-RESULT.md`](../GATE-0-RESULT.md) → *Gate status reconciliation* and
[`../CUTOVER-CHECKLIST.md`](../CUTOVER-CHECKLIST.md). The evidence file is the
source; those two are the readers. Keeping it that way round is what stops the
register drifting from its readings again.

**`CUTOVER READY = YES` only when every mandatory gate is `PASS` and a human has
read the evidence — not merely when the file is well-formed.** The validator says so
itself when it accepts a complete file.
