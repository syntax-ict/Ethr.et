# ETHR

Multi-tenant, offline-first HCM SaaS for Ethiopian organizations.

**The conventions live in [`docs/CLAUDE.md`](docs/CLAUDE.md).** Read it before writing code — the 15 Non-Negotiable Conventions, the design system, the API and testing rules. This file exists because agent tooling loads `CLAUDE.md` from the repository root, and nothing here pointed there.

Also worth reading: [`CONTRIBUTING.md`](CONTRIBUTING.md) for how to run and test the project, and [`docs/audit/BASELINE.md`](docs/audit/BASELINE.md) for what is actually measured versus merely documented.

---

## Four things that will otherwise cost you a day

### 1. The directory names are inverted

- **`api/`** is the entire Laravel 12 backend — not a thin API layer over something else.
- **`src/`** is the Next.js *project root*. The real frontend source is nested one level down at **`src/src/`**.

### 2. A green test run can be a lie

Over a Docker Desktop Windows bind mount, PHP's recursive directory scan returns incomplete results. Measured 2026-08-21: `vendor/bin/pest` collected **21 of 132** test classes, ran them, and **exited 0 with a green summary**. It did that for weeks. Larastan has the same problem in the other direction — ~990 phantom errors from migrations it could not see.

Use `./scripts/gates.sh`, which routes around it and fails loudly on an undercount. If you run the suite directly, check the collection count against `find api/tests -name '*Test.php'` before believing the result.

**A native PHP run on a local disk does not have this problem** — measured **2026-08-21: 140/140 classes collected, 1673 tests passing**. The trap is the bind mount, not PHP.

**Do not compare a collection count against that 140.** It is a dated measurement, and the suite has grown: `find api/tests -name '*Test.php'` returns **178** as of 2026-09-25. The instruction above is to compare collection against *`find`'s current output*, not against a number written down a month earlier — a reader who compares against 140 today sees a 38-class surplus and concludes something is wrong when nothing is. The ratio is what matters, not the figure.

### 3. Four processes, not two

`docker compose up -d`. Without the queue worker no job ever runs; without Reverb a broadcast throws, so a *successful* write can still return 500 under `QUEUE_CONNECTION=sync`.

The `RUN_ALL.ps1` / `START_BACKEND.ps1` / `START_FRONTEND.ps1` launchers predate the Docker setup. `RUN_ALL.ps1` prints "SQLite" while the documented stack is MariaDB, and starts neither the worker nor Reverb.

### 4. Tenant isolation is fail-closed, and bypassed in 158 places

`BelongsToTenant` adds a global scope that applies `whereRaw('0 = 1')` when no tenant is resolved — absence of context yields *no* rows, not *all* rows. That design is why this product is safe by default.

There are **158** `withoutGlobalScope` / `withoutGlobalScopes` call sites across 56 files, counted with PHP's tokeniser against `tests/Feature/Security/tenant-scope-bypasses.php` and matching it exactly. It was 157/55 from 2026-09-23 until 2026-09-25, when `Listeners/NotifyDeviceSyncFailed.php` added one: a queued admin lookup that re-applies `tenant_id` derived from the device, mirroring `NotifyDeviceOffline` beside it. It was 156/54 from 2026-09-22 until §11h added one: a deliberately cross-tenant device-serial uniqueness check, which states no `tenant_id` because uniqueness there is global by design.

**This figure read 161/55 for four days, and overcounted by exactly five.** Counting was `preg_match_all` over raw file text, which cannot tell a call from the same words in a comment — and five matches were comments, every one in a file whose docblock explains why its bypass is safe. `Http/Middleware/EnsurePlatformContext.php` has left the inventory entirely: its only match was always a docblock.

**The five that entered between 2026-09-16 and 2026-09-18 were still real.** The inflation is a *constant* +5, present since before the first pin, not something that arrived with those five. Measured against the pin commit (`21746a9`) with the tokeniser: **151 real calls + 5 comments = the 156 first recorded**; today it is **156 real + 5 comments = 161**. So the table below stands unchanged, and the count genuinely rose by five real bypasses.

Worth knowing because it is a trap: the figure first pinned (156) equalled the real figure for a day (156 on 2026-09-22, now 157). They were different quantities that happened to coincide, and reading the match as "nothing really changed" got both wrong. Most are legitimate: platform-admin surfaces, pre-authentication lookups, global reference data, and queued jobs that run with no HTTP tenant context. **Every one must re-apply a tenant predicate**, directly or by deriving from a key that is itself tenant-owned. One was measurably wrong and shipped — see `tests/Feature/Security/TenantImportIsolationTest.php` — so assume the next one can be too.

`TenantScopeBypassInventoryTest` now pins that inventory per file and fails when a count moves, so **a new bypass cannot enter unnoticed**. Be clear about what that buys: it makes adding one a deliberate act, which is exactly what was missing when the shipped defect went in. It does **not** audit the ones that already exist — a count cannot.

**A first pass of that audit ran on 2026-09-16** (`docs/audit/BASELINE.md` §11c). 123 of the 156 sites state or derive a tenant predicate within a few lines; the other **33 were read individually**. Twelve are platform-admin surfaces behind `admin.manage`, four are pre-authentication lookups where the presented secret is the authority, twelve derive from a key that is itself tenant-owned — and **four were a real defect**: `WebhookDispatcher` wrote delivery rows with no `tenant_id`, so a queue worker either violated a NOT NULL constraint or filed the row against whichever tenant the *previous* job had left resolved. `CurrentTenant` is a `singleton`, not a `scoped` binding, so it survives between jobs. Fixed, with tests that fail on each half.

That defect turned out to be a symptom. `CurrentTenant` was bound as a `singleton`, so it survived for the life of a worker process — and **eight queued jobs and a queued listener call `set()` while nothing in `app/` ever calls `forget()`**. The next job inherited the previous job's tenant instead of failing closed. It is now `scoped`, which the framework flushes between jobs (`QueueServiceProvider` hands the Worker a `$resetScope` callback; `Worker::daemon()` calls it before reserving each job). If you write a job that needs tenant context, **set it yourself** — do not assume it is absent, and do not assume it is right.

The 123 with a predicate were **not** individually audited. Having one is necessary, not sufficient.

**A second pass ran on 2026-09-22** (`docs/audit/BASELINE.md` §11d), over *all* 156 with a
stricter test — a `where`-family predicate on `tenant_id` **in the same statement**, rather
than a mention within ±8 lines. **It found nothing that can reach another tenant's rows**,
and five sites that are safe for reasons nothing enforces: two device lookups keyed on
columns with **no unique index**, a builder helper safe only because both its callers add
the predicate, fifteen `::find()` sites that state nothing, and a `supervisor_id` validation
rule that does not scope. The shape it reports: **106 of 156 sites prove their own safety;
50 do not** — they are safe because of a gate, a dispatcher, a caller or a backfill
somewhere else. *(That pair is the 2026-09-22 reading. It is **113 of 157; 44** after the
closures below — re-measured in §11i, closed in §11j, and the paragraphs at the end of
this section say what moved.)*

**The five sites added since that audit were read individually on 2026-09-18**, because the
pin having moved from 156/53 to 161/55 means five bypasses entered *after* the only pass
that ever looked at them line by line. The inventory test forced each to be a deliberate
act, which is what it is for — but a deliberate act is not a reviewed one. All five carry a
predicate:

| Site | Why it is safe |
|---|---|
| `AdminPlanController::update` | Platform-admin: `Gate::authorize('admin.manage')`, route carries `EnsurePlatformContext` + `RequirePlatformMfa`. States `plan_id`; the cross-tenant count is the intent |
| `AdminPlanController::destroy` | Same |
| `NotifyDeviceOffline` | States `tenant_id`, derived from `$device->tenant_id` — the device is tenant-owned |
| `DispatchWebhookJob::handle` (delivery) | States `tenant_id` from `$webhook->tenant_id`, plus `webhook_id` |
| `DispatchWebhookJob::failed` | Derives via `webhook_id`, which is tenant-owned, plus the delivery primary key |

**One inconsistency worth knowing rather than fixing blind:** `handle()` states `tenant_id`
explicitly while `failed()` relies on `webhook_id` alone. Both hold — `webhook_id` is
itself tenant-owned — but the two halves of one class disagree about how much to state, and
that asymmetry is the shape a later defect takes.

**Three of those five are now enforced** (2026-09-23, `docs/audit/BASELINE.md` §11e), each
with the loudest mechanism the site allows:

- `devices.webhook_token` has a **unique index**. The token lookup drops the tenant scope
  and lets the token both select and authenticate; that rested on
  `Device::generateWebhookToken()` being `bin2hex(random_bytes(32))`, and now rests on the
  schema. A weaker generator fails at the insert instead of resolving to another tenant's
  device. NULL is still allowed on purpose — token-less devices authenticate by IP
  allowlist, and the seeder creates them.
- `OrganizationProvisioner::query()` takes the **tenant id as a required argument** and
  applies the predicate itself. Both callers already passed it one line later, so the SQL
  is unchanged; a third caller can no longer forget.
- `devices.serial_number` kept **no** unique index at the time, and the reasoning here was
  half right: a tenant-scoped index would indeed not make the lookup safe, because the
  bypassed query does not state `tenant_id`. What it missed is that a **global** one does.
  *(Superseded 2026-09-23 — §11h. See below.)* `Device::webhookIpAllowed()` being
  fail-closed is still what held the site until then, pinned in
  `tests/Feature/Security/DeviceWebhookTenantScopeTest.php`.

**The fourth was an authorised behaviour change** (2026-09-23, §11f), and it covers **all
seven** employee relation fields — `department_id`, `branch_id`, `position_id`, `grade_id`,
`team_id`, `cost_center_id` and `supervisor_id`. A `public_id` from another tenant now
returns **422** instead of 201-with-a-silent-null. Four constraints, each load-bearing:

- The check is a `withValidator()` hook in
  `Http/Requests/Employee/Concerns/ValidatesRelationTenancy.php`, **not a rule**. Scramble
  generates `src/src/api/generated.ts` from `rules()`, and `ChangePlanRequest` already
  records that the `Rule::exists(...)` builder is untested against that gate. `rules()` is
  byte-identical to before, so the contract cannot drift.
- **Never put an explanatory comment above a key in any array Scramble reads.** It
  publishes them as OpenAPI `description`s — see `create_login` at `generated.ts:6904`,
  whose PHP comment is in the client contract verbatim. *(Widened 2026-09-23: this said
  "in `rules()`", and that reading cost a red API-contract gate on PR #62 — a comment above
  a key in a controller's `response()->json([...])` array was lifted into `generated.ts`
  just the same. It is **any** array literal the spec is derived from: FormRequest rules
  and controller response arrays alike. Put the explanation on a statement instead, or in
  `BASELINE.md`.)*
- The hook re-runs `resolveRelationIds()`'s own scoped lookup rather than restating
  `tenant_id`, so validation and resolution cannot disagree. The field/model map lives once,
  in `App\Support\EmployeeRelations::MAP`, read by both — an eighth field added to one and
  not the other would be a silent null again.
- The message is Laravel's generic `validation.exists` line, identical to a genuinely
  nonexistent id: a distinguishable one would confirm a `public_id` across tenants, which is
  what the scope hides. Tests pin the two as equal **per field**.

`CostCenter` is the only one of the seven without `SoftDeletes`, so it is absent from the
soft-delete dataset. And `@mixin FormRequest` must use the imported name — the
leading-slash FQN fails Pint's `fully_qualified_strict_types`, which cost a red gate.

**The fifth was closed on 2026-09-23 (§11g), and the count in §11d was wrong.** There are
**nine** bare `::find()` sites, not fifteen — the fifteen is the size of the
"derived from a tenant-owned key" row in §11d's class table, which also counts sites
stating `webhook_id` or `payroll_run_id`. The prose conflated a class with its subset, the
same trap this file records for 156/161 one scale down. Of the nine:

- **Five now state `tenant_id`.** `WorkforceMigrationService::mergeTarget()` already had
  `$tenantId` in hand — its other branch scoped by it, so the two halves disagreed. The
  four queued-job lookups (`DispatchWebhookJob`, `NotifyAnnouncementAudienceJob`,
  `ProcessPayrollJob` ×2) had **no tenant id in scope at all**: each is the root lookup of
  a job whose payload carried only a row id, so the tenant was derived *from the row being
  fetched*, which proves nothing about which row you were entitled to fetch. The tenant is
  now carried in the payload; each job has exactly one dispatch site.
- **Two are impossible.** `Tenant` is on the Global Model List — no `BelongsToTenant`, no
  `tenant_id` column, so there is no scope to re-apply.
- **Two would be regressions, and now say so in a comment.** `BackupTenantJob`'s requester
  is a platform admin whose `tenant_id` is null, so scoping would silently drop every
  backup notification; `AdminTenantController::resolveImpersonator()` recovers the super
  admin behind an impersonation, which is necessarily cross-tenant and is authorised by the
  `isSuperAdmin()` re-check, not the lookup.

**`$tenantId` on those jobs was nullable with a default, deliberately — and is now
required.** PHP restores a job from `unserialize()` without running the constructor, so a
required `readonly int` makes every job *already queued* at deploy time fail permanently.
That was why §11g hedged; §11j (2026-09-23) took the hedge out, which is why **a queue
drain is now a deploy precondition** — `docs/DEPLOYMENT.md` → *Draining the queue before
an upgrade*, which also names the one thing a drain does not clear:
`DispatchWebhookJob` backs off to **24 hours**, so a delivery already deep in retry
survives it.

**The last was closed on 2026-09-23 (§11h), and all five of §11d's fragile sites are now
enforced.** Live devices carry a unique `(serial_number, adapter_type)` index. Three things
about it are load-bearing:

- **It is global, not tenant-scoped.** The bypassed lookup does not state `tenant_id`, so
  two *different* tenants holding one serial is exactly the case it cannot tell apart. Only
  global uniqueness makes that `->first()` sound. The cost is stated rather than hidden:
  registering a serial another tenant holds now fails, which reveals that serial exists
  somewhere. Inherent to any global constraint, and narrow — an authenticated operator
  registering hardware they hold learns existence and nothing else.
- **It is built on a generated column**, `serial_number_active`, which goes NULL once
  `deleted_at` is set. `destroy()` soft-deletes and there is **no restore route**, so a
  plain index would burn a serial permanently. MariaDB has no partial indexes; this is the
  workaround. The column is VIRTUAL because SQLite can add a virtual generated column with
  `ALTER TABLE` and refuses a stored one.
- **The query does not rely on the index.** `resolveWebhookDevice()` takes two candidates
  and rejects when it finds more than one, so an older database or a dropped index does not
  silently restore the coin flip.

`ValidatesSerialUniqueness` turns a constraint violation into a 422 instead of a 500. It
queries cross-tenant on purpose and states no `tenant_id` — which is why the inventory
moved to 157/55 and the entry says so plainly.

**Re-measured after all five** (2026-09-23, §11i): **108 of 157 prove their own safety, 49
do not**, still nothing that can reach another tenant's rows. Two sites moved into the
self-proving class — `OrganizationProvisioner::query()` and
`WorkforceMigrationService::mergeTarget()` — and `ValidatesSerialUniqueness` entered the
other one, which is why 50 fell by one and not two.

**Closed the same day** (§11j): `$tenantId` is a required `readonly int` on all three jobs,
the five `if` blocks are deleted, and each lookup states the predicate on its own chain.
**The figure is now 113 of 157 prove their own safety, 44 do not** — the ceiling §11i named
as reachable by deletion. *(That pair is the 2026-09-23 reading and is left as measured.
`NotifyDeviceSyncFailed` joined the inventory on 2026-09-25, making it **114 of 158**: it
states `tenant_id` on its own chain, derived from the device, so it enters the self-proving
class and the 44 is unchanged. Re-measuring the whole set is a separate pass, and quietly
incrementing a figure nobody re-derived is how these drift.)* The remaining 44 are gates, pre-authentication secrets, global
models and sweeps, where a predicate is impossible or contrary to the feature; closing any
of those would be a behaviour change, not a tightened signature.

**The lesson to carry is the one that made those five count for nothing in between.** They
had the tenant id in the payload and wrote `if ($this->tenantId !== null) { $query->where(...) }`
— a separate, conditional statement. A predicate conditional on a nullable payload field is
stated by the *dispatcher*, not by the query, which is the dependency §11g existed to
remove. **Having a predicate is necessary, not sufficient; having one conditionally is
neither.** `QueuedJobTenantPredicateTest` now reflects over all three constructors and
fails if the argument goes back to optional *or* to `?int`.

When it fails, the message tells you the question to answer: does the new query state `tenant_id` itself, or derive from a key already tenant-owned? If yes, update `tests/Feature/Security/tenant-scope-bypasses.php`. If no, you have found the next one.

Raw SQL (`whereRaw`, `selectRaw`, `DB::raw`) carries no scope at all. Say `tenant_id` yourself.


### Running the gates locally needs a GitHub token

`composer install` is **163 packages**, every one a public Packagist package whose `dist`
URL points at `api.github.com`, and `api/composer.json` declares no custom `repositories`.
Unauthenticated, GitHub allows **60 requests per hour per IP**, so a cold install gets
partway and then takes 403s until composer stops at `AuthHelper.php:132` with
`Could not authenticate against github.com` — **0 of 163 installed**, no `vendor/bin/pest`,
no local gate.

**The fix is a token with no scopes at all.** Everything being fetched is public and this
repository is public; the token only lifts a rate limit.

```bash
composer config --global --auth github-oauth.github.com <token>   # writes COMPOSER_HOME/auth.json
composer diagnose | grep 'github.com oauth'                       # → "OK  expires on <date>"
```

A classic PAT with **no boxes checked**, or a fine-grained token with read-only access to
public repositories, is sufficient. **Do not use a `repo`-scoped token** — it would hand
every one of those 163 packages' install scripts a credential to your private repositories
for no benefit. Never commit `auth.json` or `.env`; both are outside the working tree or
gitignored, and `CONTRIBUTING.md` carries the longer version of this.

**In an agent sandbox the token must be set in the environment's own settings**, not on the
operator's machine and not as a shell export — each command starts a fresh shell, so an
export does not survive, and the container is a different machine from the operator's.
Until it is, `Pint`, `PHPStan` and `Pest` cannot run there and **CI is the only gate**.

#### In a Claude Code cloud sandbox, a token does not help — measured 2026-09-25

Everything above is true of an ordinary machine. It is **not** the cause in the cloud
sandbox, and an agent that reads the section above will spend its time chasing a credential
that cannot work. What was measured there:

| Check | Result |
|---|---|
| `GET api.github.com/rate_limit` | **15000/hour, 43 used** — rate limiting is not the cause |
| `GET api.github.com/repos/phpstan/phpstan/zipball/<ref>` | **403** |
| `GET codeload.github.com/...` | **403** |
| `git clone https://github.com/<any public repo>` | **works** |

The 403 body says it outright: *"GitHub access to this repository is not enabled for this
session."* The sandbox's egress proxy scopes GitHub **per repository**, and only the
repositories attached to the session are in scope. Composer's `dist` URLs point at
`api.github.com/repos/<vendor>/<pkg>/zipball/...` — 163 different repositories, none of them
this one. **No token changes that**, because it is not an authentication failure; composer
merely reports it as one, at the same `AuthHelper.php:132` the rate-limit case reaches.

`--prefer-source` gets **162 of the 163** through, because anonymous `git clone` of a public
repository *is* served. Exactly one package cannot take that route:

```
$ python3 -c "import json;d=json.load(open('api/composer.lock'));
  print([p for k in ('packages','packages-dev') for p in d[k] if p['name']=='phpstan/phpstan'][0]['source'])"
None
```

`phpstan/phpstan` ships with **`"source": null` in `composer.lock`** — there is no source
install path at all, so it can only arrive as an API zipball, and one blocked package fails
the whole install. That is why `vendor/bin/` stays empty even after every other package has
synced.

**So in this sandbox `CI is the only gate` still holds, but for a different reason,** and
the distinction matters when deciding whether to keep trying: the rate-limit case is fixed
by a credential the operator can supply, and this one is not.

---

## Quality gates

```bash
./scripts/gates.sh            # the full sweep
./scripts/gates.sh quick      # everything except the test suites — seconds
./scripts/gates.sh backend    # composer validate, Pint, PHPStan, Pest
./scripts/gates.sh frontend   # i18n, Prettier, ESLint, tsc, Vitest
./scripts/gates.sh docs       # markdown link integrity
./scripts/gates.sh mysql      # the backend suite against MariaDB, not SQLite
./scripts/gates.sh security   # composer audit + npm audit (production deps)
./scripts/gates.sh coverage   # backend line coverage — needs PCOV or Xdebug
```

`performance` is outside the full sweep too, and was **unrunnable without Docker
until 2026-09-17** — it delegated unconditionally to `pest-isolated.sh`, the same
defect that kept PHPStan from ever passing in CI (cause 3 below). It is now
native-first like the others, and it prints what it measured rather than only
whether it passed, so the output is a baseline you can compare against. First one
recorded in `docs/audit/BASELINE.md` §13e: the tightest route uses 13% of its
budget on this hardware.

`security` is deliberately outside the full sweep, like `performance`. It goes
red when a third party publishes an advisory, not when you break something, and
a gate that is permanently red stops being read. **It is green as of 2026-09-16**
— `npm audit --omit=dev` reports 0 vulnerabilities and `composer audit` is
clean. It was red from the day it was added; see `docs/audit/BASELINE.md` §15a
for what closed it. Expect it to go red again without warning, because that is
what this gate is for.

`mysql` is outside it too, for a different reason: it needs a database server,
and the full sweep has to stay runnable on a fresh clone. **It fails rather than
skipping when no server is reachable** — the whole point is that the suite
otherwise only ever runs on SQLite while production runs on MariaDB. The first
time anyone pointed it at MariaDB (2026-09-15) it found a search defect that
returned nothing in production and passed every test. CI runs it on every push.

**CI calls `gates.sh` rather than restating it**, so the two cannot drift.

**The Actions tab was finally read on 2026-09-16, and every run had failed — 50 of them, since the first push.** Not one gate had ever executed. Five causes, every one structural rather than code:

1. **No shell script had its executable bit.** Every `scripts/*.sh` was mode `100644`, so `./scripts/gates.sh` exited **126** (Permission denied) on a Linux runner. Git on Windows does not track the bit unless `core.filemode` is set, so it was never committed. Fixed with `git update-index --chmod=+x`.
2. **`composer install` died before any gate ran.** `config/broadcasting.php:25` defaults to `reverb` when `BROADCAST_CONNECTION` is unset, a runner has no `.env`, and `routes/channels.php` calls `Broadcast::channel()` at load time — so `package:discover` built a Reverb broadcaster with a null Pusher key and composer exited 1. Fixed at the time with a workflow-level `BROADCAST_CONNECTION: "null"`.

   **Fixed at source on 2026-09-25, and the workaround is gone.** `config/broadcasting.php:25`
   now defaults to `null`, so a checkout with no `.env` selects the null driver on its own.
   The override has been removed from all three workflows that carried it — `gates.yml`,
   `security.yml` and `verify-without-fix.yml`. Nothing that runs changed: `docker-compose.yml:39`,
   `.env.example`, `.env.shared-hosting.example` and `phpunit.xml` all set the value explicitly,
   so the default only ever served the no-`.env` path, where `reverb` was the one value
   guaranteed to fail. `BroadcastConnectionConfigTest` now pins the unset case, which is what
   keeps the override from being needed again — a workaround left in place would have masked a
   regression of the default and asserted, in its own comment, a `reverb` default that no
   longer exists.

   **Locally the same defect needs `.env` to exist *before* `composer install`, not after.**
   `package:discover` runs as a post-autoload-dump script *during* the install, so copying
   `.env.example` afterwards is too late — the install has already exited 1. The order is
   `cp api/.env.example api/.env` (setting `BROADCAST_CONNECTION=null`, since `.env.example`
   ships `reverb`), then `composer install`, then `php artisan key:generate`. `key:generate`
   comes last because it needs a `vendor/` that does not exist until the install completes.

3. **`phpstan_gate` required Docker.** It delegated unconditionally to `scripts/phpstan-isolated.sh`, which exits 1 with "Container et-api-1 is not running" when there is no `et-api-1`. A CI runner has native PHP and no container, so **PHPStan could never have passed in CI regardless of the code**. Now native-first with the container as fallback, the same shape `pest_gate` already had.

4. **The backend job had no `APP_KEY`.** `Employee.tin` and `national_id` use the `encrypted` cast, and Laravel's encrypter refuses to boot without a key, so **156 tests** died with `MissingAppKeyException`. `Backend suite on MySQL` ran `key:generate` and passed; `Backend` never did and failed. Found only once `gates.sh` began publishing the failing gate's output as an annotation.

5. **Every `setup-node` pinned Node 20, which went end-of-life on 2026-04-30.** Two frontend test files fail on it — one because MSW's Node interceptor never settles a `multipart/form-data` request on Node 20 *or* 22, one because of a genuinely ambiguous query that only Node 20 was fast enough to expose. Node is now declared once in `.nvmrc` (24) and read by `node-version-file`. See `docs/audit/BASELINE.md` §12d; the full suite is verified green on Linux + Node 24, which is the first CI-equivalent green the frontend suite has ever had.

Once 1 and 2 were fixed the workflows ran for the first time (run #55): **Documentation integrity and Backend suite on MySQL passed**, three jobs failed. Those three were real — a stale `phpstan-baseline.neon` entry, `tsc` needing `.next/types` that only exists after a build, and genuine OpenAPI drift.

**A correction, recorded rather than quietly fixed:** the backend failure was attributed to the stale baseline entry. That was inferred from a local *native* PHPStan run, never from the CI log. Because of cause 3 above, PHPStan never ran in CI at all, so the baseline error cannot have been what CI reported. Both defects were real and both needed fixing; the attribution was wrong.

The lesson worth keeping: this file twice told readers to treat CI as *probably fine but unconfirmed*. It was not fine. **Read the Actions tab rather than reasoning about it** — the whole reason the workflows exist is to tell you something you do not already know. And when it fails, read *its* log rather than reproducing locally and assuming the cause matches.

**Run #66 (2026-09-16, `1cf9083`) is the first fully green run** — Backend,
Backend suite on MySQL, Frontend, Documentation integrity and API contract all
passing together, 51 runs after the first push. Treat that as a starting line,
not a finish: five separate structural defects had to be fixed before a single
gate executed, and every one of them was invisible from the working tree.

What *is* active is the pre-push hook, once you enable it:

```bash
git config core.hooksPath .githooks
```

It runs `gates.sh quick` — every gate except the test suites — plus a check for `.env` files and `APP_KEY` literals in the diff.

---

## Where things are

| | |
|---|---|
| Conventions | [`docs/CLAUDE.md`](docs/CLAUDE.md) |
| How to contribute | [`CONTRIBUTING.md`](CONTRIBUTING.md) |
| Measured state of the codebase | [`docs/audit/BASELINE.md`](docs/audit/BASELINE.md) |
| Security reporting | [`SECURITY.md`](SECURITY.md) |
| Documentation index | [`docs/README.md`](docs/README.md) |
| Deployment target status | [`docs/deployment/GATE-0-RESULT.md`](docs/deployment/GATE-0-RESULT.md) |
| Deploying to Plesk shared hosting | [`docs/deployment/PLESK-HOSTING-GUIDE.md`](docs/deployment/PLESK-HOSTING-GUIDE.md) |
| **Hard rule — repository only** | [`docs/deployment/SHARED-HOSTING-CONTRACT.md`](docs/deployment/SHARED-HOSTING-CONTRACT.md) — work happens here, not in hosting settings. Outranks every deployment doc |

Production target is Ethio Telecom Linux shared hosting under Plesk. The VPS and Docker production assets are kept only until that cutover is verified.
