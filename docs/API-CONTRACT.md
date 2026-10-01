# The API contract — how `generated.ts` is made, and the gate that keeps it true

**Written 2026-10-01 from the code** (audit item D14). Every claim cites the file and line it
was read from; where something is a package default this pass could not read (vendor is not
installed in the worktree that wrote it), it says **not verified**.

The frontend does not hand-write the shape of API responses. It imports them from
**`src/src/api/generated.ts`**, which is compiled from an OpenAPI document that is itself
generated from the Laravel routes. If the two drift, `tsc` keeps passing against a contract the
API no longer honours — so a gate regenerates it and fails on any difference.

---

## The pipeline

```
api/routes/api.php  ─┐
FormRequest rules()  ├─ Scramble ──► api/openapi.json ──► openapi-typescript ──► src/src/api/generated.ts
response arrays,     │  (scramble:export)  (gitignored)                          (committed)
API Resources       ─┘
```

| Step | What | Where |
|---|---|---|
| 1 | **Scramble** (`dedoc/scramble`, locked at **v0.13.36**) documents every route under `api/v1` | `api/composer.json:14`, `api/composer.lock:367-368`, `api/config/scramble.php` (`api_path`) |
| 2 | `php artisan scramble:export --path=openapi.json` writes **`api/openapi.json`** — not committed | `src/scripts/generate-api.mjs:36-44`; `api/.gitignore:26` |
| 3 | **openapi-typescript** compiles it to **`src/src/api/generated.ts`** — committed | `src/scripts/generate-api.mjs:46-51`; `src/package.json` (`openapi-typescript` ^7.13.0) |

One command runs both steps:

```bash
cd src && npm run generate:api        # src/package.json:23 → node scripts/generate-api.mjs
```

It needs **native PHP 8.2+** on `PATH` and **a migrated database**. Scramble types responses by
reading the live schema: no database fails the export with a `QueryException`, and — worse — an
*empty or partially migrated* one does not fail at all; it silently produces a degraded contract
(`scripts/api-types-check.sh:43-58`). Run `php artisan migrate` against the XAMPP MariaDB first
([`LOCAL_SETUP.md`](LOCAL_SETUP.md)).

The frontend reads types from it as `operations["<controller>.<action>"]` — for example
`src/src/features/admin/api.ts:171-172`.

## The gate

**`scripts/api-types-check.sh`** regenerates both files into a temporary directory and diffs the
result against the committed `generated.ts` (`:28-77`). Both steps are deterministic, so any
difference is drift, not noise (`:15-17`). A failure prints the first 40 lines of the diff and
the fix: `cd src && npm run generate:api`.

| Where it runs | How |
|---|---|
| Locally | `./scripts/gates.sh` — **the full sweep only**, because it needs both halves and a database (`scripts/gates.sh:19`, `:498-501`). `gates.sh quick` and `gates.sh backend` do not run it |
| CI | Job **`api-contract`** in `.github/workflows/gates.yml:473-535`: MariaDB 10.11 service, `migrate --force`, then the script |

So the rule for any change to an endpoint's input or output: **regenerate and commit
`generated.ts` in the same commit**, then let the full sweep or CI confirm.

## What ends up in the contract — and the trap

Scramble reads more than signatures:

- **`rules()` in a FormRequest** becomes the request schema. Changing a rule changes the
  contract.
- **Controller response arrays and API Resources** become the response schema.
- **A comment directly above a key in any array Scramble reads is published** as that field's
  OpenAPI `description`, and lands in `generated.ts` verbatim. Example: the two-line comment
  above `create_login` in `api/app/Http/Requests/Employee/StoreEmployeeRequest.php:61-63` is the
  `@description` at `src/src/api/generated.ts:6908-6912`.

The last one has cost a red contract gate (PR #62, recorded in root
[`CLAUDE.md`](../CLAUDE.md) §4): a comment above a key in a controller's `response()->json([...])`
array was lifted into `generated.ts` exactly as a `rules()` comment is. **Put explanations on a
statement, in a method docblock, or in a document — never above an array key**, unless you
intend it as public API documentation.

Two patterns this codebase uses to keep the contract stable:

- **A check that must not change the schema goes in `withValidator()`, not `rules()`.** The
  cross-tenant relation check is written that way so `rules()` — and therefore the contract —
  stayed byte-identical (`api/app/Http/Requests/Employee/Concerns/ValidatesRelationTenancy.php`;
  root `CLAUDE.md` §4).
- **`exists:` in string form, not the `Rule::exists(...)` builder**, because the builder is
  untested against the gate (`api/app/Http/Requests/Billing/ChangePlanRequest.php:16-21`).

Routes that must not be documented carry `#[ExcludeRouteFromDocs]` — the two cron endpoints do
(`api/app/Http/Controllers/Api/V1/Cron/CronRunController.php:44`, `:62`).

## Where Scramble is wrong

It mistypes some responses: summed cents as `string`, `attendanceSetting.show` as `unknown[]`,
some intelligence lists as `Record<string, never>` (audit N13, `auditrecent.md` §10). At those
sites the frontend types are hand-written against the PHP, with a comment saying so. Do not
"fix" `generated.ts` by hand — the gate will put it back. Fix the PHP so Scramble can infer the
type, or keep the hand-written type and its comment.

**The version is pinned on purpose.** Dependabot's bump to 0.13.45 (PR #9) was closed on
2026-09-30 because regenerating would have written a *worse* contract — paginated `data` typed as
`string`, paginator `links` collapsed to `unknown`, nullable page URLs losing `| null`
(`auditrecent.md` §9). Upgrade Scramble only with a reviewed diff of `generated.ts`.

## The interactive docs page

Scramble also serves a browsable UI, behind its `RestrictedDocsAccess` middleware
(`api/config/scramble.php`). Outside the local environment that middleware consults the
`viewApiDocs` gate, which this app grants to **super admins only**
(`api/app/Providers/AppServiceProvider.php:273-280`). The path is **not verified**: that comment
names `GET /api/docs`, while Scramble's package default is `/docs/api`, and `config/scramble.php`
sets neither explicitly. On the static-export production target only paths that reach Laravel
are served, so check it there before relying on it.
