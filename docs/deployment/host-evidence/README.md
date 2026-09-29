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

**That command assumes a shell, and this account has none — on Bronze, run the probe
over the web and send the credentials as a POST body (added 2026-09-29).** SSH is
Forbidden (Route A), there is no Scheduled Tasks section (Route B — G0-D **FAILED**), and
Route D went with the Plesk Git repository; [`GATE-0-RESULT.md`](../GATE-0-RESULT.md)
records **Route C, web-served, as the only live probe route.** Until 2026-09-29 Route C
had to run *without* credentials, because web mode read them from the query string, and a
query string lands in an access log this account cannot purge. That left **G0-F and G0-I
with no route at all.** The probe now reads a POST body first, so they have one:

1. In the copy you upload, set `ETHR_PROBE_WEB_TOKEN` to a random value. Put it in
   `httpdocs/` under a random filename.
2. From your own machine, pinned to the host as M1 was. Use Git Bash — in Windows
   PowerShell 5.1 `curl` is an alias for `Invoke-WebRequest` and these flags will not parse:

   ```bash
   curl -sS --resolve www.ethr.et:443:213.55.96.154 \
       https://www.ethr.et/<random-name>.php \
       --data-urlencode 'token=<that-value>' --data-urlencode 'json=1' \
       --data-urlencode 'db-host=localhost' --data-urlencode 'db-name=<DB_NAME>' \
       --data-urlencode 'db-user=<DB_USER>' --data-urlencode 'db-pass=<…>' \
       -o probe-<date>.json
   ```

   The password lands in your own shell history, which you can clear. The host's access
   log is the one you cannot.
3. **Delete the file from `httpdocs/` in the same sitting.**

Four things about that run, each a way to get a wrong answer:

- **Connect as the application's own database user** — the one `DB_USERNAME` will name.
  `DB3` is `SHOW GRANTS`, `DB4` creates a trigger, and `DB6` grants to `CURRENT_USER()`:
  all three measure *the connecting user*, not the server. A throwaway user's `DB4` says
  nothing about whether `migrate` can create `audit_log`'s triggers, and G0-F is the gate
  where a wrong `PASS` costs the audit log.
- **`db-pass` in the query string is refused with 400.** The check runs after the token
  check, so an unauthenticated request still learns nothing. If you do see that 400, the
  password is already in the access log. Change it in Plesk before it goes into `.env`.
- **A 403 that is not exactly `403 Forbidden` (14 bytes) did not come from the probe.**
  Something in front of PHP refused the POST. Record what it returned.
- **The web run reads the real web limits**, which is a second reason to prefer it. Under
  CLI, `memory_limit/-1` and `max_execution_time/0` read as unlimited (a false PASS), and
  the upload pair reads the php.ini defaults (a false FAIL). The probe's `L!` row flags
  this, and it is emitted only under CLI. The web run also records `W0 document root` as
  an absolute path. That corroborates the `httpdocs/` measured over HTTP on 2026-09-24 and
  gives the literal path behind the `'/../ethr/api/…'` prefix, which was derived rather
  than read. Capture it.

**Verified 2026-09-29 under a real web SAPI** (`php -S`), with the server's request log
captured:

| Request | Result |
|---|---|
| Shipped copy | 403, 14 bytes |
| Wrong token, by POST | 403, 14 bytes |
| `db-pass` in the query string, no token | 403, 14 bytes |
| `db-pass` in the query string, with a token | 400 |
| Everything by POST, with `json=1` | 200. Parseable JSON, database section attempted, sentinel password **absent** from the response |

The request log carried the sentinel on exactly the two GET lines. The POST line read
`POST /<name>.php` and nothing more.

**The credentials reach a real server.** A later run the same day, with the password fed
to curl on stdin (`--data-urlencode db-pass@-`), was answered by a local MySQL with
`[1045] Access denied for user '<user>'@'localhost' (using password: YES)`. The POSTed
user and a non-empty password both arrived. **Not exercised locally:** a login that
*succeeds*, and therefore `DB1`–`DB10` over this route. Those rows are the database code
the CLI route already ran; only the input path is new.

`--json` was added on 2026-09-27 precisely so this is **attached, not retyped**.
Save it here as `probe-<date>.json` and cite the filename in `M2.result`. The
password is never included in the output — **re-verified 2026-09-28** by running the
probe with a sentinel password and grepping both stdout and stderr: zero
occurrences in either.

**Count the `Database` rows before believing the run.** Verified 2026-09-28: with
wrong or unreachable credentials the probe does **not** fail — it records a single
`DB connection · UNSUPPORTED` row carrying the PDO error, skips `DB1`–`DB10`, and
finishes normally with a full report. So a run that measured **nothing** about the
database looks almost exactly like one that measured everything, and **G0-F, G0-I
and G0-H would all still be unmeasured while `probe.json` sits there looking
complete.** One `Database` row means the credentials were wrong — re-run before
leaving the session. Same failure shape as `vendor/bin/pest` collecting 21 of 132
classes and exiting 0 green.

Two smaller notes from the same check:

- **Do not redirect stderr into the file.** `> ~/probe.json` is correct;
  `> ~/probe.json 2>&1` is not — the probe shells out, and any warning it prints
  lands ahead of the `{` and makes the file unparseable.
- **Exit 1 is meaningful, not noise.** In `--json` mode the probe exits 1 when any
  check is `UNSUPPORTED` and 0 otherwise, so the status says something about the
  host rather than about whether the probe ran. *CLI only:* over the web the response
  is 200 either way, and the rows are the only signal.

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

**A scheduled run that says DORMANT is also not a failure** — it means neither secret
is set, which is the expected state before deployment. A run that says
*PARTIALLY CONFIGURED* is a real fault: one secret is missing or misnamed.

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
