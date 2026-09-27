# OWNER DECISION REQUIRED — C-5: the production frontend mode

**Status: OPEN. Nothing in this repository may treat it as answered.**
**Raised:** 2026-09-27, from the forensic audit finding **C-5**.
**Decides:** whether the ETHR frontend is deployed to Ethio Telecom Bronze as a
**static export** served by Apache, or as a **Plesk Node.js application**.

This file exists because three authoritative sources disagree and **repository
evidence cannot settle it** — the disagreement is between two owner instructions,
not between a document and the code. Everything that *could* be resolved by
inspection has been; what is left is a choice.

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

## 6. Until it is answered

- **The Plesk Node application must not be enabled** — not on the strength of
  `DEPLOYMENT.md` §5 alone, which that section now says itself.
- **Static export must not be described as the owner-selected production mode.**
  It is the *implemented* mode and the mode the *contract* mandates; those are two
  true statements, and neither is "the owner chose it".
- `render-htaccess.php` renders **only** BRANCH B and cites this file when refusing
  BRANCH A. That is not a vote — it is refusing to make the choice silently.

---

## Related

- [`../deployment/SHARED-HOSTING-CONTRACT.md`](../deployment/SHARED-HOSTING-CONTRACT.md) — hard rules 2 and 3
- [`../deployment/shared-hosting/DEPLOYMENT.md`](../deployment/shared-hosting/DEPLOYMENT.md) §5 — the conflicting instruction
- [`../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md`](../migration/AUTHORITATIVE-BRONZE-MIGRATION-PLAN.md) §6 stage 2
- [`../audit/BASELINE.md`](../audit/BASELINE.md) §19, §20, §21 — what the export was measured to do
- [`DECISIONS.md`](DECISIONS.md) — where the answer should be summarised once taken
