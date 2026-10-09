# Contributing to ETHR

Most of what follows is not style advice. It is the set of traps this
environment actually sprang on people, written down so the next person does not
rediscover them.

The conventions themselves live in [`docs/CLAUDE.md`](docs/CLAUDE.md) — the 15
Non-Negotiable Conventions, the design system, the API rules. Read that first;
this file is about working the repository.

## The layout is inverted from what it looks like

- **`api/`** is the entire Laravel backend, not a thin API layer over something
  else.
- **`src/`** is the Next.js *project root*. The actual frontend source is nested
  one level down, at **`src/src/`**.

## Running it locally

Local development is the production shape — XAMPP's Apache, PHP and MariaDB,
no Docker (the Docker stack was removed on 2026-09-30). The full procedure is in
[`docs/LOCAL_SETUP.md`](docs/LOCAL_SETUP.md); the short form:

```bash
scripts/local-production/up.sh        # the Bronze deployment on :8081
scripts/local-production/verify.sh    # must end "0 failed" (50 checks on 2026-10-09)
```

For hot reload, run `php artisan serve` in `api/` and `npm run dev` in `src/`.
Queued jobs run only when drained — `php artisan queue:work --stop-when-empty`
locally, the GitHub Actions cron caller in production. Do not switch to
`QUEUE_CONNECTION=sync` to avoid that: it hides every bug that only a real
worker shows.

**Node comes from `.nvmrc`** — currently 24, which `nvm use` / `fnm use` picks
up from the repository root and which CI reads via `node-version-file`. It is
not a preference. Two frontend test files fail on Node 20 and one of them on 22
as well, for reasons that have nothing to do with the application code; that
pin is what kept `Frontend (i18n, Prettier, ESLint, tsc, Vitest)` red on every
CI run until 2026-09-16. `docs/audit/BASELINE.md` §12d has the measurements.

The old Windows launchers `RUN_ALL.ps1`, `START_BACKEND.ps1` and
`START_FRONTEND.ps1` were removed on 2026-09-29: `RUN_ALL.ps1` printed
"SQLite" against a MariaDB stack, and none started the worker or Reverb.

### `composer install` needs a GitHub token

Every one of the 163 `dist` URLs in `api/composer.lock` points at
`api.github.com`, and `api/composer.json` declares no custom `repositories` —
so a cold install (empty Composer cache) makes well over a hundred API calls.
Unauthenticated, GitHub allows **60 per hour per IP**. The install gets partway,
starts receiving 403s, and Composer stops to prompt for a token — which under
`--no-interaction`, in a hook, or in a script is simply a failure.

Store a token once, globally:

```bash
composer config --global --auth github-oauth.github.com <your-token>
```

That writes to `COMPOSER_HOME/auth.json`, outside the repository. A per-project
`api/auth.json` works too and is already gitignored, but the global file keeps
the credential out of the working tree entirely. **Never commit either one.**

**The scope required is none.** Every dependency is a public package from
Packagist, and this repository is public — the token is doing nothing but
lifting a rate limit. A classic PAT with *no* boxes checked, or a fine-grained
token with read-only access to public repositories, is sufficient and grants
nothing else. A `repo`-scoped token would hand every dependency's install
script a credential to your private repositories for no benefit.

Verify it without printing it:

```bash
composer diagnose | grep 'github.com oauth'
# Checking github.com oauth access: OK  expires on <date>
```

That line reports the expiry as well. An expired token fails exactly the way a
missing one does, so check here first when a previously working `composer
install` starts rate-limiting.

CI does not need one: the `Install PHP dependencies` step in
`.github/workflows/gates.yml` passes no token and the runs are green. This is a
workstation prerequisite, not a pipeline secret.

### Create `api/.env` *before* the first `composer install`

On a machine with no `api/.env` — a fresh clone — `composer install` fails
*after* downloading everything, in the `package:discover` post-autoload-dump
script:

```
Failed to create broadcaster for connection "reverb" with error:
Pusher\Pusher::__construct(): Argument #1 ($auth_key) must be of type string, null given
```

**Closed 2026-09-25 — kept because the error message is still searchable.**
`config/broadcasting.php` *used to* default to `reverb` when `BROADCAST_CONNECTION`
was unset, and `routes/channels.php` calls `Broadcast::channel()` at load time. With
no `.env` at all, `REVERB_APP_KEY` is null too, so Pusher's constructor rejected
it. This was the same defect that blocked CI for fifty runs, where the workflows
worked around it with a `BROADCAST_CONNECTION: "null"` override.

The default is now `null`, the three workflow overrides are removed, and
`BroadcastConnectionConfigTest` pins the unset case. If you see the message above
on a current checkout, the default has regressed — that test should be red.

**Locally the fix is ordering, not a value.** `.env.example` ships
`REVERB_APP_KEY=ethr-reverb-key`, so once the file exists the broadcaster
constructs and `package:discover` succeeds with `BROADCAST_CONNECTION` left at
`reverb` — measured 2026-09-24, `php artisan package:discover` in 5–7 s under
either setting. Copy the file first and the failure never occurs:

```bash
cd api
cp .env.example .env
php artisan key:generate     # encrypted casts on Employee.tin refuse to boot without APP_KEY
composer install
```

Without the `key:generate` the suite dies with `MissingAppKeyException` rather
than failing a test, which reads like a broken suite instead of an unconfigured
one.

You may still want `BROADCAST_CONNECTION=null` — it is what the shared-hosting
deployment uses, and it stops a broadcast throwing when no Reverb server is
running. It is just not what makes `composer install` work.

> **`composer install` and `composer dump-autoload` are slow here, not hung.**
> `composer.json` sets `"optimize-autoloader": 1`, so every dump builds a full
> classmap over the whole `vendor/` tree — 15,025 files, 183 MB. Measured
> 2026-09-24 on a Windows temp path: **589 s** for `composer dump-autoload`
> alone, exit 0. `composer install` runs the same dump as its final phase, so
> the download finishes quickly and the classmap scan is what you are watching.
> Give it ten minutes before concluding anything is stuck.

## Quality gates

One entry point, for people and for CI alike:

```bash
./scripts/gates.sh            # everything
./scripts/gates.sh backend    # Pint, PHPStan, Pest
./scripts/gates.sh frontend   # i18n, Prettier, ESLint, tsc, Vitest
```

More scopes exist:

```bash
./scripts/gates.sh quick      # everything except the test suites — for the hook
./scripts/gates.sh docs       # markdown links resolve
./scripts/gates.sh security   # composer audit + npm audit (production deps)
./scripts/gates.sh export     # the Bronze shared-hosting production build
```

**`export` is the one to know about if you touch the frontend or `.htaccess`.** It
runs `ETHR_TARGET=shared-hosting next build` and then asserts the artifact is really
a static export — the four `__id__` shells, `404.html`, the `_next` bundle, and no
`server.js`. It is part of `./scripts/gates.sh`, unlike `security` and
`performance`, because it goes red only when you break the production artifact rather
than when a third party publishes an advisory.

It exists because until 2026-09-27 **no gate ran `next build` at all**, for either
target, so the artifact the deployment target actually serves had been verified once
by hand on one machine. Its first run found that six real routes —
`/employees/new`, `/employees/import`, `/payroll/{cost-sharing,loans,payslips}` and
`/devices/dashboard` — were being served the entity-detail shell. See
`docs/audit/BASELINE.md` §22.

Use `npm run build:shared-hosting` from `src/` for the same thing directly. Do **not**
write `ETHR_TARGET=shared-hosting npm run build` in a Windows shell: neither cmd.exe
nor PowerShell accepts that prefix, so you silently get a `standalone` build.

### Enable the pre-push hook

```bash
git config core.hooksPath .githooks
```

Once per clone. It runs `gates.sh quick` and refuses the push if anything fails,
and it checks the diff for `.env` files and `APP_KEY` literals — both mistakes
this repository has actually made.

It runs `quick` rather than the full sweep on purpose: the backend suite takes
about ten minutes, and a hook that costs ten minutes is a hook people learn to
pass `--no-verify` to. `ETHR_PREPUSH_SCOPE=all git push` if you want everything.

### On CI

`.github/workflows/` calls `gates.sh` rather than restating the gate list, so
there is one definition of "does this pass".

*(This section used to end "**It has never run** — nothing has been pushed to the
remote yet". That stopped being true long ago and is corrected rather than
deleted, because the reason it was written is worth keeping: for the first 50
pushes the workflows really did fail before any gate executed, and
`docs/audit/BASELINE.md` records the five structural causes. Run #66 was the
first fully green one. As of run #431 the seven jobs pass on every push to
`main`.)*

The nine jobs, named as GitHub reports them — these strings are what a required
status check must match exactly. *(It was seven until 2026-10-09. The last two
were added to `gates.yml` later and run unconditionally on every pull request,
so they are now required too.)*

```
Documentation integrity
API contract (OpenAPI drift)
Backup restore rehearsal on MariaDB
Frontend (i18n, Prettier, ESLint, tsc, Vitest)
Backend (Pint, PHPStan, Pest)
Backend coverage (PCOV)
Backend suite on MySQL
Static export (Bronze shared hosting)
Deployment rules (.htaccess render + validate)
```

`security.yml` is a separate workflow and is **not** one of them. It triggers on
a schedule and on pull requests touching `composer.json`, `composer.lock`,
`package.json` or `package-lock.json` — so on most pull requests it never starts.

### Branch protection on `main`

**Applied 2026-09-24, found missing 2026-10-09, re-applied the same day.** Recorded
here so it is reviewable rather than living only in the repository settings UI.
Nothing in this repository *applies* it. It is set under *Settings → Rules →
Rulesets* (ruleset `main`, id 24799448).

**Missing on 2026-10-09.** `GET /repos/syntax-ict/Ethr.et/rulesets` returned `[]`,
and `GET …/rules/branches/main` returned `[]`, which is the effective-rules view and
covers rulesets. Classic protection was 404 as well. Nobody knows when or how it
went. The symptom showed up earlier: GitHub refused auto-merge on PR #176 because
there were "no required checks". So, again, a control that was documented and
believed in was not in the path. `docs/audit/REPOSITORY-GOVERNANCE-2026-10-09.md`
has the record.

| Setting | Value |
|---|---|
| Ruleset target | `refs/heads/main` |
| Enforcement | **Active** (not *Evaluate*) |
| Require a pull request before merging | on |
| — Required approvals | **0** |
| Require status checks to pass | on, with the nine jobs listed above |
| — Require branches to be up to date first | on |
| Block force pushes | on |
| Restrict deletions | on — **targeting `main` only** |
| Do not allow bypassing the above settings | off |

Four of those are counter-intuitive enough to state the reason:

- **Required approvals is 0, deliberately.** GitHub does not let anyone approve
  their own pull request. On a repository with a single maintainer, requiring one
  approval makes every pull request permanently unmergeable by the only person
  who can merge it. Zero still forces the pull-request flow and still requires the
  checks; it only declines to demand a second human who does not exist. Raise it
  the day there is a second reviewer.
- **Do not require `security.yml`.** It does not run on most pull requests (see
  above), and a required check that never starts blocks the pull request forever.
  This is the same reason `CLAUDE.md` gives for keeping `security` out of the full
  sweep: a gate that is permanently red, or permanently pending, stops being read.
- **Scope *Restrict deletions* to `main`.** A ruleset targeting all branches with
  that option on also blocks ordinary branch cleanup, which is a slow thing to
  diagnose — the failure surfaces as an HTTP 403 on the ref update while ordinary
  pushes keep working, and `git push` then prints a misleading
  `Everything up-to-date` before exiting 1.
- **Bypass stays off.** With it on, administrators are bound too, which on a
  solo repository removes the only escape hatch when a check is stuck. Off, the
  rules apply to normal work and can still be overridden deliberately.

**Why it was worth doing:** before it was applied the gates were advisory. Seven
green jobs blocked nothing, `main` accepted a direct push, and a force-push over
it was permitted. The greens were real; the enforcement was not — the same shape
as the gap `BASELINE.md` documents at length, a control that is documented and
believed in but not actually in the path of the thing it is meant to stop. It is
now in that path.

#### `production` has its own ruleset, and it is deliberately smaller

Ruleset `production` (id 24799471, applied 2026-10-09, active) blocks **force-push
and deletion** of `refs/heads/production`. That is all it does. It does not require
a pull request, because nothing ever opens one against `production`: `release.yml`
pushes each release commit straight to it. Those commits are always fast-forwards
(see *Branches and releases* below), so blocking non-fast-forward updates costs the
workflow nothing. It still stops anyone from rewriting the deployment history Plesk
Git pulls from.

Ruleset `release-tags` (id 24802340, applied 2026-10-09, active) covers `refs/tags/v*` and blocks
**updating and deleting** a tag. Creating one is allowed, because `release.yml`
creates one per release. Once a tag has named a deployment, it always names the same
commit.

#### Verifying it — and the flag that will lie to you

**Read the effective rules first.** `GET /repos/{owner}/{repo}/rules/branches/{branch}`
returns every rule that applies to a branch, from rulesets of any origin. When
nothing applies it returns `[]`, and that is how the 2026-10-09 absence was found.
Measured after re-applying:

```bash
gh api repos/syntax-ict/Ethr.et/rules/branches/main --jq '.[].type'
# deletion  non_fast_forward  pull_request  required_status_checks
gh api repos/syntax-ict/Ethr.et/rules/branches/production --jq '.[].type'
# deletion  non_fast_forward
```

Use this read to find out whether a rule exists, not whether it blocks anything.
Check each ruleset's `enforcement` as well (`gh api repos/{owner}/{repo}/rulesets`
must say `active`). The push probe below is still the proof.

**Do not check `protected` on the branches API.** For a **ruleset**, that field
reads **`false`** on a branch that is fully protected. Measured 2026-09-24:
`GET /repos/{owner}/{repo}/branches` reported `"protected": false` for `main`
while a direct push to it was being rejected. The field reflects *classic* branch
protection rules; rulesets are a separate system and are not surfaced through it.
Reading `false` there and concluding "unprotected" is wrong, and it was concluded
here before the push was tried.

The authoritative check is to attempt the thing protection forbids:

```bash
git commit --allow-empty -m "protection probe" && git push origin main
```

```
! [remote rejected] main -> main (protected branch hook declined)
```

That rejection is the verification. If the push is *accepted* instead, the empty
commit lands on `main` and the protection is not enforcing — check the ruleset's
**Enforcement status** first, since *Evaluate* logs what would have been blocked
without blocking anything and is indistinguishable from no protection in every
other way.

Two notes for anyone automating this. A CI or agent environment may refuse the
probe itself: pushing straight to `main` is a pull-request bypass, and a sandbox
can decline it before GitHub ever sees it, which leaves the question unanswerable
from inside that sandbox. And protection does **not** cover branch deletion of
*other* branches — if `git push origin --delete <branch>` returns HTTP 403 while
ordinary pushes succeed, that is a credential or egress limitation, not this
ruleset; the two were confusable enough here to be worth separating.

### Check the collected test count, not only the colour

This is the trap worth knowing. PHP's recursive directory scan returned
incomplete results over a Docker Desktop Windows bind mount. Measured
2026-08-21: `pest` collected 21 of 132 test classes, ran them, and **exited 0
with a green summary** — so the suite reported success while proving almost
nothing, and did so for weeks. Larastan failed the same way (990 phantom errors
on the mount, 0 off it).

The Docker stack, and the `pest-isolated.sh` / `phpstan-isolated.sh` scripts
that worked around it, were removed on 2026-09-30. `scripts/gates.sh` keeps the
collection guard, which fails loudly on an undercount: any lossy filesystem
produces the same silent green, so keep the checkout on a plain local disk.

**A native PHP run on a local disk does not have this problem.** If you have PHP
8.2+ with `pdo_sqlite`, `mbstring`, `gd`, `dom` and `fileinfo`, the full suite
runs directly and collects every class:

```bash
php -d memory_limit=-1 vendor/bin/pest
```

`memory_limit=-1` is required, not cosmetic — the DomPDF payslip tests exhaust
the default limit and take the whole suite down with them.

Either way, check the collection count against `find api/tests -name '*Test.php'`
before believing a green run.

## Tests

- Backend: Pest, in `api/tests/{Unit,Feature}`. `phpunit.xml` declares only
  those two as testsuites; `tests/Performance` is deliberately outside them
  because it holds benchmarks, not gates.
- Frontend: Vitest + MSW in `src/src/test/`, Playwright in `src/e2e/`.
- Frontend coverage, run deliberately rather than as a gate — it roughly doubles
  the frontend gate's wall time:

  ```bash
  cd src && npx vitest run --coverage
  ```

  Baseline 2026-09-16: **32.34% statements, and 170 of 321 files at 0%** — better
  than half the frontend is never imported by a test. See `docs/audit/BASELINE.md`
  §12f. Backend coverage needs PCOV or Xdebug installed and is not currently
  possible; `phpdbg` looks like a way round it and is not (§12e).
- Helpers `createTenant()`, `createUser()`, `actingAsUser()` and
  `selfieDataUrl()` live in `api/tests/Pest.php`.

**A regression test must be shown to fail without the fix.** A test that passes
both before and after proves nothing. Revert the change, watch it go red, put
the change back. `tests/Feature/Security/TenantImportIsolationTest.php` was
written that way and says so.

### Running the suite against MySQL

The default suite runs on SQLite `:memory:`. Production runs on MariaDB, and
the two are not interchangeable — every defect below was invisible on SQLite
and real on MySQL:

- `DATE` columns truncate the time component on insert; SQLite keeps it, so a
  `due_date` carrying a time made dunning fire a day late on one driver and on
  time on the other.
- `PDO::quote()` escapes newlines on MySQL and not on SQLite, so a backup dump
  could tear on the driver every backup test happened to use.
- `TRUNCATE` is DDL on MySQL and implicitly commits; SQLite has no TRUNCATE at
  all and Laravel compiles it to `DELETE FROM`.

So run it on MySQL before a release, and after touching anything that writes
dates, raw SQL, or schema:

```bash
mysql -e "CREATE DATABASE ethr_suite_mysql CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
./scripts/gates.sh mysql
```

`phpunit.mysql.xml` differs from `phpunit.xml` in seven `<env>` lines and
nothing else. Only `DB_CONNECTION` is forced; export `DB_HOST`, `DB_PORT`,
`DB_DATABASE`, `DB_USERNAME` or `DB_PASSWORD` to point it at your own server.

The `mysql` scope is **outside the full sweep**, like `security` and
`performance`, so `./scripts/gates.sh` still runs on a fresh clone with no
services. **It fails rather than skips when no server is reachable** — a gate
that skips reports success, and this repository has twice been caught by checks
that were green because they had not run.

CI runs it on every push (`backend-mysql` in `.github/workflows/gates.yml`),
against a `mariadb:10.11` service container and as a **non-root user**, which
also exercises whether the `audit_log` trigger migration works without `SUPER`.

**The trap, already paid for twice:** on MySQL, DDL implicitly commits —
`TRUNCATE`, `CREATE`, `ALTER`, `DROP`, `LOCK TABLES`. `RefreshDatabase` wraps
each test in a transaction, and DDL ends that transaction for good. There is no
rollback afterwards. Two different shapes of this bit:

- **In a `beforeEach`.** `PermissionSeeder` called `truncate()`. Laravel finds
  the transaction gone at teardown, sets `RefreshDatabaseState::$migrated =
  false`, and runs a full `migrate:fresh` before the next test — ~20s each,
  putting the suite at roughly nine hours. See
  `database/seeders/PermissionSeeder.php:32`.
- **In a test body.** `BackupRestoreRehearsalTest` drops every table on purpose.
  On SQLite the rollback undoes it; on MySQL the database simply stays destroyed
  and every later test fails on a missing table — including tests in other files
  that have nothing to do with it. That file now skips on non-SQLite drivers and
  `php artisan ethr:backup:rehearse` covers MySQL instead.

So: a test that must issue DDL needs a driver guard, not a hopeful rollback.

## Tenant isolation

The single thing most worth being careful about. `BelongsToTenant` adds a
fail-closed global scope: with no tenant context it applies `whereRaw('0 = 1')`,
so the absence of scope yields *no* rows rather than *all* rows.

There are ~147 `withoutGlobalScope` / `withoutGlobalScopes` call sites. Some are
legitimate — platform-admin surfaces, pre-authentication lookups, global
reference data like national holidays, and queued jobs, which run with no HTTP
tenant context and must re-scope by hand.

**Every one of them must re-apply a tenant predicate, directly or by deriving
from a key that is itself tenant-owned.** Nothing enforces this automatically.
One site was measurably wrong (`EmployeeImporter`, fixed with a regression
test); assume the next one can be too.

If you write raw SQL — `whereRaw`, `selectRaw`, `DB::raw` — it carries no scope
at all. Say `tenant_id` yourself.

## Commits

Conventional Commits, one logical change each:

```
fix(payroll): apply income tax Proclamation 1395/2025
docs(audit): establish Phase 0 forensic baseline
```

Work on a short-lived branch and merge, as the existing history does. Explain
*why* in the body — the comments and commit messages in this repository carry
an unusual amount of hard-won detail about measured failures, and that is
deliberate. Keep it up.

### Branches and releases

There are exactly two permanent branches, and they are different kinds of thing.

| Branch | What it is | How it moves |
|---|---|---|
| `main` | The source. Every change is integrated here | Pull requests only. The ruleset requires the nine checks and blocks direct pushes, force-pushes and deletion |
| `production` | **Generated.** The built release tree Plesk Git deploys: `vendor/`, the static export, the rendered `.htaccess` | Only by [`release.yml`](.github/workflows/release.yml). Each push is one `release: <main sha>` commit whose parent is the previous release |

**`production` shares no history with `main`. Never merge, rebase, cherry-pick or
open a pull request between them.** `git merge-base main production` returns nothing,
and that is by design. `production`'s history is the deployment history, and its
commits contain build output that does not belong in the source. A
"promote `main` to `production`" PR would mix the two and break the Plesk pull.

**The release flow, end to end:**

There are three places, and a change moves through them in one direction:

| Place | What happens there |
|---|---|
| **Local** (one checkout of this repository) | Development, `./scripts/gates.sh`, migrations and seeders against your own MariaDB, and the **local production rehearsal** (`scripts/local-production/up.sh` → `http://localhost:8081`). The rehearsal builds and deploys the same release artifact CI does |
| **GitHub** (`syntax-ict/Ethr.et`) | Review on `main`, then the built `production` branch, with one tag per release |
| **Production** (Plesk) | Plesk Git **pulls** `production`. Artisan runs from the Laravel Toolkit or the maintenance endpoint, then the live site is verified |

1. **Local.** Branch from `main` with a short-lived, prefixed name: `feat/`, `fix/`,
   `chore/`, `docs/`, `test/`. Dependabot creates its own `dependabot/…` branches.
   Run `./scripts/gates.sh quick` (the pre-push hook does). For anything that
   touches deployment, migrations or seeders, also run
   `scripts/local-production/up.sh` and `verify.sh`, which build the real release
   and serve it through Apache.
2. **Pull request into `main`.** The nine required checks must pass.
3. **Merge.** The repository squash-merges, so a PR becomes one `… (#NNN)` commit,
   and the head branch is deleted automatically (*Settings → General*, on since
   2026-10-09).
4. **Publish (automatic).** **Quality gates** runs on the push to `main`. Only if it
   passes does **Plesk release** do three things. It builds the tree, which carries
   `api/public/release.json` naming its source commit. It rehearses two deploys of
   it. Then it pushes `release: <first 12 of the main sha>` to `production` and tags
   that commit **`vYYYY.MM.DD.N`**, the Nth release that UTC day. Tags are annotated
   and protected by the `release-tags` ruleset: they cannot be moved or deleted. This
   step is not a deployment.
5. **Pull (manual).** Press **Deploy** in Plesk Git, then do the release steps in
   [`docs/deployment/PLESK-GO-LIVE.md`](docs/deployment/PLESK-GO-LIVE.md). Nothing
   reaches the host until someone does that.
6. **Verify.** `scripts/release-status.sh https://<APP_DOMAIN>` should end with
   *"Local, GitHub, production and the live site are in step."*

**Hotfixes use the same flow,** because there is nowhere else for a fix to live.
Open a `fix/` PR into `main`. Once it merges, step 4 publishes it, then press
Deploy. Never commit to `production` by hand: the next release is built from
`main` and would silently drop the change. To roll back, open a revert PR on
`main`. It goes through the same gates and comes out as a new release.

**Is everything in sync?** One read-only command answers it for all four places:

```bash
scripts/release-status.sh                                 # local, main, production
scripts/release-status.sh https://<APP_DOMAIN>            # ...and the live site
ETHR_BASE_URL=http://localhost:8081 scripts/release-status.sh   # ...and the local rehearsal
```

Each `!` line names the next action:
- local `main` behind: `git pull --ff-only`;
- `production` built from an older `main`: the gates or the release are still
  running, or one failed, so read the Actions tab before assuming which;
- the live site running an older release: press Deploy.

It exits 0 when everything agrees and 1 when something is pending.

The live answer comes from `/release.json`, which every release carries from
2026-10-09 on. A site deployed before then reports nothing, and the script says
*unknown* rather than guessing. *Published* (the `production` branch) and *deployed*
(what `release.json` on the live site says) are different questions, and only the
second one tells you what users are running.

**Cleaning up a local branch after its PR merges:** `git fetch --prune` drops the
remote-tracking ref, and `git branch -vv` marks the local branch `[gone]`. Squash
merges keep `git branch -d` from seeing the branch as merged. Check that its tip
equals the PR's head commit (`gh pr view <n> --json headRefOid`) before you delete
it with `-D`.
`docs/audit/REPOSITORY-GOVERNANCE-2026-10-09.md` records the first time this was done.

## Security

Do not open a public issue for a vulnerability. See [`SECURITY.md`](SECURITY.md).

Never commit a real `.env`, an `APP_KEY`, or a credential. `START_BACKEND.ps1`
used to hardcode an `APP_KEY` and it took a security pass to notice.
