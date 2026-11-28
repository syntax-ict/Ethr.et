# ETHR frontend upgrade plan, 2026-11-28

**Status: approved by the owner on 2026-11-28. Nothing in it is built yet.** Measurements are of `main@843915da5a`. As each slice lands, record it in a status table here, the way `FRONTEND_REDESIGN_PLAN.md` §L does.

## At a glance

- **What it is.** An upgrade of the frontend only, with no backend change. It finishes and unifies the design work already shipped; it is not a redesign. Every setting gets a short line saying what it does when on, and the details move behind "How it works".
- **M1, visible quality** (about 12 PRs, about 1.5 to 2 weeks with four lanes running in parallel):
  - short settings text in the whole settings hub;
  - phone-friendly dialogs and 44px taps;
  - a tidier menu;
  - **a fast public landing page** (mobile score 73 → 90, load under 2.5 s, contact details in the HTML), with no redesign;
  - the three open fix PRs live;
  - a browser privacy report.
- **M2, one way to do each thing** (about 10 PRs): one badge, callout, tile, pager and heading scale everywhere; privacy fixes; short help text in every other area.
- **M3, standards** (about 8 PRs): the 46 registered guideline findings closed, WCAG 2.2 AA on 10 key journeys, performance held by budgets.
- **Decided on my recommendation:**
  - adopt the settings text standard;
  - `dark:` follows the theme picker;
  - bottom-sheet dialogs and 44px taps on phones;
  - drop JetBrains Mono;
  - no Claude Design;
  - keep the menu structure, fixing four defects and renaming two labels;
  - all ten quality strands taken, two of them in a lighter form.
- **You:**
  - approve;
  - read the am and om rewrites, or name a reader;
  - run the usability test with real staff;
  - for the landing page, supply one customer quote with written consent and the real metrics, confirm VAT on prices, and sign off the security page;
  - sign in to Plesk for deploys.
- **Guarantees:** nothing under `api/`, no URL changes, sign-in not redesigned, Sonnet builds and Opus reviews, every PR passes the gates before deploy.

## Context

The owner asked for an upgrade of the existing frontend only, with no backend change: reuse what exists, keep the code short, and meet professional international standards. Before writing anything, I inspected the repository: three read-only surveys of `src/` plus the earlier audits.

What they found decides the shape of this plan. **ETHR does not need a redesign.**
- The direction (the "quiet ledger"), the tokens and the shared components were designed and shipped on 2026-10-20 to 2026-10-22 (`docs/FRONTEND_REDESIGN_PLAN.md` §L, PRs #319 to #394). A full UX audit was clean after that: 288 passed, 0 failed.
- A 46-finding defect register against Vercel's Web Interface Guidelines followed on 2026-11-18 (`docs/audit/WEB-INTERFACE-GUIDELINES-2026-11-18.md`). Two of its fix PRs (#801, #802) are open and behind main. A third (13 confirmations) sits uncommitted in `F:\ethr-wt-wig-inputs`. The other nine slices have not started.

What is left is **consolidation and finishing**:
- the components exist, but pages still carry local copies of them;
- the type tokens exist, but almost nothing uses them;
- the registered defects are still open.

`docs/CLAUDE.md`: "Never restart completed work." This plan finishes it.

The owner added one more requirement, using `/settings/attendance` as the example. Every setting must say, in a short plain line, what it does when it is turned on, and this must hold across the whole system. That becomes slice S3: a standard, three small components, and a rewrite that rolls out area by area, with a ratchet that keeps help text short.

## Where I disagree with the brief

- **Claude Design: no.** Mockups would design a product that already has a direction, tokens and 133 shared components. For a working product, the truthful artefact is the real branch build, screenshotted at 375, 768 and 1280px in light, dark and high contrast.
- **Restyle or new visual direction: no.** It would undo a signed direction that is live in production, and would cost every page a re-review for no user gain. The visible improvement comes from consistency: one heading scale, one badge, one callout, one pager.
- **Skills, stated plainly:**
  - `ui-ux-pro-max` is on disk in `~/.agents/skills`. Claude Code does not load skills from there. I will make it available to Claude Code and use its rule data as a checklist, not as a style generator: it offers 67 styles, and we want none of them.
  - `Impeccable` is not installed, and the skill catalogue search returned nothing. It is third-party code, so I would ask before downloading it, naming the source. The plan does not depend on it.
  - `Web Design Guidelines` needs no install. The ruleset URL is the one the 2026-11-18 audit used, and the final review re-runs it as a delta.
  - `frontend-design` is installed and useful for the sheet and callout variants.
- **WIG rules lose to house rules where the owner decided:** sentence case, straight quotes, no long dash, no emoji, no exclamation mark, the house date and currency helpers, and the kiosk zoom lock.

## Measured state (2026-11-28, `main@843915da5a`)

**Stack**
- Next 16.4 App Router, static export.
- React 19.3, Tailwind 4.3 CSS-first; tokens in `src/src/styles/globals.css`.
- Radix and shadcn-style primitives (22 in `components/ui`).
- TanStack Query and Table, RHF and Zod, Recharts, lucide, sonner, next-themes. Custom i18n with en, am and om.
- Vitest: 464 files. Playwright: 44 specs. axe, LHCI.
- `design-ratchets.test.ts` holds five ceilings (text below 12px is at 82).

**Already right and kept as is:**
- one page title in the shell (`PageTitleBar` plus `route-meta`, with `PageActions` on 46 pages);
- one nav registry;
- `QueryBoundary`, `ErrorState` (silent failures held at 0), `FormField` (134 uses), `useZodForm`, `useConfirm`, `FilterBar`, `PaginationControls`, `StatCard`, `ChartCard`, `CrudSection`, `Stepper`;
- 0 raw palette classes, 0 native `confirm()`.

**Duplication and inconsistency to remove:**

| Area | Today | Canonical |
|---|---|---|
| Status colours | 17 local status-to-class maps in 15 files; 9 local badges (2 of them named `StatusBadge`) | `lib/status/registry.ts` with `StatusBadge domain` |
| Field wrappers | 8 local `Field` components | `patterns/FormField` |
| Stat tiles | 8 local tiles (`StatCard` again, `StatusCard`, `Metric`, `Stat`, `Tile`) | `components/dashboard/StatCard` |
| Callouts | 15 banner components plus hand-tinted boxes drawing their own markup (exact count in S4) | `ui/alert` (11 users) |
| Pagers | `DataTable`'s own pager and the timesheet pager | `PaginationControls` |
| Empty states | about 37 hand-made empties; `QueryBoundary` keeps its own default | `EmptyState` |
| Spinners | 243 inline `Loader2 animate-spin`; `Button` has no `loading` prop | `Button loading` |
| Headings | 42 `h1`s in about 15 class strings; `CardTitle` is a `div` in 165 uses with 7 class strings; `text-title`/`text-heading`/`text-numeric` used in 0 to 2 files | the type tokens |
| Raw `<button>` | about 98 with hand-written classes | `Button` variants |
| Dead code | `ProgressRing` (0 uses), `initialsFor` (0 importers), QueryBoundary's `DefaultEmpty` | delete |

**Responsive:**
- Dialogs are a centred box at every width; `Sheet` is left or right only.
- Buttons are 32 to 36px, and icon buttons go down to 24px; `--spacing-touch` (44px) is used in 3 files.

**Docs disagree with the code:**
- `data-theme` in the docs vs the `.dark` class in the code;
- dark primary `#3282B8` vs `#4a9fd8`;
- 48px vs 44px touch targets;
- `--color-accent` meaning;
- bottom sheets and `UndoToast` are described but do not exist;
- JetBrains Mono is declared and never loaded.

## The component kit, and when each is used

Every screen is built from these and nothing else. "New" means fewer than 40 lines, built on what is already installed.

| Need | Component | Status |
|---|---|---|
| Page title, breadcrumb, actions | `PageTitleBar` + `route-meta` + `PageActions` | Kept |
| A group of settings | `SettingsSection` (Card, `h2`, one-line intro, one Save) | New |
| One setting | `SettingRow` (label, one-line help, control, "How it works", effect tag, warning) | Extended |
| A number with a unit | `InputWithUnit` | New |
| One of 2 to 4 options | Segmented control | New; also replaces the 14 hand-built radio groups |
| On or off | `Switch` inside `SettingRow`, never alone | Kept |
| Form field | `FormField` + `useZodForm` + `FormErrorSummary` | Kept; the 8 local copies move onto it |
| Status | `StatusBadge` from `lib/status/registry` | Kept; the 17 local maps move into it |
| Note, warning, blocker | `Alert` (info, success, warning, destructive, neutral) | Extended; the 15 banners move onto it |
| Figures | `StatCard`, `ChartCard` | Kept; the 8 local tiles move onto them |
| Lists | `SimpleTable` (phone cards) or `DataTable` (employees only) + `FilterBar` + `PaginationControls` | Kept; one pager |
| Loading, empty, error | `QueryBoundary` → `Skeleton` / `PageSkeleton`, `EmptyState` with an action, `ErrorState` with Try again | Kept; the hand-made empties move onto them |
| Doing something | `Button` with `loading` | Extended |
| Are you sure / why | `useConfirm`, `ReasonDialog` | Kept |
| Focused task | `Dialog`, a bottom sheet on phones; `Sheet` for side panels | Extended |
| Steps | `Stepper` | Kept |
| Feedback after an action | `AppToaster` toast for results, `SaveStatus` inline for settings | Kept |

## Decisions you are approving (my recommendation on each)

0. **Settings and help-text standard (S3): yes.** This is your request, and it goes early because users see it on every settings screen. The one-line help plus "How it works" pattern is the convention Stripe, Linear, GitHub and Google Workspace settings use. Nothing in the full text is lost; it moves behind the disclosure.

1. **Land #802's `@custom-variant dark`: yes.** It makes every `dark:` utility follow the theme picker instead of the OS. That is app-wide, which is why it was held, but only 4 sites use `dark:`.
2. **On phones (below 640px), dialogs become bottom sheets and tap targets become 44px: yes.**
   - Done once, in `ui/dialog`, `ui/sheet` (a new `bottom` side) and `ui/button` (`min-h-touch` under `sm` only). Desktop density stays compact.
   - Reviewed by screenshot against the 2026-10-20 baseline. This closes the item §L deferred "until the screenshot baseline is reviewable".
3. **JetBrains Mono: drop it.** The code becomes `font-mono` = system mono plus `tabular-nums`; the docs are fixed to say so. Loading another font costs mobile LCP, which is already 6.5 to 7.9 s.
4. **Claude Design: not used.**

## Evidence checked before approval (read only, 2026-11-28)

| Question | Answer | Effect on the plan |
|---|---|---|
| Which roles can the role matrix sign in as? | `UserRole` has 7 roles. Seeded already: **super_admin** (`DatabaseSeeder`, which CI's `db:seed` runs) and tenant_admin, hr_admin and employee (`DemoTenantSeeder`). Not seeded: **finance_admin, dept_admin, supervisor**, all three offered by the Users screen (`settings/users/page.tsx:79-81`) | **Decided:** the missing 3 are created in the Playwright setup **through ETHR's own Users screen** (starting password, role, linked to a seeded employee and department so a department admin and a supervisor have people to see). No seeder changes, so it stays frontend only, and it tests the Users workflow on the way |
| Can CI make screenshot goldens? | Yes. `ux-audit.yml` already starts MariaDB 10.11, runs `db:seed` and the dev server on Linux, and uploads artefacts | A sibling `visual.yml` reuses those steps; nothing new is needed |
| How many long help texts? | 116 application keys over 120 characters (not 268, which included marketing) | Starting ceiling for `helpTextLength` |
| Hand-written error lines | 61 `<p className="… text-destructive">` | Starting ceiling for a `rawFieldError` ratchet → `FormField error` |
| Tinted boxes | 122 lines pair a `*-soft` background with a border or radius. This includes legitimate badges and pills, so the S4 script separates boxes from pills before setting the ceiling | Ceiling set in S4, not guessed here |
| What does "Allow check-in when offline" do? | The phone keeps a punch made with no connection and sends it later (`attendance/mobile/page.tsx:713`) | Help line written and verified |
| Is the 13-confirmation work real? | Yes. 20+ modified files on `fix/wig-confirmations` in `F:\ethr-wt-wig-inputs`, uncommitted and never pushed | S1 commits it; nothing is redone |

## Success measures (what "done" is, in numbers)

| Measure | Today | Target | Checked by |
|---|---|---|---|
| Application help texts over 120 characters | 116 | 0 | `helpTextLength` ratchet |
| Switches outside `SettingRow` on settings screens (`settings/*`, `admin/*`, `shifts`, device and branch rule cards; editor and row toggles excluded) | about 20 files, exact count in S0 | 0 | ratchet scoped to those paths |
| Local status maps, badges, field wrappers, tiles, pagers | 17, 9, 8, 8, 2 | 0 | ratchets and `component-inventory.test` |
| Text below 12px | 82 | 0 | existing ratchet |
| Open guideline findings (WIG register) | 46 | 0, or an owner decision on record | register status table |
| `ux-audit` phase=all | 288 passed / 0 failed | at least 288 / 0, with more routes | CI |
| axe critical and serious, on every audited page | 0 | 0 | `accessibility.spec` |
| WCAG 2.2 AA, 10 key journeys, keyboard and NVDA | not measured | pass, with each exception written down | S14 manual pass |
| Role matrix: wrong menu item or write control for a role | not measured | 0 | Playwright role matrix |
| Authenticated Lighthouse accessibility | 98 to 100 | at least 98 everywhere | `.lighthouserc.auth.cjs` |
| JS per route (compressed) | 528 to 567 KB | 15% less on the dashboard, employees and attendance pages, never more anywhere | route-size check |
| Public landing, mobile Lighthouse performance (`/en`, `/am`) | 73, 68 | at least 90 | `.lighthouserc.cjs`, raised to 0.9 |
| Public landing, mobile LCP and JS | 4.5 to 4.7 s, 352 KB | under 2.5 s, under 200 KB | Lighthouse, route-size check |
| Mobile LCP, authenticated | 6.5 to 7.9 s | lower than today. **2.5 s is not reachable frontend only:** the API time is part of it | Lighthouse, reported against §24 |
| Usability: task success, owner-run test | not measured | at least 80% success per task; time targets per task set from the first run, then held | the script's result sheet |

## Definition of Done for every PR

- Frontend only (`git diff --stat` shows nothing under `api/`); `generated.ts` unchanged.
- `gates.sh frontend` green after the collection count matches `find`.
- en, am and om keys present; `copy-style` passes; am and om queued for native read where the text changed.
- Uses the component kit; any new ratchet is at today's count and any lowered ratchet is lowered in the same PR.
- Screenshots at 375 and 1280px, light and dark, on every changed screen; console clean.
- Opus review findings fixed or answered; Sonnet implemented.
- Kiosk, punch and offline journeys run when a primitive changed.
- The rollback written in the PR description.

## Risk register

| Risk | Mitigation |
|---|---|
| A shortened sentence changes a rule about pay, law or privacy | The full text moves into "How it works", it is never deleted; the Opus review checks each rewrite against the code; owner sign-off for the payroll and photo rows |
| A primitive change breaks screens that were not looked at | Visual regression goldens, `ux-audit`, kiosk and punch journeys, one high-reach change per release |
| Another session changes the same files (busy `main`, shared checkout) | A worktree per slice, update-branch before merge, one PR in flight per file set |
| Amharic or Oromo becomes worse while English becomes shorter | Native read before the area ships; screenshots at 375px in am and om |
| The plan stalls halfway | The milestones are independent; M1 stands alone |
| A user loses a menu item they used | The navigation migration map, the role matrix and unchanged URLs; deep links from email tested |

## Fast path: shorter time, same accuracy (decided)

| Lever | What it saves | Why quality holds |
|---|---|---|
| **Codemods for the mechanical moves.** Status maps, field wrappers, `h1` classes, `text-[10px]`/`[11px]`, `...` → `…`, and the `Switch` rows that have no help yet. Each is done by a TypeScript transform script, the way #332 moved 79 error handlers in one pass | Days of hand edits in S4, S5 and S12 | One reviewable diff per move; a ratchet at 0 proves nothing was missed |
| **Docs edited inside each slice's PR**, not afterwards. S13 shrinks to a half-day sweep of what is left | A whole slice | Docs and code cannot drift, because they land together |
| **Visual comparison inside one CI run.** `visual.yml` builds `main` and the branch in the same job and diffs the screenshots. No committed goldens to maintain, and the same machine and fonts for both sides | Golden upkeep, and Windows-vs-Linux flakiness | Every primitive change is still compared screen by screen |
| **S0 reuses CI.** The `ux-audit` phase=all run already saves screenshots at 3 widths and 3 themes. Those are the baseline set; no separate local capture | About a day | It is the same evidence the 288/0 figure came from |
| **Parallel lanes.** M1 runs 4 lanes after S2 (S3, S15, SP report, S16a). M2 runs S4's component families as separate PRs wherever their files do not overlap | About half the wall-clock time | Each lane has its own worktree, ports and PR; no lane edits another's files |
| **Review sized to risk.** `/code-review` (the user CLAUDE.md's legitimate Opus step 3) on every PR. A full Opus review agent only where the risk is semantic: S2 (every page), S3 (pay and law copy), S15 (roles), SP (exposure) | Review time on mechanical PRs | Each PR still gets an Opus review; the deep reads go where a wrong change costs most |

**Revised effort, with the fast path:**
- **M1:** about 1.5 to 2 weeks.
- **M2:** about 2 weeks.
- **M3:** about 2 weeks.

These are still estimates; M1 measures them.

## Readiness check (verified 2026-11-28)

| Item the plan relies on | State |
|---|---|
| `SettingRow`, `FormField`, `EmptyState`, `ErrorState`, `StatCard`, `ui/alert`, `lib/status/registry.ts`, `save-status`, `CurrencyInput`, `etbToCents` (`lib/utils/currency.ts`), `useLocalStorage`, `LegalDocument`, `SolutionPage` and `SOLUTIONS` (`components/marketing/solutions.ts`), `og-card`, `og.png` route | **Present** |
| `npm run guide:capture` (`src/scripts/user-guide/capture.mjs`), `scripts/lighthouse-authenticated.sh`, `ux-audit.yml` (seeds MariaDB, saves screenshots), `ux-audit`, `offline` and `accessibility` specs | **Present** |
| `GET /api/v1/site-content` public, and the snapshot writer used twice already | **Present**; S16 needs no backend change |
| Roles for the role matrix | 4 seeded; 3 created through the Users screen, which offers them |
| The confirmations work | Present, uncommitted, in `F:\ethr-wt-wig-inputs` |
| `@radix-ui/react-radio-group` | **Missing. Decided: add it** (S3) |
| The preview pair `api-8011-node` | **Missing** (stale memory). Use the pairs that are in `.claude/launch.json` |
| Skills | `ui-ux-pro-max` on disk (outside Claude Code's skill path), `frontend-design` installed, WIG ruleset by URL, `Impeccable` not available and not needed |
| Model | This session is Opus, which is right for this plan. **Switch to Sonnet in the model picker before S1** |

## Order (decided, the same everywhere in this plan)

1. **S0**, the baseline.
2. **S1**, landing #801, #802 and the confirmations.
3. **S2**, the primitives, alone and in a release of its own. Every later slice builds on it.
4. **S3 (attendance and the settings hub), S15, the SP report and S16 (public website), in parallel**, each in its own worktree and session. None of them touches `ui/button`, `ui/dialog` or `ui/card`, and #802's edits to `sidebar-nav` and `mobile-bottom-nav` are already merged by then. S3 and S15 share no file, because S3 changes copy and settings rows while S15 changes the registry and the shell nav.
5. **M2:** S3 for the remaining areas, SP's fixes, S4, S5 (S5 waits for S4), S13.
6. **M3:** S6 to S12, then S14.

## What you do (the owner's part)

1. Approve this plan.
2. Read the am and om rewrites for each S3 area, or name a native reader.
3. Run the usability script with 5 people per role.
4. Sign in to Plesk for each deploy, as today.
5. The decisions below are pre-decided on my recommendation; say so only if you disagree.

## Milestones (stop after any one of them and the product is better and complete)

| Milestone | Slices | Effort | What users get |
|---|---|---|---|
| **M1: visible quality** | S0, S1, S2 (shipped alone), S3 for `/settings/attendance` and the settings hub, S15, the SP report, S16 | About 12 PRs, 2 to 3 weeks | Short, clear settings everywhere in Settings; phone-friendly dialogs and tap targets; a tidier menu; a fast public landing page with its content in the HTML; the three open fix PRs live; a list of what the browser exposes |
| **M2: one way to do each thing** | SP fixes, S3 for the remaining areas, S4, S5, S13, S16b (landing proof: screenshots, plan finder, security page) | About 10 PRs, 2 to 3 weeks | The same badge, callout, tile, pager and heading on every screen; no exposed data in the browser |
| **M3: standards closure** | S6 to S12, S14 | About 8 PRs, 2 to 3 weeks | The 46 registered guideline findings closed, WCAG 2.2 AA on the key journeys, performance held by ratchets |

Effort in this table is the plain estimate. With the *Fast path* below it falls to about 1.5 to 2 weeks for M1 and about 2 weeks each for M2 and M3. M2 and M3 are re-planned after M1 from its measured pace.

## The slices (one PR each, in order)

Each slice:
- is frontend only (`src/` and `docs/`);
- is built in a worktree off `origin/main`, because the checkout is shared;
- carries its tests, and lowers or adds a ratchet in the same PR when it removes a pattern;
- ships en, am and om keys with house copy style.

### S0. Baseline (no code)

Record in the saved doc:
- `gates.sh frontend` green;
- the Vitest file count;
- the ratchet ceilings;
- `ux-audit` phase=all (`gh workflow run ux-audit.yml -f phase=all`);
- `accessibility.spec`;
- the authenticated Lighthouse run (`scripts/lighthouse-authenticated.sh`, compared with BASELINE §24);
- the screenshot baseline: the `ux-audit` phase=all run's own artefacts (3 widths, 3 themes), downloaded to `F:/ethr-baselines/2026-11-28/`;
- the role matrix (which roles exist in the seeders, and what each sees);
- the per-route JS size of the static export;
- the ratchet scripts for the counts in *Evidence checked before approval*, committed so each ceiling is reproducible.

### S1. Land the open work

- **Confirmations.** Commit the uncommitted changes on `fix/wig-confirmations` (W-08, 13 sites), rebase onto main, open the PR.
- **#801 (shared inputs).** Rebase (27 behind) and land. W-06's "backend round-trip" test becomes a Vitest on `etbToCents`.
- **#802 (shell focus, theme, motion).** Rebase (20 behind) and land, after decision 1.
- **Merge order.** One at a time, because the ruleset requires up to date. Lockfiles are re-resolved, never line-merged (trap recorded on #802).

**Release rule for S2.** The phone bottom-sheet `Dialog` reaches 118 dialogs. It ships in a release with nothing else, after visual regression and the kiosk and punch journeys pass. The rollback is written down before Deploy.

### SP. Frontend privacy and exposure audit (Opus; report in M1 beside S3, fixes in M2)

Quality strand 2, below. First a report in `docs/audit/frontend/`, then one fix PR, then a Vitest that the sign-out path clears the query cache, IndexedDB and SW caches.

### S2. Primitives

Each is one file, and every page inherits it.

- **`ui/button`:** a `loading` prop (spinner plus `aria-busy`, disabled while pending), `touch-action: manipulation`, `min-h-touch` below `sm`.
- **`ui/card` `CardTitle`:** defaults to `h2` on `text-heading`, keeps the `as` prop, drops `leading-none`.
- **`EmptyState`:** heading level from a prop, defaulting to `h2` inside a page and `h3` inside a card.
- **`ui/dialog` and `ui/sheet`:**
  - below `sm`, `DialogContent` docks to the bottom: full width, rounded top, `max-h-[90dvh]`, `overscroll-contain`;
  - `Sheet` gains `side="bottom"`.
- **`ui/alert`:** variants cover every banner tone, plus `action` and `dismiss` slots.
- **`SimpleTable`:**
  - an `empty` slot (renders `EmptyState`);
  - `aria-sort` support;
  - `tabular-nums` on numeric columns;
  - the row `role="button"` replaced by a real link or button cell (W-16).
- **`QueryBoundary`:** its skeleton gets `role="status"`, and its private `DefaultEmpty` is replaced by `EmptyState`.
- **New ratchets:** `rawSpinner` (inline `Loader2 animate-spin` in a `<Button>`) and `rawButton` (raw `<button className=`), at today's counts.

### S3. Settings and help-text standard (the owner's priority)

**The problem, shown on `/settings/attendance`:**
- Every switch and number carries a paragraph. The page is long to scan and hard to compare.
- What a switch *does when on* is buried in the middle of a sentence.
- The same shape is drawn by hand in 28 of the 30 files that render a `Switch`. The shared `SettingRow` (`components/patterns/SettingRow.tsx`) exists, but only 2 files use it.
- **Measured exactly:** 565 application help keys (`_desc`, `_help`, `_hint`, `description`, `_note`, marketing excluded). **116 of them are over 120 characters and 26 over 180.** The worst is `attendance.settings_page` with 16; then devices, the backup console, activation codes, plans, SMS and tenant detail. Marketing has another 14, which are out of scope because they are page copy, not help.

**The standard every setting follows (international SaaS convention, house copy style):**

| Part | Rule | Example |
|---|---|---|
| Label | A noun phrase of 2 to 4 words, sentence case. No unit in brackets | Daily overtime limit |
| Unit | A suffix inside the input, not in the label | `120` min · `70` % · `100` m · `7` days · `4` s |
| One-line help | What happens **when it is on**, in the present tense, 12 words at most (about 90 characters in English). A switch's help starts with a verb | Refuses phone check-ins from outside the branch area. |
| Details (optional) | A closed **"How it works"** disclosure under the row, holding the law, the limits and the edge cases. Nothing is deleted; it moves out of the way | Proclamation 1156/2019 Art. 59; a third of basic pay at most |
| Effect tag (optional) | A small neutral badge when the setting changes records or pay | Writes attendance · Affects pay · Needs consent |
| Warning | Shown only when it applies, as an inline `Alert`, not as prose | Tell your staff in writing before you turn this on. |
| Choice of 3 or more | A segmented control showing the selected option's one-line effect, not one paragraph describing all of them | Off / Advisory / Required |
| Section intro | One sentence, two lines at most | Each punch gets a risk score. A score never refuses a punch. |

**Components.** These extend or reuse what exists, and add one small dependency (see the segmented control):
- **`SettingRow`** gains:
  - `details`, a native `<details>` disclosure (keyboard and screen-reader ready, no JavaScript);
  - `badge`;
  - `warning`, which renders `ui/alert`;
  - help text on `text-caption`, the title on `text-label`;
  - rows that stack below `sm`.
- **`SettingsSection`** (new, about 30 lines): a `Card` with an `h2` title, a one-line intro and `SettingRow` children. It shares the existing per-card save and `save-status`.
- **`InputWithUnit`** (new, about 25 lines): `ui/input` with a suffix, `inputMode="numeric"` and `aria-describedby` set to the unit.
- **Segmented control: `ui/radio-group` plus a `segmented` variant.**
  - **Verified 2026-11-28:** `@radix-ui/react-radio-group` is **not installed**. The WIG register says "both installed", which is wrong.
  - **Decided:** add it as a direct dependency. Its keyboard engine, `@radix-ui/react-roving-focus` 1.2.1, is already in the lockfile through `react-tabs`, so the added weight is one small package. It gives real radio semantics and arrow keys with no hand-written focus code.
  - It is styled with the `ui/tabs` `pill` look.
  - S7's 14 hand-built radio groups use the same component, so one component closes both.

**Worked rewrite of `/settings/attendance`** (English. The am and om dictionaries are rewritten to the same meaning and marked for the owner's native read):

| Setting | Help line (≤ 12 words) | Moved into "How it works" |
|---|---|---|
| Biometric device | Fingerprint or face terminals at the door. | |
| Phone check-in | From the employee's phone, with location and selfie. | |
| QR code | Scan a code at the branch entrance. | |
| Kiosk | A shared tablet; employees type their code. | |
| Computer | Check in from the dashboard on a computer. | |
| Manual entry | HR records the punch by hand. | |
| Spreadsheet import | Upload many punches from a spreadsheet. | |
| Daily overtime limit `min` | Days above this are flagged on the payroll run. | Also flagged: days over 4 hours and weeks over 12. All overtime is still paid |
| Overtime needs approval | Employees ask before working overtime. | Overtime past the approval is flagged, still paid |
| Biometric match threshold `%` | Matches below this go to review. | |
| Lateness deductions · *Affects pay* | Lets payroll deduct approved late or early minutes. | Off by default; approved by someone who processes payroll; a third of basic pay at most |
| Legal basis for deductions | The work rule, agreement or written consent that allows it. | Proclamation 1156/2019 Art. 59 |
| Reconcile before payroll (segmented) | **Off:** runs are approved as today. **Advisory:** the run lists attendance to settle. **Required:** a run waits until attendance is settled. | |
| Require geofence | Refuses phone check-ins from outside the branch area. | |
| Selfie with phone check-in · *Needs consent* | Takes a selfie with each phone check-in. | |
| Keep selfies for `days` | Then deleted; the attendance record stays. | HR and the manager get 48 hours to hold a photo for a complaint |
| GPS accuracy limit `m` | Less accurate phone punches go to review. | |
| Offline check-in | Phone punches made without internet are saved and sent later. | Verified: `attendance/mobile/page.tsx:713` saves on the phone only when `offline_sync_enabled` is not false. The registered-phone rule still applies |
| QR code lifetime `min` | | |
| Refresh QR automatically | Makes a new code when the old one expires. | |
| Scans per code | 0 means no limit. | |
| Require kiosk PIN | Employees type a PIN after their code. | Set each PIN on the profile, under Attendance |
| Show result for `s` | Then the kiosk clears for the next person. | |
| Kiosk sound | Confirms each punch, even after the person turns away. | Spoken in the kiosk's language, or tones when no voice exists |
| *Punch assistant* (intro) | Helps staff punch on time. Only reminders are on by default. | |
| Shift reminders | Notifies staff before the shift, when late, and at the end. | Each employee can turn them off |
| Auto check-out · *Writes attendance* | Closes open check-ins at shift end, flagged for review. | The employee is told and can file a correction |
| Check-in on arrival · *Writes attendance* | Checks in opted-in staff who open ETHR at their branch. | Up to an hour early; needs branch coordinates; never with required selfies |
| *Cheating protection* (intro) | Each punch gets a risk score. High scores go to Integrity. | A score never refuses a punch; only the registered phone rule can |
| Registered phone | Only each person's own phone can punch. | Start with Check and review while staff register |
| Office networks | Fixed office internet addresses for computer punches. Leave empty if none. | A web punch from them needs no phone and is marked for review |
| Review from risk score | Lower sends more punches to review. 40 is recommended. | A branch can set its own |
| Live face check | Asks for a blink or head turn before the selfie. | Runs on the phone; no face data leaves it; a failure goes to review |
| Adaptive selfies · *Needs consent* | Asks for selfies less often from staff with a clean record. | Applies only when selfies are not required for everyone. After 90 clean days, about one day in five; everyone else, every time |
| Presence checks | Asks checked-in staff, at random, to confirm they are at work. | 15 minutes to answer; never mid-shift; a pattern goes to Integrity. **Warning:** tell your staff in writing first |
| Work sites | Other places where staff may punch. | |
| Rules by branch | A branch follows these rules unless you change them. | Retention, confidence and overtime stay the same everywhere |
| Silent terminal alert | Tells admins when a terminal goes quiet during work hours. | At most once a day per terminal |
| Not connected after `min` | For terminals that call ETHR all the time. | The host scheduler can run 10 to 20 minutes late |
| No punches after `min` | For terminals heard only when someone punches. | Counted from the default shift's start |

**Accuracy rule.** Every rewrite is checked against the code path it describes, as part of the Opus review. A shorter sentence that changes the behaviour, the law or a number is a defect. The "Offline check-in" line is new copy, because it has no description today, and it was checked against the punch flow before being written.

**Rollout across ETHR, one PR per area, same components:**
1. `/settings/attendance`, the sample
2. the rest of the settings hub (`settings/page`, leave types, approval chains, webhooks, users, payroll, password policy, landing, showcase)
3. shifts, rotations, devices and device forms, branch rules
4. the platform console (`admin/*`: backups, SMS, plans, platform settings, tenant detail)
5. onboarding `setup2` and the employee forms
6. long help elsewhere: payroll banners, the activation code, `api_reference`, the 13 values over 250 characters

**Ratchet.** A new `helpTextLength` test in `design-ratchets.test.ts` counts application help keys (`_desc`, `_help`, `_hint`, `description`, `_note`, marketing excluded) over 120 characters in `en.json`. It starts at **116** and must reach 0. am and om are allowed 1.4 times the length, because Ethiopic and Oromo run longer. `copy-style.test.ts` already guards the house style.

### S4. Consolidate duplicates onto the canonical components

These are mechanical moves, each with a ratchet that ends at 0:
- the 17 status maps and 9 badges go into `registry.ts` domains (support, voice, recruitment stage, devices, integrity severity, webhooks, timesheet codes, mobile readiness);
- the 8 `Field` wrappers become `FormField`;
- the 8 local tiles become `StatCard`;
- the 15 banners and the hand-tinted boxes become `Alert` (the script first separates boxes from badges and pills);
- the 61 hand-written field error lines become `FormField`'s `error`;
- `DataTable`'s pager and the timesheet pager become `PaginationControls`;
- the about 37 hand-made empties become `EmptyState`;
- `feature-reason-dialog` becomes `ReasonDialog`;
- the attendance conflicts raw confirm becomes `useConfirm`;
- `ProgressRing`, `initialsFor` and the 5 hand-made initials go to `EmployeeAvatar` or are deleted.

### S5. Type and heading scale

- Apply `text-title` to every `h1`: `PageTitleBar`, the 4 detail pages, the 18 auth headings through one `AuthHeading`, and marketing heads on `text-display`.
- Apply `text-balance` in the base layer for `h1` and `h2` (W-39).
- Apply `text-numeric` and `font-mono tabular-nums` to amounts, codes and times through `CurrencyDisplay` and the table numeric column.
- The sub-12px ratchet goes from 82 towards 0 (the 51 `text-[10px]` and 30 `text-[11px]` become `text-caption`).
- New ratchet: `h1ClassVariants`, capped at today's count.

### S6 to S12. The remaining WIG register slices, in its order

The findings are not restated here; the register is the source.

| Slice | WIG PR | Scope | Frontend-only note |
|---|---|---|---|
| S6 | 4 | URL state: `useQueryTab` writes `?tab=`, `useQueryPage` on 7 lists | |
| S7 | 5 | Keyboard widgets and labels: 14 hand-built radio and tab groups move to S3's segmented control or Radix `Tabs`; 35 unlinked labels | |
| S8 | 6 | Live regions and first-error focus | |
| S9 | 7 | Formats and literals: `formatNumber(locale)`, 24 display formats, 12 literals | |
| S10 | 8 | Unsaved-changes guard (in-app navigation) and form metadata | |
| S11 | 9 | Charts: `isAnimationActive` from reduced motion through `chart-theme`, `role="img"`, searchable paginated `EmployeeSelect`, capped lists | Uses only the existing paginated endpoints |
| S12 | 11 | Copy (`...` to `…`, `copy-style.test` extended to `om`), `translate="no"` `<Token>`, hex fallbacks | WIG PR 12 (the public site) moved to S16, where W-11 is solved frontend only with the existing snapshot pattern |

Also close the three items still open from `docs/audit/QA-FRONTEND-2026-10-15.md`, in S9:
- F4: the payslip `period_label` is shown in English. Display the frontend's formatted period instead of the stored label.
- F5: the portal about section is unbalanced.
- F6: Settings shows a raw IANA time-zone name. Show a readable one.

Also enable `eslint-plugin-jsx-a11y` recommended, as warnings first, then raise to errors once the counts are at 0.

### S13. Docs reconciliation

Update `docs/DESIGN_SYSTEM.md` and the design-system section of `docs/CLAUDE.md` to match the code:
- `.dark`, `#4a9fd8`, 44px;
- what `--color-accent` means;
- system mono;
- the bottom-sheet and `Button loading` rows;
- `UndoToast` marked "not built";
- the new ratchets;
- a **"Writing settings"** section holding S3's standard, so the next screen follows it without this plan.

Also add a line to `FRONTEND_REDESIGN_PLAN.md` §L and a status table to the WIG register.

### S14. Final review

- Re-run the Web Design Guidelines ruleset over the changed files, as a delta against the 46 findings.
- Run a `ui-ux-pro-max` checklist pass: accessibility, touch, layout and typography only.
- Compare `ux-audit` phase=all, `accessibility.spec`, authenticated Lighthouse and the screenshot set with S0.
- Run the role matrix, the WCAG 2.2 manual and NVDA pass, and the performance ratchets as gates.
- Hand the owner the usability test script.

### S15. Information architecture and navigation consolidation (the owner forwarded a proposal; decided here)

**What exists today.** The proposal has to be weighed against this; it was measured, not assumed.
- **One registry.** `lib/navigation/registry.ts` (79 links) drives the sidebar, the settings nav and the command palette. Every row is shown only when the person holds the ability its page's API checks.
- **The tree was rebuilt on 2026-10-23** and is documented in `docs/CLAUDE.md` → *Sidebar Navigation Structure*. Tenant sections are Workspace, People, Operations, Finance, Insights, Communication and Admin, plus Pinned and Recent.
- **Already built:** collapsible groups that show the total of their counts when closed, `aria-current`, breadcrumbs in `PageTitleBar`, a searchable command palette (Ctrl+K), en, am and om labels, and a separate phone tab bar for super admins.
- **Platform console:** 11 flat rows in one section, each with a page on disk under `app/(dashboard)/admin/`.

**The verdict: keep the structure, fix four real defects, rename two vague labels.** Regrouping into the proposed nine function groups is rejected, for three reasons:
- **It moves every user's menu five weeks after the last move.** Familiar navigation is worth more than a tidier taxonomy, and the current tree is documented and tested.
- **Leave and Approvals would become one-row groups.** Each is a single page, so a group for each adds a click and depth. That contradicts the proposal's own "no unnecessary navigation depth".
- **Workspace is deliberate, and is the HR SaaS norm.** Workday, BambooHR and Rippling put the person's own items ("my day": dashboard, approvals, my attendance, leave, payslips) apart from the admin functions. Leave sits there because everyone uses it for their own requests.

**What S15 changes.** One frontend-only PR. No URL, route, API or permission changes.

| # | Change | Why |
|---|---|---|
| 1 | **Defect: phone tab bar duplicates the registry.** `mobile-bottom-nav.tsx` hard-codes its 5 tenant tabs and 5 super-admin tabs. They move to `registry.ts` as `MOBILE_TABS`, filtered by the same abilities | One definition per destination (proposal §5); today a renamed route can drift on phones only |
| 2 | **Defect: open groups are forgotten.** The sidebar's `expanded` set is component state, so it resets on reload. It is kept with the existing `useLocalStorage` hook, per user, and the active route's group still always opens | Proposal §6 "remembered group expansion" |
| 3 | **Platform console grouping.** The 11 flat rows get 4 headings inside the platform section. **Overview and tenants:** Overview, Tenants, Plans, Feature controls. **Operations:** Operations, Backups, SMS delivery. **Trust:** Audit, Support. **Public site and settings:** Marketing, Platform settings. The proposal's 8 groups would put 1 to 2 rows in each | Super-admin work stays separate from tenant HR, without depth |
| 4 | **Labels.** "Operations" becomes **Time & attendance**, and "Insights" becomes **Reports & analytics** (en, am, om). "Finance" stays **Payroll**, because the only entries are payroll runs, loans and cost sharing; grades and salary steps stay under Organization and do not move. "Admin" stays as it is (Settings and Billing, with Platform shown only to platform admins) | The industry's names for these groups; the routes behind them are unchanged |
| 5 | **Duplicate and misleading entries.** The inventory checks every registry row for two rows pointing at the same route, two different labels for one route (sidebar vs palette vs title bar `route-meta`), and settings destinations that also appear in the main tree. Contextual shortcuts on dashboards stay. Each finding is either fixed in the PR or listed with a reason | Proposal §5 |
| 6 | **Icons.** One lucide icon per destination, checked across sidebar, palette and phone tabs | Proposal §6 |

**Acceptance documents, written before the PR into `docs/audit/frontend/NAVIGATION-IA-2026-11-28.md`:**
- the route inventory (126 pages against the 79 registry links, including pages reachable only from inside a feature);
- the current tree and the tree after changes 1 to 6;
- the role-to-navigation matrix for the 7 roles: employee, supervisor, dept_admin, hr_admin, finance_admin, tenant_admin and super_admin, plus a custom role. It is read from the registry's ability checks, not from role names;
- the duplicate findings;
- the routes that must not move: every URL, the reserved slugs in `Tenant::RESERVED_SUBDOMAINS`, `RouteRedirect` pages and deep links from email.

**Tests:**
- a Vitest that every registry `href` has a `page.tsx` and a `route-meta` entry, and that `MOBILE_TABS` comes from the registry;
- the strand 1 role matrix (Playwright) for visibility and for direct URL access refused or allowed;
- 375px phone navigation, including the More sheet;
- am and om labels at 375px;
- the `docs/CLAUDE.md` sidebar section updated in the same PR.

**Model and order.** Opus reviews the role matrix, because role visibility touches permissions. Sonnet implements. S15 runs in parallel with S3, after S2 (see *Order*).

### S16. Public website: ethr.et landing and marketing pages (added on the owner's question)

**What exists.**
- **Pages.** 11 public pages under `app/(marketing)/[locale]/`: home, features, pricing, contact, FAQ, privacy, terms, and 4 search-intent solution pages that share one `SolutionPage`.
- **Code.** 4,551 lines in `components/marketing/landing/`, including the animated SVG stories in `hero-story.tsx` and `problem-stories.tsx`.
- **Content is data.** Sections and rows come from `MarketingSection`, edited in `/admin/marketing` and baked into the build through the committed `lib/marketing/content-snapshot.json`.
- **Recent work.** The page was redesigned on 2026-10-24 and 25 (#409 to #411, #424). Discoverability work is live: keyword pages, JSON-LD, hreflang, sitemaps (#314), and the one-robots-meta fix (#698).

**The verdict: no redesign.** The landing is a month old, database-driven and consistent with the product. What it lacks is **speed, pre-rendered content and a few standards fixes**. Speed matters for Google ranking and for phones on slow links. Unlike the signed-in app, the public site is fully static, so the targets here are **reachable with frontend work alone**.

| # | Change | Reuses | Today → target |
|---|---|---|---|
| 1 | **First paint without waiting for JavaScript.** The hero shows a static first frame of its story as plain SVG; the animation starts after the page is idle. The six problem stories load as separate chunks when scrolled near, through the existing `useRevealOnce`. Below-the-fold sections render without client JavaScript where they need none | `useRevealOnce`, `useStoryClock`, the existing reduced-motion static frame | Mobile Lighthouse `/en` 73, `/am` 68 → **at least 90**; LCP 4.5 to 4.7 s → **under 2.5 s**; landing JS 352 KB → **under 200 KB**; CLS under 0.1 |
| 2 | **Site content in the HTML** (WIG W-11). Contact email, phone and address, metrics and the testimonial are baked into the static export from a committed `site-content-snapshot.json`, written by a new `scripts/fetch-site-content-snapshot.mjs`, a copy of the shape of the existing `fetch-plans-snapshot.mjs` and `fetch-marketing-snapshot.mjs` on `scripts/lib/snapshot-writer.mjs`; the API only refreshes them. Verified: no backend change, because `GET /api/v1/site-content` is already public (`routes/api.php:244`) | `snapshot-writer.mjs`, the PLESK-GO-LIVE step 0 snapshot regeneration | The exported `en/contact/index.html` contains a `mailto:` link; no layout jump when the figures arrive |
| 3 | **Standards fixes** that were spread across the WIG register, gathered here: the skip link and `main` id on the public layout (W-10); `width` and `height` on the trusted-by logos (W-45); pricing and landing numbers formatted by page language, not a fixed `en-ET` (W-24); scroll padding under the sticky header (W-43); the contact form's success state announced (W-21). The dark-mode scenes (W-09) are already fixed by #802 | the house formatters, `ui/alert` | axe 0 critical or serious; LHCI accessibility at least 0.95 |
| 4 | **Copy.** The 14 marketing strings over 120 characters are shortened to the S3 standard (one idea per line, the claim first). Every claim stays inside what the product does today (capability audit 2026-10-17); the backend `PermittedMarketingClaim` check still guards console edits. Fallback text in en, am and om | S3 standard, `copy-style.test` | 14 → 0 |
| 5 | **Conversion clarity.** One primary call to action per section ("Start free trial" or "Talk to us", never both as primary); pricing shows what each plan includes in the same order on every card; the FAQ answers in one or two sentences with a link to more. Layout, sections and visuals are unchanged | `Button`, `Card`, `Badge` | Measured by the owner's usability script (one task: find the price for 50 employees and request a demo) |

**To reach 10/10 (decided).** A B2B buyer decides on the same few things everywhere: what it is, does it work, can I trust it, what does it cost, and how do I start. ETHR answers the first and the last well today. These close the rest. Every claim is checked against the code and the capability audit, and **nothing is invented**: where only the owner can supply something (a customer quote, a VAT line), the slot stays hidden until it is supplied.

| # | Addition | Why it earns the point | How (frontend only, reusing what exists) | Owner input |
|---|---|---|---|---|
| 6 | **"See it working": real product screenshots.** Four of them: the phone punch, an Amharic payslip, the attendance day, an approval | Buyers trust a real screen over an illustration; the drawn stories explain the problem, not the product | Captured from the **demo organisation's fake data**, never a customer's, by the existing `npm run guide:capture` script. Light and dark, WebP with `width` and `height`, lazy below the fold. Recaptured at each milestone, so they never show an old screen | None |
| 7 | **Plan finder on the pricing page.** "How many employees?" highlights the plan that fits and its monthly price in ETB | Price transparency; the three tiers are cut by employee count, so one number answers the question | Reads `lib/marketing/plans-snapshot.json` (`max_employees`, `price_cents`, `is_popular`); formatted through `formatETB`; about 40 lines | Whether prices include VAT. The current wording stays until the owner confirms, because TIN and VAT are already owed |
| 8 | **Security and data page** (`/{locale}/security`) | Organisations ask where payroll data lives before they sign | One page on the existing `LegalDocument` component. It states only what is true and verified: hosted in Ethiopia (Ethio Telecom), each organisation's data walled off from the others, two-step sign-in available on every account (`profile/security`) and required for platform admins, an audit log, nightly backups. **Off-site backup is not claimed**, because it does not exist yet. Added to the sitemap. No new top-level route, so no reserved slug | Sign-off on the text |
| 9 | **Social proof, real only.** The testimonial and metrics slots that exist but are empty are filled; "Trusted by" (8 organisations, consent-gated) stays | A named customer is the strongest trust signal for a new product | No code: the fields exist (`testimonial_*`, metrics in Platform settings → Public site), and the sections already hide themselves when empty | One quote, with written consent, from a live organisation (for example a woreda office), plus the real metrics |
| 10 | **Public-sector page.** A fifth solution page, "HR for government offices" | Woreda and regional offices are real customers, and government is one of the organisation types the product already supports | One more entry in the existing `SOLUTIONS` config for `SolutionPage`; the text is drawn from the 2026-11-16 government pitch and checked against capabilities | Sign-off |
| 11 | **A preview image per page.** A link shared on Telegram or LinkedIn shows that page's title, not the same card for every page | Most Ethiopian B2B links travel by Telegram | `og-card.tsx` rendered once per page at build time (static params), instead of the single `og.png` | None |
| 12 | **Amharic loads as fast as English.** On `/am` the text font is Noto Sans Ethiopic (about 198 KB), so the main text waits for it | Amharic is the default for many visitors | `display: swap` with a metric-matched fallback (`size-adjust`), so the text shows at once with no layout jump; the font is preloaded only on Ethiopic pages | None |
| 13 | **Measured, with no tracking.** No cookie and no consent banner, so no analytics script is added | Privacy is a selling point; adding a tracker would need a banner and a backend | Search Console and Bing (already set up) for search; sign-ups counted from the platform console's existing organisation list; recorded monthly in BASELINE | None |

**Scorecard: how "10/10" is checked, not asserted.**
- **Clarity:** 3 of 5 people in the owner's usability test say what ETHR does and who it is for after a 5-second look.
- **Speed:** mobile Lighthouse at least 90 for performance, accessibility, best practices and SEO, on `/en`, `/am` and `/om`; Core Web Vitals green.
- **Trust:** the security page live, and one real testimonial.
- **Price:** the plan finder answers "what does it cost for N employees" in one step.
- **Path:** every section has exactly one primary action.
- **Proof:** real screenshots on the home page.

**Split.**
- **S16a**, in M1: items 1 to 5 and 12.
- **S16b**, in M2: items 6 to 11 and 13, because they wait on owner inputs (a consented quote, sign-off, VAT).

**Out of scope, and why:**
- **The organisation portal sign-in (`ethr.et/{slug}`).** The owner's rule: it is not redesigned, and it changes only through shared primitives.
- **Marketing pictures and content rows.** These are data, edited in `/admin/marketing`.
- **New pages or SEO campaigns.** Off-site listings are the owner's work (`docs/operations/SEARCH-LISTINGS.md`).

**Gates.**
- `.lighthouserc.cjs` raises performance from 0.8 to 0.9 and accessibility from 0.9 to 0.95 once item 1 lands, so a regression turns CI red.
- A build test checks that the exported contact page carries the contact links.
- Screenshots of `/en`, `/am` and `/om` at 375 and 1280px, light and dark, in the visual-regression set.

**Order.** S16 runs as a **fourth parallel lane in M1**, beside S3, S15 and the SP report, after S2. It touches `components/marketing/` and `app/(marketing)/` only, which no other slice touches.
- **S16a** is 2 PRs: items 1, 2 and 12 first, then items 3 to 5.
- **S16b** is 2 PRs in M2: items 6, 7 and 11, then items 8 to 10 with the owner's text.

## Quality strands: the owner's ten added requirements, decided

All ten are frontend only. Where a strand would need a backend change, the table says so and that part is left out.

| # | Requirement | Decision | How, with what exists | Lands in |
|---|---|---|---|---|
| 1 | Role-based UX testing | **Yes** | A Playwright matrix signs in as each of the 7 roles: the 4 that are seeded, plus finance_admin, dept_admin and supervisor, created through the Users screen in the test setup (see *Evidence*). For each role it asserts three things: the sidebar items from `lib/navigation/registry.ts`, that each page opens or is refused, and that write controls are **hidden when the role cannot submit** (A-Z review C-11). A Vitest pins the registry's permission filter. No seeder is changed | S0 (measure), S14 (gate) |
| 2 | Frontend privacy and security audit | **Yes, Opus-owned** (security is Opus under the routing rule) | Authorisation is server-side, so this audit is about **exposure** in the browser: (a) the TanStack Query cache, IndexedDB offline queue and service-worker caches are cleared on sign-out and tenant switch, with no payroll or employee API response cached by the SW; (b) no PII in URLs (`useQueryFilters` `q`), in `console.*`, or in Sentry events (`beforeSend` scrubbing); (c) `autoComplete="off"` and masking on salary, bank and TIN fields; (d) print and export views show only what the screen shows; (e) secrets shown once are not left in the DOM. Report first, then fixes | Slice **SP**: report in M1, fixes in M2 |
| 3 | PWA and kiosk regression | **Yes** | The S2 primitives (Button, Dialog) are used by the punch and kiosk screens, so every slice that touches them runs `offline.spec`, the kiosk journeys and `gates.sh e2e-attendance`. After each deploy, `test:e2e:production:offline` | Per slice from S2, and after deploy |
| 4 | Localisation QA | **Yes, with an honest limit** | `copy-style.test` is extended to `om`; `amharic-layout.spec` gets screenshots at 375px in am and om to catch truncation and wrapping; the glossary tests are kept. **An agent cannot certify native quality**: the S3 rewrites in am and om go to the owner, or a native reader, for sign-off before the area ships. This is already owed for several features | S3 and S12 |
| 5 | WCAG 2.2 AA | **Yes** | axe tests catch only part of it. Added: a manual keyboard pass and an NVDA (free, Windows) screen-reader pass on 10 key journeys, and the 2.2 criteria checked by hand: 2.4.11 focus not hidden under the sticky header or tab bar (W-43), 2.5.7 dragging alternatives, 2.5.8 target size, 3.3.7 redundant entry, 3.3.8 accessible authentication (OTP paste, W-04). The target is AA conformance on those journeys, not a certificate | S14, and S2 and S7 for the fixes |
| 6 | Performance budgets | **Yes, as ratchets** | Today: mobile LCP 6.5 to 7.9 s, 528 to 567 KB of compressed JS on every route (BASELINE §24). A budget that fails today would be red forever, so `.lighthouserc.auth.cjs` gets assertions at today's figures, and a per-route JS-size check on the static export fails if a route grows. Both are lowered as slices land. Frontend levers: on-demand loading of leaflet, mediapipe, html5-qrcode and cmdk; no new dependencies. API latency is backend and out of scope | S0 (measure), S14 (gate) |
| 7 | Visual regression | **Yes, focused** | Golden screenshots of all 126 pages in 3 themes and 3 widths would be large, slow and flaky. Instead, about 20 key routes at 375 and 1280px, light and dark, with a frozen clock and seeded data, masking live regions. **`main` and the branch are built and compared in the same CI job**, so there are no committed goldens and no Windows-vs-Linux font drift. It is an opt-in workflow like `ux-audit`, used as the review of every primitive change | Per slice from S2 |
| 8 | Component governance | **Yes, without Storybook** | Storybook is a heavy new dependency to maintain. Instead: (a) the component kit table lives in `DESIGN_SYSTEM.md`; (b) the S3 and S4 ratchets hold every duplicate at 0; (c) a `component-inventory.test.ts` fails when a new export under `features/` or `components/` reuses a canonical name (`*StatusBadge`, `*EmptyState`, `StatCard`, `Field`, `*Banner`), in the style of the tenant-scope inventory; (d) the PR template asks "kit component used, or why not" | S4 and S13 |
| 9 | Real workflow usability testing | **Yes, run by the owner** | Real users cannot be simulated. I prepare a moderated test script: 5 tasks per role (punch in, request leave, approve, run payroll, change one attendance setting), in en and am, with a success, time and error sheet. Five users per role, per Nielsen. The owner runs it with QA Audit or Habru staff, and findings come back as slices. Before that, I do a cognitive walkthrough of the same tasks in the browser pane | Script with S3; results after S14 |
| 10 | Release and rollback protection | **Yes, mostly already in place** | Kept: gates, `release-status.sh`, `release.json`, the `production` branch. Added: one high-reach change per release (Dialog, `dark:`, Button are never shipped together); a written rollback for every release (revert PR → release → Pull and Deploy, or redeploy the previous `production` commit in Plesk Git); service-worker update checked after each deploy (the stale-cache fix #401). No new feature flags, because the flag registry is backend | Every release |

## Constraints held throughout

- **No file under `api/` is touched.**
  - `src/src/api/generated.ts` is never regenerated, because it comes from Scramble, which is backend.
  - No new endpoint is assumed; anything that would need one is deferred and listed.
- **Sign-in is not redesigned** (owner rule). Auth pages change only through shared primitives (heading, button, dialog), never through a per-tenant copy.
- **Never kill a process you did not start**, and never run concurrent Pest or gates in one worktree.

**Model routing (owner hard rule, `docs/MODEL-ROUTING.md`)**
- This plan is the Opus step.
- Sonnet implements each slice; switch the session to Sonnet in the model picker before S1.
- Opus reviews each diff through an Opus subagent or `/code-review`.
- Sonnet fixes only the verified findings.
- `gates.sh frontend` plus `ux-audit` and `e2e-attendance` (for the S2 dialog and button changes) run before any deploy.
- S12 copy and S13 docs stay on Sonnet with no cycle.
- S3's rewrites get the Opus review anyway, because a shortened sentence about pay or law can change what it promises.

**Release**
- Standing roadmap: local, then remote branch, then main, then the `production` branch, then Plesk Pull and Deploy.
- Every slice is frontend only, so release steps are Pull and Deploy with no migrate. The whole pulled range is still checked for other sessions' migrations before Deploy.

## Verification, per slice and at the end

- **`./scripts/gates.sh frontend`**, run after the collection count is checked against `find`. It covers i18n, Prettier, ESLint, tsc and Vitest, including `design-ratchets`, `semantic-color-tokens`, `copy-style` and `icon-button-names`.
- **Browser pane on the local preview.** One api and web pair from `.claude/launch.json` per worktree lane, for example `api-8010` plus `web-3010`, which the 2026-11-18 audit used. The demo organisation is `admin@demo.ethr.et`. A lane in its own worktree gets its own pair of ports so lanes never share a server. Checked at 375, 768 and 1280px in light, dark and high contrast. Changed routes are screenshotted against the S0 set, and the console must be clean.
- **`npm run test:e2e:a11y`, `test:e2e:responsive`, `test:e2e:theme` and `test:e2e:amharic`**, locally against the preview.
- **`ux-audit` phase=all in CI.** It must be at or above 288 passed and 0 failed.
- **For S3, per area:**
  - a before-and-after screenshot pair of the settings screen at 375 and 1280px in en and am;
  - "How it works" opens with the keyboard, and the screen reader reads the label and then the one-line help (`aria-describedby`);
  - the `helpTextLength` ceiling goes down in the same PR.
- **After each deploy:** `release.json`, a `/login` render with a clean console, and `npm run test:e2e:production:offline`.
