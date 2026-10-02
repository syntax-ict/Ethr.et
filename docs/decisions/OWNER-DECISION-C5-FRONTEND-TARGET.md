# C-5 — the production frontend mode: **DECIDED**

> # ✅ OWNER DECIDED, 2026-09-27 — **STATIC EXPORT served by Apache/Plesk.**
>
> **ETHR production must not depend on a Plesk Node application.**
>
> | | |
> |---|---|
> | **Frontend** | `ETHR_TARGET=shared-hosting` static export → Apache/Plesk, from `<DOCROOT>` |
> | **Backend** | PHP/Laravel → Apache/Plesk, same document root, via the `.htaccess` front controller |
> | **Database** | MySQL-compatible database supplied by Ethio Telecom |
> | **Node in production** | **None.** No continuously running Node process, no Passenger, no Plesk Node extension |
>
> **The owner's stated reasons**, recorded as given: the target is Ethio Telecom
> shared hosting / Bronze; the static export already passes CI; CI confirms **689
> exported files, 186 `_next` files, 4 shell documents and no `server.js`**; and
> the repository must stay deployable on shared hosting with no long-running Node
> process.
>
> This selects **BRANCH B** of `docs/deployment/shared-hosting/.htaccess`. Render
> it with:
>
> ```
> php scripts/shared-hosting/render-htaccess.php --target=static-export -o <DOCROOT>/.htaccess
> ```
>
> ### ⚠ The branch letters run backwards, and the directive that decided this hit it
>
> The instruction was worded *"Use Branch A: static-export frontend served by
> Apache/Plesk"* and *"keep `render-htaccess.php --branch=a` as the appropriate
> production path"*. **In this repository the letters are the other way round:**
>
> | | |
> |---|---|
> | **BRANCH A** | the Plesk Node.js application — the option being **excluded** |
> | **BRANCH B** | the static export served by Apache — the option being **chosen** |
>
> The description was unambiguous and was given four times with reasons, so the
> **intent** is what has been applied: static export. Following the letter would
> have selected the Node application — the exact opposite — and would also have
> failed, because under BRANCH A the *"Everything else"* block is **deleted**
> rather than enabled, which is not a render at all.
>
> **Nothing was renamed to tidy this up.** The runbook, the `.htaccess` and
> `BASELINE.md` §21 all say "BRANCH B", and those are measurement records;
> relabelling a measured thing is how a measurement stops being one. What changed
> instead is the interface: `--target=static-export` names the thing rather than
> indexing it, `--branch=b` still works, and `--branch=a` / `--target=node` now
> fail with an error that says *"if you were told to use branch A meaning the
> static export, the letter is wrong"*. Verified 2026-09-27.

**Raised:** 2026-09-27, from the forensic audit finding **C-5**.
**Decided:** 2026-09-27 by the owner, as above.
**Decides:** whether the ETHR frontend is deployed to Ethio Telecom Bronze as a
**static export** served by Apache, or as a **Plesk Node.js application**.

The analysis below is kept as written, because it is why the decision was
answerable and it records what the choice cost. It was accurate while the question
was open; **§5 Option A is the option that was taken**, and §6 — *"Until it is
answered"* — no longer applies except for its last bullet, which still holds.

This file existed because three authoritative sources disagreed and **repository
evidence could not settle it** — the disagreement was between two owner
instructions, not between a document and the code. Everything that *could* be
resolved by inspection was; what was left was a choice, and it has been made.

---

## 1. Why this cannot be decided by reading dates

| Source | Date | Says |
|---|---|---|
| [`../deployment/SHARED-HOSTING-CONTRACT.md`](../deployment/SHARED-HOSTING-CONTRACT.md) **hard rule 2** | set 2026-09-22, **restated 2026-09-25** | *"A Plesk extension is not a default. The Node.js extension is the case in point."* Listed under **NEVER REQUIRED** |
| Same, **hard rule 3** | 2026-09-22 | *"A field that is absent is not a default to configure — it is a dependency to remove"* (**G0-A**: neither directive textarea exists) |
| [`../deployment/shared-hosting/DEPLOYMENT.md`](../deployment/shared-hosting/DEPLOYMENT.md) §5 | **2026-09-24** | *"**Node.js is the chosen branch — owner decision 2026-09-24.**"* |

**The owner decision falls between the contract's setting and its restatement.**
The runbook has carried a `PRECEDENCE CONFLICT` banner since 2026-09-25 saying so
in those words, and declining to resolve it: *"not resolved here, because it is
not this runbook's to resolve."* That was correct then and is correct now.

Both instructions are the owner's. A later date does not establish that the
restatement was aimed at this decision, and the contract's own text says it
*"outranks every table below it"* — which is an ordering claim about documents, not
evidence about intent.

---

## 2. What Phase 1 changed, and what it did not

This is the part most likely to be misread, and it has already been misread once.

**Phase 1 retired the *routing* argument against Node.** Serving `/api` needs no
reverse-proxy directive: `mod_rewrite` is honoured (G0-B.1 **VERIFIED**), PHP-FPM
answers `.php` at the document root, and `Authorization` reaches PHP (G0-B.4
**VERIFIED**). Measured again on Apache 2.4.58 in `BASELINE.md` §21g — 18 of 18,
`/api` and `/sanctum` both reaching `index.php`.

**It did nothing to hard rule 2.** G0-G's panel reading *confirms* Node.js here is
an **extension** (the panel offers *Enable Node.js*), which is exactly what rule 2
excludes.

> An earlier draft of the authoritative plan concluded *"the Node branch is not
> foreclosed"* from the routing half alone. That was withdrawn, and the root
> `CLAUDE.md` records the same error shape at a different scale for the 156/161
> bypass count: **"They were different quantities that happened to coincide."**
> Killing one of two independent reasons and writing as though both were gone is
> the mistake. **Do not repeat it in either direction** — including by treating
> rule 2 as decisive about an instruction given two days before its restatement.

---

## 3. What the repository has actually built

**The code is on the static-export side, and that is a fact about the tree rather
than an argument.** `5b47e0d` (2026-09-26) implemented it:

| Built | Where |
|---|---|
| `output` switches on `ETHR_TARGET` | `src/lib/build-target.ts`, `src/next.config.ts` |
| Four server-component wrappers + one sentinel shell per `[id]` route | `src/app/(dashboard)/{employees,payroll,devices,admin/tenants}/[id]/page.tsx` |
| Real id read from `usePathname()` after hydration | `src/lib/hooks/useRouteId.ts`, `useHydrated.ts` |
| `force-static` on `robots.ts`, `sitemap.ts`, `manifest.ts`, `og.png/route.tsx` | those files |
| `(auth)/layout.tsx` skips its `headers()` read on that target only | that file |
| BRANCH B Apache rules, rendered and validated | `docs/deployment/shared-hosting/.htaccess`, `scripts/shared-hosting/render-htaccess.php`, `api/tests/Feature/SharedHostingHtaccessTest.php` |
| A CI gate that builds and verifies the artifact | `.github/workflows/gates.yml` → `static-export`; `src/scripts/verify-static-export.mjs` |
| Hydration contract pinned by tests | `src/test/static-export-{route-id,host}.test.tsx` |

**`standalone` remains the default**, so none of this commits the decision: a
`standalone` build is unchanged and still emits `server.js`. Both targets build
today.

**What the export costs, stated rather than buried:** the marketing pages (`/`,
`/features`, `/pricing`, `/faq`, `/contact`) lose server-side rendering. That is an
SEO consideration for whoever owns it, not a technical blocker.

---

## 4. What choosing Node would additionally require

Not presented as an argument against it — presented so the choice is costed.

1. **Enabling a Plesk extension**, which hard rule 2 currently forbids. If Node is
   chosen, rule 2 has to be amended in the contract, not worked around.
2. **Answering who owns `/`** — **M4**, still open. The panel read *Application URL*
   `http://ethr.et` and *Document Root* `/ethr`. If Plesk mounts the Node app at the
   domain root, `/api/v1/...` may never reach `index.php` and the same-origin
   arrangement the whole layout depends on does not exist. `DEPLOYMENT.md` §5 is
   explicit that this *"must not be guessed from Plesk's general documentation"*.
   The static-export branch has no such question, because there is only one server.
3. **Setting the entry point to `server.js`.** The panel default read `app.js`, which
   is wrong for this app. A mismatched entry point fails at start — loudly, at least.
4. **Building on Node 22 to match the host**, per `docker/frontend/Dockerfile`
   (`FROM node:22-alpine`). This does **not** license relaxing `.nvmrc`'s 24: the
   host never runs the gates.
5. **Deleting the BRANCH B block** from the deployed `.htaccess` rather than
   enabling it, because Passenger inserts its own front-controller rule ahead of
   this file and a second catch-all conflicts.

---

## 5. The decision, and how to record it

**Pick one and write it here.** Then the propagation below is mechanical.

### Option A — static export (what the code is built for)

- Amend `DEPLOYMENT.md` §5: remove the `PRECEDENCE CONFLICT` banner, demote the
  Node branch to a documented alternative, promote the fallback section to primary.
- Amend `MIGRATION_STATE.md` **M4** — it currently says *"Enable the Plesk Node app,
  then fetch one API route and one frontend route"*, which rule 2 forbids. Under
  Option A, M4 is **dropped**, not deferred.
- `BRONZE-COMPATIBILITY-MATRIX.md` §1 *"1 website"* row: replace *"plus a Next.js
  Node app"* with the static export.
- No code change. `render-htaccess.php --branch=b` already refuses BRANCH A.

### Option B — Plesk Node.js application

- Amend `SHARED-HOSTING-CONTRACT.md` hard rule 2 and its **NEVER REQUIRED** list,
  explicitly, with the owner's reasoning. Do not leave rule 2 standing and
  contradicted.
- Keep **M4** and answer it before anything else, per §4.2 above.
- `render-htaccess.php` gains `--branch=a`, whose semantics are *delete the
  "Everything else" block*, not *enable it* — the script currently refuses that
  branch by design and cites this file.
- The `static-export` CI job stays useful either way (it keeps the export building)
  but stops being the gate on the production artifact; a Node build gate replaces it.

### Option C — defer

Legitimate, and the current state. The cost of deferring is that `DEPLOYMENT.md` §5
keeps two primaries and a reader can follow the wrong one. Everything else in the
migration proceeds regardless: **no other open item is blocked by this**, which is
why the audit classified it as a decision rather than a blocker.

---

## 6. ~~Until it is answered~~ — what stands now that it is

- **The Plesk Node application must not be enabled.** Unchanged, and now for a
  stronger reason than before: it is no longer merely excluded by hard rule 2, it is
  excluded by the owner's decision. `DEPLOYMENT.md` §5 no longer offers it as a
  primary path.
- ~~**Static export must not be described as the owner-selected production mode.**~~
  **It now is exactly that.** Three statements used to be distinguishable — that the
  export was *implemented*, that the *contract* mandated it, and that the *owner* had
  not chosen it. The third has changed, and the documentation says so rather than
  continuing to hedge.
- `render-htaccess.php` renders **only** the static export and cites this file when
  refusing the Node branch. That was a refusal to choose silently; it is now the
  implementation of a decision, and the refusal message says which.

### 6a. What was propagated when the decision landed (2026-09-27)

Every item §5 Option A listed, plus two the list did not anticipate:

| Where | Change |
|---|---|
| `shared-hosting/DEPLOYMENT.md` §5 | `PRECEDENCE CONFLICT` banner replaced with the decision; static export promoted to primary; the Node branch kept as a documented, non-selected alternative |
| `MIGRATION_STATE.md` **M4** | **dropped**, not deferred — under static export nothing contends for `/`, so the question does not arise |
| `BRONZE-COMPATIBILITY-MATRIX.md` §1 | *"1 website"* row states the static export |
| `shared-hosting/.htaccess` | BRANCH A/B headers state the decision and the letter trap |
| `render-htaccess.php` | `--target=static-export` added *(not anticipated by §5 — it exists because the deciding directive tripped on the branch letters)* |
| `SHARED-HOSTING-CONTRACT.md` | hard rule 2 **unchanged** — the decision agrees with it, so there was nothing to amend. Recorded because §5 Option **B** would have required amending it and a reader should see that it did not happen |

---

## Related

- [`../deployment/SHARED-HOSTING-CONTRACT.md`](../deployment/SHARED-HOSTING-CONTRACT.md) — hard rules 2 and 3
- [`../deployment/shared-hosting/DEPLOYMENT.md`](../deployment/shared-hosting/DEPLOYMENT.md) §5 — the conflicting instruction
- [`../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md`](../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md) §6 stage 2
- [`../audit/BASELINE.md`](../audit/BASELINE.md) §19, §20, §21 — what the export was measured to do
- [`DECISIONS.md`](DECISIONS.md) — where the answer should be summarised once taken
