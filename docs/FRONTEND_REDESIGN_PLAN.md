# ETHR.et frontend transformation plan

Audit date: 2026-10-20. Planning only. Nothing in the application was changed to produce this document, and nothing here has been implemented.

**How to read this.** Every status below is one of Pass, Partial, Fail, Not Verified or N/A. A Pass cites a file and line, a test, or an observation made during this audit. Numbers are measured unless marked **(est.)** for a heuristic count or **(historical)** for a figure recorded in an earlier document. No user research, usability sessions, new accessibility audits or new performance runs were carried out for this plan; where a claim would need one, the status is Not Verified.

Paths are relative to the repository root. `src/src/` is shortened to `s/` and `api/app/` to `api/`.

---

## A. Executive assessment

### Scores

| Dimension | Score | Evidence |
|---|---|---|
| Visual design | 6 / 10 | A coherent teal and gold identity, zero raw palette classes (enforced by `s/test/semantic-color-tokens.test.ts`), but 346 arbitrary values (`text-[10px]` 68 times, `text-[11px]` 31) and no type or spacing tokens in `s/styles/globals.css` `@theme` (lines 17 to 165): the identity is applied by hand, not by system. |
| Usability | 5 / 10 | About 20 destructive actions run with no confirmation; 13 native `confirm()` calls, 6 in literal English (`s/app/(dashboard)/organization/page.tsx:246,531,769,957,1145,1340`); team leave rejection is one click with the hardcoded reason "Rejected" (`s/app/(dashboard)/leave/page.tsx:471-473`); the employee record's Edit button only works on the Info tab (`s/app/(dashboard)/employees/[id]/employee-detail.tsx:186`). |
| Accessibility | 7 / 10 | Radix primitives for every overlay, axe WCAG 2.x AA run at three viewports and three themes (`src/e2e/ux-audit.spec.ts:230-262`), reduced motion honoured (`globals.css:594,1125`), but the dialog Close label is literal English (`s/components/ui/dialog.tsx:48`) so every Amharic screen-reader user hears English, and about 20 routes are outside the axe sweep. |
| Responsiveness | 5 / 10 | No horizontal overflow on the nine dashboard pages measured at 375px today; a bottom tab bar exists; but 16 of 36 table files hide no columns on small screens, only three list views have a card layout, the Organization tab strip overflows at 375px (8 tabs, measured), and mobile public LCP is 4.3 to 5.0 s (historical). |
| Consistency | 4 / 10 | Three pager UIs and seven Previous/Next key families; two central status maps that disagree plus about 22 local maps; two date-formatting paths, one of which ignores the Ethiopian calendar; four overlapping error-extractor modules; three confirmation styles. |
| Maintainability | 4 / 10 | 35 `.tsx` files over 600 lines, 9 over 1,000; `organization/page.tsx` is 1,549 lines with six copy-pasted CRUD sections; 26 dashboard files handle loading but not errors; statement coverage 32.34% (historical, `docs/audit/BASELINE.md` §12f). |
| Performance evidence | 5 / 10 | Desktop public pages measure LCP 790 to 890 ms (historical, §18). Mobile public pages measure performance 68 to 79, LCP 4.3 to 5.0 s, TBT 434 to 568 ms (historical, §18, 2026-10-04). No authenticated page has ever been measured. |

### Five strongest aspects to preserve

1. **Fail-closed tenant isolation and one shared portal template.** `BelongsToTenant` yields no rows without context; every organisation's public page is `s/features/auth/components/portal-view.tsx:78-92` with the organisation's sections, never a per-tenant page. The owner's standing rule (one landing template, one login component) is in force and must survive the redesign.
2. **Enforced semantic tokens.** `s/test/semantic-color-tokens.test.ts` fails the build on any raw palette class; measured today: zero in application code. `s/test/design-token-contrast.test.ts` pins contrast of the token pairs.
3. **The `QueryBoundary` four-state contract** (`s/components/patterns/QueryBoundary.tsx`, 52 files) already exists; the problem is adoption, not design.
4. **Amharic parity and the Ethiopian calendar.** `en.json` and `am.json` both hold 4,665 keys with no gaps (`scripts/i18n-check.js` passes); `s/lib/calendar/ethiopian.ts` converts both ways and `useCalendar().formatDate` respects the person's choice; Ethiopic line-height 1.7 is set globally (`globals.css:585`).
5. **Single-choice libraries and the offline path.** lucide is the only icon set (208 importers, zero others), sonner the only toaster, recharts the only chart library, Radix the only overlay layer; the attendance queue in IndexedDB (`s/lib/offline-queue.ts`) with background sync in `src/public/sw.js` works and is tested (`src/e2e/offline.spec.ts`).

### Five highest-impact problems

Ranked by user impact multiplied by breadth.

1. **Failures look like empty data.** 26 dashboard files branch on `isLoading` but never on `isError` and use no `QueryBoundary`, so a failed request renders "nothing here" (for example `s/app/(dashboard)/organization/page.tsx:173`, `leave/page.tsx:225,366`, `payroll/payslips/page.tsx:19`, `vacancies/page.tsx:112`). 79 `onError: () => toast.error(...)` handlers take no error argument, so the server's reason is discarded. The dashboard charts ignore `isError` (`s/features/dashboard/components/interactive-charts.tsx:131-147`).
2. **Destructive actions without a guard.** Verified call sites with no confirmation: employee documents, bank details, emergency contacts and education deletes (`s/features/employees/components/{documents-tab:171, bank-details-tab:92, emergency-contacts-tab:71, education-tab:139}`), leave types (`settings/leave-types/page.tsx:174`), kiosks (`attendance/kiosks/page.tsx:140`), rotations (`shifts/rotations/page.tsx:173`), SMS gateway (`admin/sms/page.tsx:494`), plan retire (`admin/plans/page.tsx:423`), session revoke (`s/features/auth/components/active-sessions-card.tsx:56,82,254`), photo removal (`profile/page.tsx:365`), and the one-click leave rejection above.
3. **Dates ignore the calendar and the language.** `useDateFormatters` (`s/lib/hooks/useTenantTimezone.ts:44`, 42 files) is backed by `s/lib/utils/date.ts:94-122`, which is hardcoded to `en-GB` and the Gregorian calendar. Only `useCalendar().formatDate` (21 files) honours the Ethiopian calendar preference; about 36 files use the former alone **(est.)**. Leave requests print raw ISO strings (`leave/page.tsx:316,450`). This undermines the product's core differentiator.
4. **Phones.** Mobile public LCP 4.3 to 5.0 s and TBT 434 to 568 ms (historical), `/dashboard` first load about 472 KB carrying the whole `am.json` chunk (historical), 16 table files hide no columns, three card layouts in total. Field staff and supervisors on phones are the attendance users.
5. **Status semantics disagree.** `s/components/shared/status-badge.tsx` and `s/lib/utils/status-colors.ts` give different answers for suspended (warning vs error, `status-colors.ts:71`), cancelled (muted vs error, `:72`) and draft (muted vs info, `:75`); device status is mapped three times with different results (`devices/[id]/device-detail.tsx:83` vs `devices/page.tsx:635` and `devices/dashboard/page.tsx:300`); payroll `completed` (awaiting approval) and `approved` share one green (`status-badge.tsx:17,20`).

### Recommendation

**Upgrade, not redesign.** The architecture, token system, isolation model, i18n and test culture are sound and should be preserved. Four page groups need a workflow redesign (employees list, organization, leave and approvals, settings hub); the rest are upgrades on the existing structure. No framework migration, no new UI library, no rebuild.

Confidence: high on the counted findings (they were measured in code today and spot-checked by a second pass); medium on mobile and performance findings (historical measurements, 2026-09-17 and 2026-10-04, public pages only); low on anything about authenticated pages in production (see limitations).

### Audit limitations

- **Production authenticated screens were not inspected for this plan.** The admin console session had expired, and the brief forbids state-changing interaction. Authenticated pages were inspected on the local development stack (`localhost:3010`, test tenant `acme`, factory users) at 375px, read-only, plus screens observed earlier the same day on local development (dashboard, header, command palette) and production (tenant detail in the admin console, during unrelated work).
- **Production public pages were inspected in a 320px browser pane** (`/en`, `/en/pricing`, apex `/login`, `syntaxtech.ethr.et/login`, `syntax.ethr.et/login`, a 404 URL). Desktop rendering of production was not captured.
- **The repository is not the live build.** The checkout is `feat/header-actions` at `45479bbf4`, whose content matches `main` after the squash of PR #318 (`8a37664767`). Production serves release `e698c3d68bfa` (v2026.10.19.4, `release.json`), which includes PR #317 but **not** PR #318 (theme moved into the account menu, keyboard shortcuts sheet, translated role labels). Findings about the header reflect the repository; the live header still shows the theme icon.
- No usability sessions, no user interviews, no new Lighthouse or axe runs. Performance figures are quoted from `docs/audit/BASELINE.md` §18 with their dates.
- Chrome (the extension) disconnected during the audit; the built-in browser pane was used instead, which rendered at pane width only.

### Two production findings outside the redesign

Recorded as observed, with no action taken and no assumption about what the correct state is.

1. **A second tenant record, `syntax.ethr.et`, answers `403 tenant-inactive`.** The owner's organisation is `syntaxtech.ethr.et` ("Syntax Technology"), whose `tenant-context` answered 200 and whose portal renders normally. `GET https://syntax.ethr.et/api/v1/auth/tenant-context` returned `{"type":"https://ethr.et/errors/tenant-inactive","title":"Tenant Inactive","status":403}` at about 2026-10-20 08:10Z, and `https://syntax.ethr.et/login` renders the "Organisation not found" state. This is a tenant-status fact in production about a separate record (trial expiry, suspension or cancellation), not a frontend defect. It is recorded for the owner's awareness; this plan makes no assumption about whether that record should be active.
2. **The exported 404 page has no stylesheet.** `https://www.ethr.et/en/this-page-does-not-exist` returns `404.html` whose `<head>` carries scripts but no `.css` link, so the page renders in browser default styling. Cause, verified in code: `s/app/not-found.tsx` is the only page outside every route group, and the project has no root `app/layout.tsx` (each group carries its own `RootShell`), so the export never wraps it in the shell that imports `globals.css`. Listed as a Phase 1 quick win.

---

## B. Current frontend inventory

### Stack (verified in `src/package.json`)

Next.js ^16.4 (`output: "export"` when `ETHR_TARGET=shared-hosting`, else standalone; `s/lib/build-target.ts:34`), React ^19.3, TypeScript ^5.9, Tailwind ^4.3 (CSS-first, no `tailwind.config`), TanStack Query ^5.104 and Table ^8.21, react-hook-form ^7.80 with zod ^4.4, lucide-react, next-themes (class attribute, themes light, dark, high-contrast: `s/app/providers.tsx:68-72`), cmdk, recharts ^3.9, sonner, axios, Sentry, Radix (checkbox, dialog, dropdown-menu, popover, progress, select, slot, switch, tabs, tooltip). No date library, no animation library, no i18n library, no Ethiopian calendar package: all three are custom code. Node 24 (`.nvmrc`). Build for production: `node scripts/build-shared-hosting.mjs` (sets the target and runs the export verifier). Deploy: `release.yml` builds the `production` branch, Plesk Git pulls it; there is no Node runtime, no middleware and no SSR at run time on the host.

### Routes (103 `page.tsx`, verified)

| Group | Count | Notes |
|---|---|---|
| `(marketing)/[locale]` | 11 | home, features, pricing, faq, contact, privacy, terms, and four solution pages rendered by one `SolutionPage` component |
| `(root)` | 7 | unprefixed redirectors, `noindex` |
| `(auth)` | 11 | login, login/{find,forgot,mfa,otp,reset,sso}, register, impersonate/claim, news, news/[id] |
| `(dashboard)` | 72 | listed in the transformation matrix |
| standalone | 2 | `kiosk` (725 lines), `offline` |

Dashboard content is mostly written inline in `page.tsx`; the largest are `organization/page.tsx` 1,549, `admin/tenants/[id]/tenant-detail.tsx` 1,531, `components/layouts/sidebar-nav.tsx` 1,292, `reports/page.tsx` 1,284, `announcements/page.tsx` 1,282, `features/settings/components/landing-card.tsx` 1,205, `features/employees/components/disciplinary-cases-tab.tsx` 1,094, `admin/page.tsx` 1,024, `devices/[id]/device-detail.tsx` 1,007.

Routes requested by the brief that **do not exist**: a dedicated verify or invitation page (activation reuses `/login/reset`, `api/Notifications/AccountActivationNotification.php:46`); `settings/{organization,branding,landing,address,sso,notifications}` (these are tabs or cards inside `settings/page.tsx`); a failed-jobs page (a panel in `admin/page.tsx`).

### Layouts and shell

`s/app/(dashboard)/dashboard-shell.tsx`: AuthGuard, TenantBrandingProvider, CalendarProvider, ReverbProvider, AppLayoutProvider; skip link, CommandPalette, KeyboardShortcuts, PunchReminders, ImpersonationBanner, OfflineBanner; `AppSidebar` (desktop, collapsible, `lg+`), `AppHeader` (three-column grid with a centred global search since PR #317), `PageTitleBar` (breadcrumb and h1 from `s/lib/route-meta.ts`, 65 keys; `/settings/users` and `/settings/scim` have no entry and auto-label as "Users" and "Scim", untranslated), `MobileBottomNav` (`lg:hidden`: Dashboard, Attendance, Leave, Approvals or Payslips, More). `PageHeader` (`s/components/shared/page-header.tsx:18`) accepts `title` and `description` but uses only `actions`: 58 of 60 callers pass the ignored props, and 20 calls pass no actions and render nothing. Settings has its own left nav (`s/components/layouts/settings-nav.tsx`); `/attendance/settings` is listed there but lives outside the settings layout, so it renders without the nav.

### Shared components

`s/components/ui/`: 22 shadcn primitives. `s/components/patterns/`: `DataTable` (528 lines, sorting, selection, column visibility, CSV; one consumer, `employees/page.tsx:249`), `QueryBoundary`, `FormField` (27 files), `FormErrorSummary` (23), `PageSkeleton`, `SettingRow` (2). `s/components/shared/` (36 files): `SimpleTable` (44 instances in 35 files), `StatusBadge` (22 uses), `ConfirmDialog` (15 instances in 10 files), `EmptyState` (66 in 52), `SearchInput` (6), `PaginationControls` (12), `EmployeeAvatar` (9), `command-palette` (758 lines), `audit-log-explorer`, `dual-calendar-date-input`, `currency-display` and others. `s/components/dashboard/`: `StatCard`, `ChartCard`, `chart-theme`, `sparkline`.

### Roles and permissions

Role levels super_admin 100, tenant_admin 90, hr_admin 70, finance_admin 70, dept_admin 50, supervisor 30, employee 10 (`s/lib/hooks/usePermissions.ts`, matching `api/Enums/UserRole.php`). 34 `can.*` flags map to server abilities (for example `manageEmployees` = `employee.create`, `viewPayrollRuns` = `payroll.viewAll`, `approveLeave` = `leave.approve`, `viewAdminConsole` = `admin.manage`). `RoleGate` (49 files) renders an Access Denied screen (`data-testid="role-gate-denied"`). The sidebar config is inline in `sidebar-nav.tsx:196-566`, permission-filtered per item.

### Critical workflows (verified in code)

Sign in (apex with organisation field, or on the organisation's own host), MFA, one-time code, SSO; registration (three-step wizard); guided onboarding (industry config, migration, access, readiness); employee create (single page, 39 inputs measured at 375px, 2 required markers) and import; attendance check-in from web, phone (geofence, selfie, offline queue), QR, kiosk and devices; corrections and conflicts; leave request, team approval, approvals inbox; payroll run, review (completed → approved), void, exports; reports builder and schedules; analytics; settings; platform console (tenants, delete and restore since PR #316, plans, SMS, audit).

### Tests

234 Vitest files in `s/test/` (jsdom, MSW), 18 Playwright specs (`src/e2e/`: accessibility, admin, amharic-layout, attendance, attendance-conflicts, auth, cost-sharing, employees, kiosk, leave, marketing, mfa, offline, payroll, responsive, sign-in-flows, theme-switching, ux-audit). Guard tests: `semantic-color-tokens`, `hardcoded-english` (narrow: six renders and nine placeholder strings), `design-token-contrast`, `icon-button-names` (misses state-dependent icons, links and `sr-only` names), `nav-permissions`, `locales`. `ux-audit.spec.ts` runs axe WCAG 2.0/2.1 A and AA with colour contrast at 375, 768 and 1280 widths and three themes, and checks for horizontal scroll, the denied screen, console and network errors; it does **not** cover `/admin/plans`, `/admin/sms`, `/attendance/{conflicts,integrity,photos}`, `/payroll/cost-sharing`, `/settings/api-keys/reference`, `/vacancies`, `/voice`, `/voice/insights`, the six `login/*` sub-pages, `/impersonate/claim`, `/news`, `/kiosk`, `/offline`, the four solution pages, `/en/privacy`, `/en/terms`. Screenshots are saved as evidence only; **there is no visual regression comparison.** Lighthouse CI (`src/.lighthouserc.cjs`) covers seven public URLs; no authenticated URL.

### Unverified areas

Payroll detail, device detail, kiosk screen, voice, vacancies, news desk and the onboarding wizard were read in code but not rendered during this audit. Dark and high-contrast themes were not rendered today (covered historically by `ux-audit.spec`). Tablet width (768px) was not rendered today.

---

## C. Luxury SaaS UI/UX checklist

Statuses: Pass, Partial, Fail, Not Verified, N/A. Priority: P1 (user-facing harm or data risk), P2 (clear friction), P3 (polish).

### C.A Visual design and product identity

| Criterion | Status | Evidence | Required improvement | Priority | Acceptance test |
|---|---|---|---|---|---|
| Recognisable, original visual identity | Partial | Teal `#0F4C75` and gold `#E8A838` with the tibeb motif are specified (`docs/DESIGN_SYSTEM.md:186-193`) and the gold sidebar rail exists; gold is otherwise unused and the motif appears only in the sidebar footer and the portal divider. | Adopt the "quiet ledger" direction in section E: gold reserved for three uses, tibeb in two places, borders over shadows. | P2 | Visual baseline review signs off the token sheet and one page per group; no decorative gradient inside `(dashboard)`. |
| Typography and hierarchy consistent | Partial | Fonts are correct (Inter, Noto Sans Ethiopic, JetBrains Mono: `globals.css:17-31`); the documented type scale (`DESIGN_SYSTEM.md:115-126`) has no tokens in `@theme`; 99 `text-[10px]`/`text-[11px]` instances. | Add the seven type tokens (section E); remove sub-12px text; guard test on `text-[`. | P2 | `grep -c "text-\[1[01]px\]" src/src` returns 0 outside `components/ui`; new test passes. |
| Spacing, alignment and content widths follow rules | Partial | Spacing is Tailwind default; `DESIGN_SYSTEM.md:140-157` documents a 4px scale with no tokens; page padding is applied per page. | Add `--space-page`, `--space-section`, `--space-stack`, `--space-inline` tokens and a `data-density` attribute on the page container. | P3 | Every dashboard page root uses the page-padding utility; screenshot diff within threshold. |
| Colours have clear semantic purposes | Partial | Semantic tokens exist and are enforced, but three parallel families express the same meaning (`status-*` 236 uses, `*-soft` 168, `text-destructive`/`text-success` 270) and `<Toaster richColors>` (`s/app/providers.tsx:83`) uses sonner's palette. | Document one family per use (soft containers for badges and alerts, `status-*` for dots and lines); token-based toast variants. | P2 | Toaster renders with token classes; a lint rule or review checklist names the family per use. |
| Icons use one family and treatment | Pass | lucide-react is the only icon library (208 importers; zero react-icons, heroicons or fontawesome); zero emoji across 472 source and locale files. | None. | P3 | `semantic-color-tokens`-style grep for other icon packages stays at 0. |
| Borders, radii, shadows and elevation consistent | Partial | Radius and shadow tokens exist (`globals.css`, `--radius-sm` to `--radius-xl`, `--shadow-2xs` to `--shadow-2xl`); `backdrop-blur` on the header, bottom nav and `employees/new`; `shadow-2xl` on `portal-view`. | Elevation rule: level 0 border-only in content, 1 popovers, 2 dialogs, 3 palette and toasts; nothing else floats. | P3 | Grep for `shadow-2xl` outside overlays returns 0. |
| Decorative elements do not compete with data | Pass | Gradients are confined to auth, marketing and kiosk (`auth-layout-client.tsx:153`, `landing-content.tsx:152`, `kiosk/page.tsx:347`, `portal-view.tsx:210`); `glow` 0; `animate-pulse` only on four live-status dots. | Make the confinement a written rule. | P3 | Grep for `bg-gradient` under `app/(dashboard)` returns 0. |
| Primary and destructive actions distinguishable | Partial | Default Button for primary (`employees/page.tsx:192`, `leave/page.tsx:114`), destructive variant on void (`payroll-run-detail.tsx:636-674`), but the analytics page has no primary (`analytics/page.tsx:93-118`, measured: zero primary buttons) and the bulk employee status Confirm is non-destructive for terminations (`bulk-actions-bar.tsx:31`). | One primary per operational page; destructive tone on every irreversible action. | P2 | Checklist per page in the matrix; vitest asserts the destructive variant on terminate and reject. |
| Shared patterns consistent without identical pages | Partial | `PageTitleBar` and `EmptyState` give pages a common frame; `organization` and `settings/shifts` versus `shifts` diverge in pattern. | The consolidations in section F. | P2 | Matrix acceptance per page. |
| Tenant branding respects the core system | Partial | Only primary and accent are applied, as inline CSS variables (`s/features/branding/TenantBrandingProvider.tsx:33-40`); `secondary_color` is ignored; `aaCorrected()` checks label-on-fill only and branding applies in dark mode, overriding the lightened dark primary. Live: `syntaxtech.ethr.et` has no brand colour set and shows the default `--primary:#4a9fd8`, so brand overrides were not exercised in production during this audit. | Per-theme contrast check including text-on-surface; fall back to the ETHR default when the brand colour fails in a theme. | P2 | `branding-high-contrast` style test extended to dark passes for a dark brand colour. |

### C.B Navigation and information architecture

| Criterion | Status | Evidence | Required improvement | Priority | Acceptance test |
|---|---|---|---|---|---|
| Current organisation and page context identifiable | Pass | Sidebar shows the organisation name and the person's role; breadcrumb and h1 from `page-title-bar.tsx:65-131`; header shows the page label (`app-header.tsx:116`). | Translate the role label everywhere (done in PR #318, not yet live). | P3 | `app-header.test.tsx` passes; production shows the Amharic role after deploy. |
| Navigation labels understandable and consistent | Partial | Labels come from `nav.*` keys; two routes auto-label from the URL ("Users", "Scim") because `route-meta.ts` has no entry. | Add the two entries. | P3 | `route-meta` test asserts every dashboard route has a key. |
| Hierarchy reflects workflows and roles | Partial | Sections My Work, People, Operations, Finance, Admin are role-filtered (`sidebar-nav.tsx:196-566`); Attendance Rules sits outside the settings hub; `/shifts` and `/settings/shifts` both manage shifts. | Move Attendance Rules under settings; redirect `/shifts` to `/settings/shifts` (or the reverse) with an exported page redirect. | P2 | One shift route remains; settings nav shows Attendance Rules inside the layout. |
| Current location and active states clear | Pass | Gold 3px active rail in the sidebar (PR #216); bottom nav active state `text-interactive-primary` + `bg-primary-soft`. | None. | P3 | `nav-permissions.test` and `mobile-bottom-nav.test` pass. |
| Breadcrumbs where they help | Pass | `PageTitleBar` breadcrumb on every dashboard page except `/dashboard` and `/setup/guided` (`page-title-bar.tsx:22`); detail pages end with the entity title (`useEntityTitle`). | None. | P3 | `page-title-bar-entity.test` passes. |
| Important actions discoverable | Partial | Primary actions sit in the title bar via `PageHeader`; 20 pages pass no actions and have none; the employee record's Edit works only on the Info tab. | Per-page primary action audit in the matrix; fix the Edit scope. | P2 | Every list page in the matrix names its primary action and it renders. |
| Deep pages have predictable navigation | Partial | Only three pages sync tabs to the URL (`useQueryTab`: employee detail, leave, organization); reports, analytics, settings, profile and voice tabs use `defaultValue`. | Adopt `useQueryTab` on every tabbed page. | P2 | `?tab=` opens the named tab on each; vitest per page. |
| Empty and unavailable sections explained | Partial | `EmptyState` in 52 files; the plan-feature notice exists (`plan-feature-notice.tsx`); empty states on attendance records, leave and payroll runs have no call to action. | Add the next step to every empty state (filter reset, create, or "ask your administrator"). | P3 | Each empty state renders an action or an explanation sentence. |
| Responsive navigation practical | Pass | Bottom tab bar with safe-area inset and a More sheet (`mobile-bottom-nav.tsx:118`); sidebar hidden below `lg`; measured at 375px today: no page overflow. | Organization and employee-detail tab strips overflow at 375px (measured); add scroll affordance or an overflow menu. | P2 | `responsive.spec` asserts the tab strip is reachable at 375px. |
| Unauthorised routes behave appropriately | Pass | `RoleGate` denies with a screen (49 files); `ux-audit.spec` fails on the denied screen for the running role; payroll pages use `allowedRoles` and are denied to a custom role holding `payroll.viewAll` while the sidebar shows them (`payroll/page.tsx:83` vs `sidebar-nav`). | Gate payroll on `can.viewPayrollRuns` to match the sidebar. | P2 | A custom role with `payroll.viewAll` reaches `/payroll` in a vitest. |

### C.C Task efficiency and interaction design

| Criterion | Status | Evidence | Required improvement | Priority | Acceptance test |
|---|---|---|---|---|---|
| Each operational page has a clear primary action | Partial | Measured today: employees "Add Employee", leave "Apply for Leave", payroll "Run Payroll", announcements "New Announcement", reports "Run Preview"; analytics none; organization none in the title bar (inline per tab). | Analytics: make the period or export the primary; organization: per-tab primary in the title bar. | P2 | Matrix acceptance. |
| Common tasks avoid extra clicks and duplicate entry | Partial | Three check-in surfaces (`/attendance` header, `/attendance/mobile`, `/attendance/scan`); the migration step duplicates `/employees/import` with a raw textarea (`migration-step.tsx:204`); `/shifts` duplicates `/settings/shifts`. | Route, do not rebuild: one check-in entry that routes by device; migration step reuses the import flow. | P2 | Duplicate routes removed or redirected; e2e smoke on each. |
| Forms group related fields logically | Partial | `employees/new` groups by section with a sticky action bar (`new/page.tsx:741`) but presents 39 inputs on one page (measured); organization dialogs use fixed `grid-cols-2` at every width (`organization/page.tsx:328`). | Stepper for the employee form; responsive dialog grids. | P2 | Form sections render as steps; dialogs single-column under 640px. |
| Required fields and rules understandable | Partial | `FormField required` and a "Fields marked *" note on `employees/new:273`; measured 2 required markers for 39 inputs; organization uses a "Name *" suffix (`:329`) with labels not linked to inputs (`:1511`); leave request has no required markers and no end-before-start check (`leave/page.tsx:146-169`). | `FormField` with `required` on every form; zod schema per form. | P2 | Every form file imports `useZodForm` or `FormField`; vitest asserts markers. |
| Errors identify problems and guide recovery | Partial | `apiErrorMessage`/`apiErrorDetail` (`s/lib/api/error-message.ts`, 37 files) prefer the field error (audit N53); but four extractor modules coexist, 79 handlers discard the error, and `admin/sms/page.tsx:103` shows `detail` alone. | One extractor; a react-query `MutationCache` default that surfaces the server reason. | P1 | Unit test on problem+json shapes; grep for the retired modules returns 0. |
| Destructive operations confirmed | Fail | About 20 unguarded destructive actions (section A); 13 native `confirm()`; `ConfirmDialog` default label "Confirm" untranslated (`confirm-dialog.tsx:33`). | `useConfirm()` over `ConfirmDialog` and a `ReasonDialog`; grep guard on `window.confirm`. | P1 | Each listed action opens a dialog in vitest; `grep -c "confirm(" src/src/app` returns 0. |
| Unsaved changes handled predictably | Fail | `useUnsavedChangesWarning` has one consumer (`employees/new`) and handles `beforeunload` only; no dialog guards against closing with edits. | Guard on the long forms (employee new and edit, organisation dialogs, settings cards); in-app navigation guard. | P2 | Vitest: closing a dirty dialog asks first. |
| Search, filters, sorting, pagination consistent | Partial | One `PaginationControls` (12 files) beside seven hand-rolled pagers and `DataTable`'s own; seven key families for Previous/Next; employees list has search and sort but no status, department or branch filter (`employees/page.tsx:83-89`); approvals has no filters (measured: zero filter controls). | `FilterBar` and one pager. | P2 | `grep` for hand-rolled Previous/Next returns 0; employees list filters by status and department. |
| Loading states prevent uncertainty | Pass | 200 `Skeleton` instances, `PageSkeleton` on 23 `loading.tsx` files, `Loader2` on pending buttons. | None. | P3 | Existing tests. |
| Success feedback clear and timely | Partial | sonner toasts on most mutations; the team leave approve and reject give no toast (`leave/page.tsx:471`). | Toast on every mutation success. | P3 | Vitest on the leave team actions. |
| Keyboard users can complete critical workflows | Partial | Radix overlays trap focus; command palette and shortcuts sheet (PR #317, #318); the leave request dialog's labels are not tied to inputs; `focus:` without `-visible` in badge, dialog close (`dialog.tsx:46`), dropdown and select. | `htmlFor` everywhere; `focus-visible` in the four primitives. | P2 | axe `label` rule clean on the leave dialog; `grep "focus:ring" components/ui` returns 0. |
| Saving and sync failures communicated | Partial | Offline banner reports queued records (`offline-banner.tsx`) but has no `role` or `aria-live` and uses a "record(s)" plural hack (`:51-52`); `useOfflineSync` retries. | `role="status"`, proper plural keys. | P2 | Vitest asserts the live region. |

### C.D Data presentation and operational quality

| Criterion | Status | Evidence | Required improvement | Priority | Acceptance test |
|---|---|---|---|---|---|
| Tables readable and well structured | Partial | `SimpleTable` sets `scope="col"` and labelled row actions; 16 table files hide no columns on small screens; no sticky header. | `hideBelow` on every column set; `renderCard` for phones. | P2 | `responsive.spec` per table route at 375px. |
| Dates, numbers, currency and statuses formatted consistently | Fail | Two date paths (section A); `formatETB` (46 calls) beside `<CurrencyDisplay>` (25) which re-implements it, and eight hand-written `/100` formats (`admin/plans:139`, `organization:1116`, `grade-salary-steps-dialog:143`, `corrections:295`); raw ISO dates on leave. | One date hook that honours the calendar; one currency formatter. | P1 | Snapshot of a leave row in EC and GC; `grep "/ 100" src/src/app` returns 0. |
| Data-dense pages balance density and legibility | Partial | No density rule; `text-[10px]` used for density in 68 places. | Compact density tokens (36px rows, 12px cell padding) instead of sub-12px text. | P2 | Type floor test; table row height token used. |
| Large datasets paginated and filterable | Partial | Server pagination with `per_page` on lists; employees and approvals lack filters; `admin/page.tsx:78` fetches only page 1 of failed jobs. | `FilterBar`; paginate failed jobs. | P2 | Matrix acceptance. |
| Reports communicate filters and periods | Partial | The builder shows the chosen period; CSV headers are literal English (`payroll-run-detail.tsx:238-248`, `audit-log-explorer.tsx:95-99`); payslip `period_label` is English only (`docs/audit/QA-FRONTEND-2026-10-15.md` F4, open). | Translate export headers and period labels. | P2 | CSV header keys exist in `am.json`; i18n-check passes. |
| Charts have an operational purpose | Pass | Each chart is named by a question (present per day, headcount by department, present/late/absent per weekday: `interactive-charts.tsx:154,221,317`; analytics tabs similar); recharts only. | Charts must render `ErrorState` on failure (today they ignore `isError`). | P2 | Vitest: a failing query renders the error state, not the empty one. |
| Attendance exceptions and approval statuses understandable | Partial | `StatusBadge` maps present, late, absent, early_leave, on_leave; `holiday` has no style and falls to muted; the records filter omits pending, holiday and voided (`attendance/page.tsx:373-390`). | Status registry covering every API enum value. | P2 | Registry test enumerates `api/Enums/AttendanceStatus` and asserts a style for each. |
| Payroll review states clear | Fail | `completed` (awaiting approval) and `approved` share the same success style and dot (`status-badge.tsx:17,20,37-38`); no stepper on the run detail. | Distinct `completed` style (warning or info) and a review `Stepper`. | P1 | Vitest asserts different classes; `payroll.spec` sees the stepper. |
| Sensitive data not unnecessarily exposed | Pass | Tax ID and bank details are `encrypted` casts server-side and gated by `employee.viewFinancial`; the directory exposes name, work phone, email, position and department only; payslips are own-only for employees. | None. | P3 | Existing backend tests (`EmployeePortalTest`). |
| Draft, pending, approved, rejected, completed distinguishable | Partial | Distinguishable except completed vs approved (above) and draft (muted vs info disagreement). | Registry. | P1 | Registry test. |

### C.E Accessibility and responsive behaviour

| Criterion | Status | Evidence | Required improvement | Priority | Acceptance test |
|---|---|---|---|---|---|
| Evaluated against WCAG 2.2 AA | Partial | axe runs WCAG 2.0 and 2.1 A and AA (`ux-audit.spec.ts:256-262`), three viewports, three themes, with about 20 routes missing; no 2.2-specific checks (target size, focus appearance). | Extend the route list; add a target-size check on touch surfaces. | P2 | `ux-audit.spec` covers every dashboard route; a 24px minimum target assertion passes. |
| Contrast sufficient | Pass | `design-token-contrast.test.ts` pins token pairs; axe colour-contrast on; high-contrast theme and `prefers-contrast: more` (`globals.css:503`). | Tenant brand colours per theme (C.A). | P2 | Extended branding test. |
| Controls labelled with accessible semantics | Partial | About 108 named icon-only buttons (89 `aria-label`, 19 `sr-only`) **(est.)**; the dialog Close label is literal English (`dialog.tsx:48`) while `sheet.tsx:93` translates it; organisation dialog labels have no `htmlFor` (`organization/page.tsx:1511`). | Translate Close; `htmlFor` everywhere. | P1 | `hardcoded-english.test` renders the dialog with a sentinel dictionary. |
| Keyboard focus visible and navigable | Partial | Global focus ring in the base layer; 18 plain `focus:` usages remain in four primitives. | `focus-visible` in badge, dialog, dropdown-menu, select. | P2 | Grep returns 0. |
| Dialogs, drawers, dropdowns and menus keyboard-operable | Pass | All Radix (dialog, sheet, dropdown-menu, select, popover, tooltip, tabs, checkbox, switch); cmdk for the palette; verified today: palette arrows, Enter and Esc. | None. | P3 | `command-palette.test`, `keyboard-shortcuts.test` pass. |
| Errors not communicated by colour alone | Pass | `FormErrorSummary` lists messages (23 files); inline field text; status badges carry a label and a dot. | None. | P3 | Existing tests. |
| Motion respects reduced-motion | Pass | `globals.css:594` and `:1125` disable animation under `prefers-reduced-motion`. | None. | P3 | Existing CSS; keep. |
| Desktop, tablet and mobile layouts defined | Partial | Breakpoints are used per page; no written rule for tables and forms on narrow screens; tablet not rendered today. | Responsive rules in section F. | P2 | `responsive.spec` at 768px for the matrix pages. |
| Tables and complex forms have a narrow-screen strategy | Fail | 16 table files hide nothing; card layouts only on payroll runs, approvals and branches; `employees/new` is 39 inputs on one scroll at 375px. | `MobileRecordCard`; stepper. | P2 | Each matrix table route passes `responsive.spec` at 375px. |
| Touch interactions suitable | Partial | Bottom nav 64px tabs; measured today at 375px: 15 controls under 44px tall on `/employees`, 31 on `/employees/new`, 8 on `/organization`. | 44px touch targets on phone layouts via the density attribute. | P2 | Target-size assertion in `ux-audit.spec`. |
| Amharic and supported languages legible | Pass | Noto Sans Ethiopic self-hosted (`root-shell.tsx:40-45`), line-height 1.7 (`globals.css:585`), `amharic-layout.spec`. | None. | P3 | Existing spec. |
| Translated text does not clip or break | Partial | `amharic-layout.spec` exists; 22 prefix/suffix concatenations in 13 files break Amharic word order (for example `attendance/corrections/page.tsx:482-485`, `settings/roles/page.tsx:183`); 14 English placeholders remain in `am.json` (audit N37/N38, open). | Full-sentence keys with placeholders; replace the 14. | P2 | i18n-check plus a widened `hardcoded-english` rule rejecting `_prefix`/`_suffix` pairs. |

### C.F Security, trust and predictable behaviour

| Criterion | Status | Evidence | Required improvement | Priority | Acceptance test |
|---|---|---|---|---|---|
| Tenant and organisation context visible | Pass | Organisation name in the sidebar and (since PR #318) the account menu; the tenant-host indicator (`tenant-host-indicator.tsx`); impersonation banner. | None. | P3 | Existing tests. |
| Role-specific actions align with permissions | Partial | Server authorisation is the authority (Convention #7, inventory test); the UI mostly mirrors it, but payroll pages gate by role list while the sidebar gates by permission; leave team tab gates by `isSupervisor` rather than `can.approveLeave` (`leave/page.tsx:73`). | Align the two gates with the permission flags. | P2 | Vitest with a custom role. |
| Sensitive operations confirmed with context | Partial | Void asks for a reason (`payroll-run-detail.tsx:636-674`); tenant delete is typed and MFA-gated (PR #316); bulk terminate asks nothing (`bulk-actions-bar.tsx:31`); leave reject hardcodes the reason. | `ReasonDialog` on terminate and reject. | P1 | Vitest. |
| Cached UI state does not leak across tenants | Pass | `clearApiCache()` on logout and on failed refresh (`s/api/client.ts:156-205`); `sw.js` never caches `/api/v1/auth/` or `/api/v1/admin/`; the search recent list is keyed per user id (`recent-searches.ts`). | None. | P3 | Existing tests; `tenant-isolation` backend suite. |
| Expired sessions and API failures predictable | Partial | One shared refresh then redirect to `/login` (`client.ts:156-205`); but 26 files render failures as empty states. | `ErrorState` adoption. | P1 | Four-state vitest per page. |
| Employee and payroll data follow least privilege | Pass | Policies and `scopeAccessibleEmployees` on the server; the global search (PR #317) shows only rows the caller may open (`api/Http/Controllers/Api/V1/Search/GlobalSearchController.php`, tests in `tests/Feature/Search/GlobalSearchTest.php`). | None. | P3 | Existing tests. |
| Hiding controls is not treated as authorisation | Pass | Every write passes a Gate or Policy on the server (`ControllerAuthorizationInventoryTest`); `RoleGate` is a courtesy. | None. | P3 | Existing test. |
| Tenant branding does not create confusion | Partial | Brand colours only; the organisation name is prominent; the portal's first paint shows the generic "Sign in to ETHR" heading and swaps to "Sign in to Syntax Technology" once `tenant-context` answers (observed live on `syntaxtech.ethr.et/login`); on the inactive `syntax.ethr.et` the name slot showed "…" before the error state. | Server-rendered name where `PortalHead` already injects the title; otherwise a skeleton bar sized to the name slot. | P3 | Vitest on `portal-view` loading state. |

### C.G Public website and tenant conversion experience

| Criterion | Status | Evidence | Required improvement | Priority | Acceptance test |
|---|---|---|---|---|---|
| Purpose and value proposition clear | Pass | Live `/en` h1 "The Complete HR Platform for Ethiopian Organizations", keyword title (PR #314), capability section with business value per capability. | None. | P3 | `marketing-pages.test` passes. |
| Capabilities presented credibly | Partial | The product-flow hero on the live landing says "Fingerprint, QR or shared kiosk" (`en.json` key `marketing.flow.step_checkin_desc`), a biometric-hardware claim the 2026-10-17 capability audit forbids; everything else on the landing and the four solution pages was checked against the code in PR #314. | Reword to "Phone, QR code, kiosk and web". | P1 | `copy-style` style test or a solution-pages test asserts no `fingerprint|biometric` in `marketing.*` keys. |
| Calls to action clear and consistent | Pass | "Start Free Trial" and "Talk to Sales" / "Contact" on every public page (observed live). | None. | P3 | Existing tests. |
| Marketing hierarchy distinct from dashboards | Pass | Separate layout, 16px header, display-size headings, gradients allowed only here. | None. | P3 | Visual baseline. |
| Tenant landing pages communicate identity | Partial | One shared template with the organisation's own sections and colours (`portal-view.tsx`); the generic-heading first paint above; logo falls back to the ETHR mark when none is set. | Skeleton; logo prompt in settings. | P3 | Vitest. |
| Custom branding coherent within the product | Partial | See C.A; dark-mode contrast of brand colours unverified. | Per-theme check. | P2 | Extended branding test. |
| Mobile presentation, performance and accessibility considered | Partial | Mobile Lighthouse performance 68 to 79, LCP 4.3 to 5.0 s (historical); the 404 page is unstyled (observed live). | Fix the 404 wrap; mobile budget work in Phase 5. | P2 | Lighthouse mobile performance at least 85 **(target, est.)**; `404.html` links the stylesheet. |
| No fake metrics, testimonials or claims | Pass | Metrics and the testimonial render only when the operator has entered them, with a consent date required (`admin/platform-settings`, observed; `marketing-pages.test` "quotes no customer it does not have"); Trusted-by is consent-gated (PR #305). | Keep. | P3 | Existing tests. |

---

## D. Shared-code architecture checklist

Columns: Criterion, Status, Evidence, Affected components, Recommendation, Tests required, Measurable benefit.

### D.A Shared design tokens and foundations

| Criterion | Status | Evidence | Affected | Recommendation | Tests | Benefit |
|---|---|---|---|---|---|---|
| Colours, type, spacing, radii, borders, breakpoints have a source of truth | Partial | Colours, radius, shadows and easings: `globals.css` `@theme`. Type and spacing: documented in `DESIGN_SYSTEM.md` only, no tokens. Breakpoints: Tailwind defaults. `docs/CLAUDE.md` still describes `[data-theme]` while themes are class-based (`providers.tsx:68-72`). | `globals.css`, `DESIGN_SYSTEM.md`, `docs/CLAUDE.md` | Add type and spacing tokens; correct the two documents (a later doc PR, outside this plan's one-file rule). | `design-token-contrast` extended to the new text tokens. | 99 arbitrary text sizes removed; one place to tune density. |
| Repeated arbitrary values replaced by tokens where useful | Partial | 346 arbitrary values; the heaviest are primitives (`ui/dialog` 23, `sidebar-nav` 23, `ui/select` 19). | `components/ui/*`, `sidebar-nav.tsx`, `app-header.tsx` | Replace sizes with tokens; keep layout-specific grid templates. | Grep count gate: at most 60 outside `components/ui` **(target)**. | Fewer one-off sizes to reconcile in dark and high-contrast. |
| Layout primitives give consistent spacing | Fail | No `Page`, `Stack` or `Section` primitive; each page sets `space-y-6` and padding itself. | All dashboard pages | A `PageBody` wrapper reading `data-density`; no per-page padding. | Vitest on the wrapper; visual baseline. | One change moves every page. |
| Theme and tenant-branding variables centrally managed | Pass | `TenantBrandingProvider.tsx:33-40` is the only writer of brand variables; `useTheme` from next-themes. | `TenantBrandingProvider` | Add the per-theme contrast rule. | Extended branding test. | Keeps the boundary enforceable. |
| Components share interaction and status conventions | Fail | Two central status maps and about 22 local ones; three confirmation styles. | `status-badge.tsx`, `status-colors.ts`, 19 files with local maps | Status registry; `useConfirm`. | Registry test; grep guards. | One answer per status. |
| Token changes can be validated for regression | Fail | No visual regression; screenshots are saved and never compared. | `ux-audit.spec` | Playwright `toHaveScreenshot` baseline on about 15 routes, three themes, two viewports, kept outside the repository until Phase 1 agrees a storage location. | The baseline itself. | Token work becomes safe to ship. |

### D.B Reusable components

| Criterion | Status | Evidence | Affected | Recommendation | Tests | Benefit |
|---|---|---|---|---|---|---|
| Form controls use shared primitives | Partial | `FormField` in 27 files; raw `<textarea>` in `migration-step.tsx:204`; `useState` forms in about 56 files **(est.)**. | onboarding, organization, leave, settings cards | `useZodForm` + `FormField` as the only form pattern. | Vitest per migrated form. | Validation, markers and error summary for free. |
| Tables, filters, pagination, toolbars share patterns | Partial | `SimpleTable` 35 files, `DataTable` 1, `PaginationControls` 12, 7 hand-rolled pagers, `SearchInput` 6, no filter bar. | Listed in D.C | One table with `hideBelow` and `renderCard`; one pager; `FilterBar`. | Component vitest; `responsive.spec`. | 528 lines of `DataTable` retired after migration; one pager. |
| Dialogs, drawers, notifications, confirmations reused | Partial | Radix dialog in 62 files, sheet in 2; `ConfirmDialog` 10 files beside 13 `confirm()` and 8 ad-hoc dialogs. | Section A list | `useConfirm`, `ReasonDialog`. | Grep guard; vitest. | Amharic parity on every confirmation. |
| Status indicators use one semantic model | Fail | See D.A. | 19 files | Registry keyed by domain. | Registry enumerates API enums. | Payroll review states distinguishable. |
| Page headers, breadcrumbs, containers, action areas not duplicated | Partial | `PageTitleBar` is the single title; `PageHeader` is an actions portal with dead props; four hand-rolled h1 on detail pages. | `page-header.tsx`, 57 callers, 4 detail pages | Rename to `PageActions`, delete dead props, fix 20 empty calls; detail pages use `useEntityTitle` plus actions. | `tsc`; `page-title-bar-entity.test`. | Removes a misleading API. |
| Loading, error, empty states reusable | Partial | `QueryBoundary` 52 files; 37 hand-rolled branches, 26 without error. | 26 files | `ErrorState` and a required error slot. | Four-state vitest. | Silent failures end. |
| Accessibility behaviour implemented consistently | Partial | Radix everywhere; `focus:` leftovers; untranslated Close. | 4 primitives | Fix the four primitives once. | axe in `ux-audit.spec`. | Every overlay benefits. |
| Component props and variants have clear contracts | Partial | `StatusBadge`, `EmptyState`, `ConfirmDialog` are small and typed; `PageHeader` lies about its props; `SimpleTable` `colClassName` is positional. | `page-header.tsx`, `simple-table.tsx` | Named column config. | `tsc`. | Fewer positional mistakes. |
| Components do not accumulate unrelated responsibilities | Fail | `organization/page.tsx` (34 data hooks, 18 mutations, 6 dialogs, 5 tables), `tenant-detail.tsx` (15 hooks, 10 mutations, 8 dialogs), `sidebar-nav.tsx` 1,292 lines. | 9 files over 1,000 lines | `CrudSection`; split by section. | Vitest per section; e2e smoke. | 1,549 lines to about 300 **(est.)**. |

### D.C DRY without over-abstraction

| Criterion | Status | Evidence | Affected | Recommendation | Tests | Benefit |
|---|---|---|---|---|---|---|
| Genuine duplication identified with evidence | Pass | This document: six CRUD sections, three pagers, 22 status maps, four error extractors, two date paths, two currency formatters, two shift pages, two import paths. | As listed | Act on the counted items only. | n/a | Scope control. |
| Stable patterns reused | Partial | `QueryBoundary`, `EmptyState`, `StatCard` are reused; `analytics/chart-helpers.tsx:24,42` re-implements `ChartCard` and `KpiBox`. | `chart-helpers.tsx` | Delete the duplicates; use `components/dashboard`. | Vitest on analytics tabs. | One chart frame. |
| Abstractions improve readability | Partial | `KpiCard` is a thin alias of `StatCard` (`kpi-card.tsx:27-46`). | `kpi-card.tsx` | Keep the alias or remove it; not both names. | `tsc`. | Fewer names for one thing. |
| Unique page logic stays local | Pass | Kiosk, mobile check-in and the command palette keep their logic local. | n/a | Keep. | n/a | n/a |
| Visual similarity alone does not justify sharing | Pass | Branches use a card grid while other units use tables, on purpose. | n/a | Keep the distinction. | n/a | n/a |
| Established shared components not reimplemented | Fail | Local `StatusBadge` in `devices/page.tsx:625` and `webhook-deliveries-dialog.tsx:130`; hand-rolled avatar in `employee-detail.tsx:157`; `CurrencyDisplay` duplicates `formatETB`. | 4 files | Use the shared ones. | Grep for local `StatusBadge` returns 0. | One behaviour. |
| Large mixed-responsibility components identified | Pass | Section A and D.B. | n/a | n/a | n/a | n/a |
| Unnecessary wrappers and prop drilling avoided | Pass | 11 contexts in 6 files, no global store, `useSyncExternalStore` in 11 files. | n/a | Keep. | n/a | n/a |
| Installed UI capabilities not duplicated by new libraries | Pass | One of each library (section A). | n/a | Rule: no new UI, date, icon or chart library. | `package.json` review in PR template. | n/a |
| Shared interfaces small and documented | Partial | `DESIGN_SYSTEM.md` lists components; prop docs live in JSDoc. | `DESIGN_SYSTEM.md` | Update after Phase 3 (separate doc PR). | n/a | n/a |

### D.D Separation of concerns

| Criterion | Status | Evidence | Affected | Recommendation | Tests | Benefit |
|---|---|---|---|---|---|---|
| Page composition separated from presentation | Partial | 72 dashboard pages, most with inline content; feature components exist under `features/*/components`. | Large pages | Move sections into `features/*` as pages are touched; no big-bang move. | Existing tests. | Smaller diffs. |
| API communication follows conventions | Pass | 31 `features/*/api.ts`, about 140 `useQuery` and 139 `useMutation`; pages call no `useQuery` directly; only `mfa-form` and `sso-return` use `apiClient` directly. | n/a | Keep. | `admin-api.test` style tests. | n/a |
| Business rules not re-implemented in components | Partial | Payroll maths is server-side; hand-written `/100` currency maths in 8 places; leave validation (end before start) missing client-side. | 8 files, leave dialog | One formatter; zod schema. | Vitest. | n/a |
| Common validation rules reused | Partial | `useZodForm` schemas per form; no shared schema fragments (phone, email, ETB amount). | `s/lib/forms` | Shared zod fragments. | Unit tests. | Consistent messages. |
| Hooks and utilities have clear responsibilities | Partial | `useDateFormatters` hides a Gregorian-only formatter behind a tenant-timezone name. | `useTenantTimezone.ts`, `date.ts` | Wrap over `useCalendar`. | Snapshot tests. | Calendar correctness in 42 files. |
| Data transformations shared only when identical | Pass | Status registry is the one place where identical meaning is shared. | n/a | n/a | n/a | n/a |
| Permission-sensitive UI follows established patterns | Partial | `RoleGate` and `can.*` are the pattern; payroll and leave deviate (C.B, C.F). | 4 pages | Align. | Vitest. | n/a |
| Types reflect backend contracts | Pass | `s/api/generated.ts` (27,716 lines) from Scramble; `API contract` CI gate fails on drift. | n/a | Keep. | CI gate. | n/a |
| State at the appropriate level | Pass | Server state in react-query; UI state local; collapsed sidebar in `localStorage`. | n/a | Keep. | n/a | n/a |
| No global state where local or server state suffices | Pass | Zero global stores. | n/a | Keep. | n/a | n/a |
| Components avoid unnecessary requests | Partial | Query defaults `staleTime` 60 s, `retry` 1; the palette debounces 200 ms; notifications poll every 30 s without Reverb. | `notification-bell` | Acceptable; measure after the authenticated Lighthouse run. | n/a | n/a |
| Error and loading behaviour consistent without hiding context | Fail | See D.B. | 26 files | `ErrorState`. | Four-state vitest. | n/a |

### D.E Performance and dependencies

| Criterion | Status | Evidence | Affected | Recommendation | Tests | Benefit |
|---|---|---|---|---|---|---|
| Avoidable rerenders investigated where justified | Not Verified | No profiling evidence exists. | n/a | Profile the dashboard and employees list after the authenticated baseline. | React Profiler session, recorded. | n/a |
| Memoisation used where it helps | Not Verified | `useMemo` is used in nav and the palette; no measurement. | n/a | Measure before adding more. | n/a | n/a |
| Existing dependencies reused | Pass | One library per concern. | n/a | Keep. | n/a | n/a |
| Duplicate libraries avoided | Pass | Zero date, icon, chart, form, toast or modal duplicates. | n/a | Keep. | n/a | n/a |
| Large features loaded efficiently | Partial | `am.json` (377 KB raw) is statically imported into every app root (`amharic-dictionary.tsx`); public pages use a 7.5 KB projection; recharts loads with each analytics tab. | `amharic-dictionary.tsx`, analytics | Split the dictionary by namespace and route group; lazy-load recharts. | Bundle-size gate; i18n-check. | `/dashboard` first load below 350 KB **(target, est.)**. |
| Unnecessarily large bundles identified | Partial | Historical: `/en` about 352 KB, `/dashboard` about 472 KB gzipped (§18); Sentry about 87 KB. | as above | As above. | `analyze` script output recorded per release. | n/a |
| Data-heavy interactions responsive | Not Verified | Employee search measured server-side at about 13 ms for 5k rows (`api/Models/Employee.php` comment); no client measurement. | n/a | Measure with the baseline. | n/a | n/a |
| Caching and data-fetching patterns preserved | Pass | react-query defaults; `sw.js` network-first for pages and API. | n/a | Keep. | `offline.spec`. | n/a |
| Changes compatible with hosting constraints | Pass | Every recommendation here is client-side or build-time; no SSR, middleware or Node runtime; redirects as exported pages. | n/a | Keep the rule explicit. | `gates.sh export`. | n/a |
| Performance improvements measured, not assumed | Partial | Public pages measured (historical); authenticated pages never. | `.lighthouserc.cjs` | Authenticated Lighthouse via Playwright storage state against the local-production export. | Recorded in `BASELINE.md` (later doc PR). | n/a |

### D.F Testing and maintainability

| Criterion | Status | Evidence | Affected | Recommendation | Tests | Benefit |
|---|---|---|---|---|---|---|
| Shared components have unit or interaction coverage | Partial | 234 Vitest files; `QueryBoundary`, `StatusBadge`, `ConfirmDialog`, palette, header, bottom nav covered; `SimpleTable` and `PaginationControls` partially. | `s/test` | Add tests with each consolidation. | n/a | n/a |
| Critical workflows have functional coverage | Partial | 18 Playwright specs cover sign-in, MFA, employees, leave, payroll, attendance, kiosk, offline; approvals and organisation CRUD have none. | `src/e2e` | Add `approvals.spec` and `organization.spec` before redesigning those groups. | The specs. | Redesign safety. |
| Tenant- and permission-sensitive UI has regression coverage | Pass | `nav-permissions.test`, `platform-admin-nav.test`, `admin.spec`, backend isolation suites. | n/a | Keep; add a custom-role case for payroll. | Vitest. | n/a |
| Responsive and accessibility behaviour has a test strategy | Partial | `responsive.spec`, `accessibility.spec`, `ux-audit.spec`; 20 routes missing; no target-size check. | `ux-audit.spec` | Extend. | The spec. | n/a |
| Downstream usage reviewed before shared components change | Not Verified | No documented process; counts in this document are the first inventory. | n/a | PR template line: "consumers of changed shared components". | n/a | n/a |
| High-impact layouts have visual regression | Fail | None. | n/a | Phase 1 baseline. | The baseline. | n/a |
| Type checking and linting follow standards | Pass | `gates.sh quick` runs Prettier, ESLint, tsc, i18n; green today. | n/a | Add the `text-[` and `window.confirm` guards. | New tests. | n/a |
| Tests reflect real risk | Partial | Guard tests exist for the two historical defects (raw colours, hardcoded English) and are narrow. | `hardcoded-english.test` | Widen to pages, toasts and concatenations. | The test. | n/a |
| Shared-component conventions documented | Partial | `DESIGN_SYSTEM.md` and JSDoc; no consumer list. | docs | Update after Phase 3. | n/a | n/a |

### D.G Significant refactors (mandated format)

Effort: S under a day, M two to four days, L one to two weeks, XL more **(est.)**.

| # | Problem | Affected files | Approach | Benefit | Effort | Regression risk and dependencies | Tests | Migration sequence |
|---|---|---|---|---|---|---|---|---|
| 1 | 26 files render failures as empty states; 79 handlers discard errors | the 26 files listed in D.B evidence; `QueryBoundary.tsx`; `providers.tsx` | `ErrorState` component; `QueryBoundary` error slot required in types; react-query `MutationCache` `onError` default surfacing `apiErrorDetail` | Failures stop looking like empty data | M | Low; `tsc` drives every fix | Four-state vitest per page; MSW 500 fixtures | Component and cache default first; then pages by group |
| 2 | About 20 unconfirmed destructive actions; 13 `confirm()`; "Rejected" | section A list; `organization/page.tsx`; `leave/page.tsx` | `useConfirm()` hook over `ConfirmDialog`; `ReasonDialog`; grep guard on `window.confirm` | Data safety; Amharic parity | M | Low | Vitest per action; guard test | Hook, then organization, then the rest |
| 3 | 42 files bypass the calendar; raw ISO on leave | `useTenantTimezone.ts:44`, `date.ts`, 42 callers | `useDateFormatters` becomes a wrapper over `useCalendar().formatDate` so callers change behaviour without changing code; then deprecate the direct import | Ethiopian dates everywhere | S then M | Medium: visible format change on 42 pages; needs the visual baseline | EC and GC snapshots on leave, attendance, payroll rows | Wrapper; baseline review; deprecation lint |
| 4 | Two central status maps disagree; about 22 local maps | `status-badge.tsx`, `status-colors.ts`, 19 files | `lib/status/registry.ts` keyed by domain, consumed by `StatusBadge`; delete locals | One truth; payroll review distinguishable | M | Low | Registry test enumerating every API enum; vitest on `StatusBadge` | Registry; `StatusBadge`; delete locals |
| 5 | `organization/page.tsx` 1,549 lines, six copied CRUD sections | `organization/page.tsx` | `CrudSection` (list, add and edit dialog, delete confirm) with a per-unit hook; organization becomes composition | 1,549 to about 300 lines **(est.)**; testable units | L | Medium; depends on 2 and 1 | Vitest per section; `organization.spec` smoke | After 1 and 2 |
| 6 | `PageHeader` dead props; 20 empty renders | `page-header.tsx`, 57 callers | Rename to `PageActions`; remove `title` and `description`; fix or delete the 20 empty calls | Honest API | S | Low | `tsc`; `page-title-bar-entity.test` | Phase 3 first item |
| 7 | Three pagers; seven key families | 7 hand-rolled sites; `DataTable` | `PaginationControls` only; one `common.pagination.*` family | One behaviour, one translation | S | Low | i18n-check; vitest | Phase 3 |
| 8 | Four error extractors | `lib/api/error-message.ts`, `lib/errors.ts`, `lib/forms/apply-server-errors.ts`, two local `apiDetail` | Keep `error-message.ts` as the RFC-7807 reader; others re-export then are removed | One behaviour (N53 stays fixed) | M | Low | Unit tests on problem+json shapes | Phase 2 |
| 9 | 16 tables unusable on phones | the 16 files in the duplication audit | `SimpleTable` `hideBelow` per column plus `renderCard`; `MobileRecordCard` | Field staff can read lists | L | Medium; per-route visual change | `responsive.spec` per route | Component in Phase 3; rollout in Phase 6 |
| 10 | Payroll review has no stepper and indistinct states | `payroll/page.tsx`, `payroll-run-detail.tsx`, `status-badge.tsx` | `Stepper` (processing, completed, approved) and registry states | Money workflow legible | M | Medium (money); depends on 4 | `payroll.spec`; vitest | Phase 4 after 4 |
| 11 | `/dashboard` first load about 472 KB with `am.json` | `amharic-dictionary.tsx`, `translations.ts`, analytics | Split the dictionary by namespace per route group; lazy-load recharts | Faster first paint on phones | L | Medium: missing-key flashes | i18n-check; bundle gate; authenticated Lighthouse | Baseline in Phase 1; change in Phase 5 |
| 12 | Settings General has four save behaviours; shifts duplicated | `settings/page.tsx:135,212-223,588`, `settings/shifts`, `shifts` | One save contract per card (`SettingRow` autosave or explicit); one shift route with an exported redirect | Predictable saving | M | Low | Vitest on settings; e2e redirect | Phase 4 |

---

## E. Target visual direction

**Name:** the quiet ledger. ETHR is an operational record for payroll, attendance and leave. It should look precise, calm and trustworthy, not promotional.

**Identity.** Deep teal `#0F4C75` is the structural colour: sidebar, primary action, focus ring, links. Ethiopian gold `#E8A838` is reserved for exactly three uses: the tibeb motif, the active or selected indicator (sidebar rail, selected tab), and count badges (pending approvals). Gold never fills a button and never becomes body text. The tibeb motif appears in two places only, the sidebar footer and marketing section dividers, as `docs/CLAUDE.md` already specifies. Nothing else in the dashboard is decorative. This is distinctive because of restraint and the one cultural mark, not because of effects.

**Typography.** Inter for Latin, Noto Sans Ethiopic for Amharic, JetBrains Mono for codes, amounts and timestamps. Tokens to add to `@theme`, each with an explicit line-height (Amharic gets 1.6 to 1.8 through `:lang(am)`):

| Token | Size / line | Weight | Use |
|---|---|---|---|
| `--text-display` | 30 / 36 | 600 | marketing hero only |
| `--text-title` | 22 / 28 | 600 | `PageTitleBar` h1 (today `text-xl md:text-2xl`) |
| `--text-heading` | 17 / 24 | 600 | card and section titles |
| `--text-body` | 14 / 20 | 400 | default operational text |
| `--text-label` | 13 / 18 | 500 | form labels, table headers |
| `--text-caption` | 12 / 16 | 400 | metadata; the floor for any readable text |
| `--text-numeric` | 14 / 20, tabular | 400 mono | amounts, codes, times |

Rule: no text under 12px; `text-[10px]` and `text-[11px]` are replaced (99 instances). No weight above 600 is loaded.

**Density.** Operational pages (lists, attendance, payroll, settings) use compact density: 36px rows, 12px cell padding, 8px gaps, 32px controls. Marketing, auth and the kiosk use comfortable density: 44 to 48px targets, 24 to 32px rhythm. Density is a `data-density` attribute on the page container, not per-component props, and the phone layout raises every tap target to 44px regardless.

**Elevation.** Borders define structure; shadows mean "floating". Level 0 (border only) for every card in content; level 1 for popovers and dropdowns; level 2 for dialogs and drawers; level 3 for the command palette and toasts. Gradients stay confined to marketing, auth and the kiosk (current state, made a rule). `backdrop-blur` stays on the header and bottom nav only.

**Motion.** 150 ms `--ease-standard` for state changes, 250 ms `--ease-emphasized` for overlays entering and leaving, nothing above 300 ms, no motion on data refresh. Reduced motion stays honoured.

**Navigation and workspaces.** The shell is kept: sidebar on desktop, bottom tabs on phones, one title bar with breadcrumb, one global search (shipped). Each operational page has one primary action in the title bar, filters in a `FilterBar` under it, the list or form below, and nothing else above the fold.

**Tables and forms.** Compact tables with a sticky header, named columns that declare at which width they hide, and a card renderer on phones. Long forms become steps; dialogs are single-column under 640px; every field has a linked label, a required marker and inline error text.

**Remove.** `richColors` on the toaster (token variants instead); decorative backgrounds inside the dashboard; the generic heading on the portal's first paint; the biometric claim in the landing hero; sub-12px text.

**Practicality.** Ordinary office PCs at 1366 by 768 and low-end Android phones on slow links: 14px body, compact density by default, the Ethiopic font served once, locale dictionaries split, charts loaded on demand.

---

## F. Design-system specification

**Tokens to add.** The seven type tokens above; `--space-page` 24px (16px on phones), `--space-section` 32px, `--space-stack` 16px, `--space-inline` 8px; `--size-row-compact` 36px, `--size-row-comfortable` 48px, `--size-touch` 44px; status resolutions: draft = neutral, suspended = warning, cancelled = neutral, payroll completed = info (awaiting approval), approved = success.

**Semantic states.** One registry per domain (employee, leave, payroll, attendance, device, tenant, webhook) mapping every API enum value to a tone and an icon; `StatusBadge` reads it; a test enumerates the API enums and fails on a missing value.

**Component variants and composition rules.**

| Component | Decision | Note |
|---|---|---|
| `PageTitleBar` | Preserve | single title and breadcrumb |
| `PageHeader` | Refactor to `PageActions` | actions only, dead props removed |
| `SimpleTable` | Refactor | named columns with `hideBelow`, `renderCard`, sticky header |
| `DataTable` | Replace | retire after `employees/page.tsx` migrates |
| `PaginationControls` | Consolidate | the only pager |
| `StatusBadge` | Refactor | reads the registry |
| `ConfirmDialog` | Preserve, wrap | `useConfirm()`; translated default label |
| `EmptyState` | Preserve | add an action to each use |
| `QueryBoundary` | Refactor | error slot required; `ErrorState` default |
| `SearchInput` | Preserve | used inside `FilterBar` |
| `FormField`, `FormErrorSummary`, `useZodForm` | Preserve | the only form pattern |
| `StatCard`, `ChartCard` | Preserve | delete `analytics/chart-helpers` duplicates |
| `EmployeeAvatar` | Preserve | replace the hand-rolled avatar in employee detail |
| `Toaster` | Refactor | token variants, no `richColors` |
| `ErrorState` | Introduce | retry, reason, support hint |
| `MobileRecordCard` | Introduce | card renderer for 16 tables |
| `CrudSection` | Introduce | list, dialog, confirm, hook, for organization and similar |
| `Stepper` | Introduce | payroll review; employee create |
| `FilterBar` | Introduce | search, status, department, date range, URL-synced through the `useQueryTab` pattern |
| `ReasonDialog` | Introduce | reject, terminate, retire, void |

**Accessibility requirements.** Every control has a visible or `sr-only` name; every label is linked; `focus-visible` ring from the base layer; dialogs are Radix; the Close label is translated; live regions on the offline banner and sync results; 44px targets on phone layouts; no information by colour alone.

**Responsive behaviour.** Below `lg`: sidebar hidden, bottom tabs; below `md`: tables hide secondary columns; below `sm`: tables render cards, dialogs single-column, forms stacked; tab strips scroll with an affordance or collapse into a select.

**Tenant branding boundaries.** An organisation may set a logo, a primary colour and an accent colour. They colour the sidebar active indicator, the primary button fill, the portal mark and the portal hero. They never colour text on surfaces, status tokens, focus rings or charts. Contrast is checked per theme (light, dark, high-contrast); when the brand colour fails in a theme the ETHR default is used in that theme. Typography is not customisable. One shared portal template, one login component.

---

## G. Page-by-page transformation matrix

Decision: Preserve, Upgrade or Redesign. Effort is an estimate. Every row keeps its API calls and business logic unchanged; changes are presentation, validation and composition only.

| Page group | Strengths | Problems (evidence) | Target experience | Decision | Shared components | Responsive and a11y | Effort | Risk | Acceptance |
|---|---|---|---|---|---|---|---|---|---|
| Shell (sidebar, header, title bar, bottom nav) | Global search, account menu and shortcuts shipped (#317, #318); gold rail; bottom tabs | `PageHeader` dead props; two unlabelled routes; `sidebar-nav.tsx` 1,292 lines | Unchanged structure; honest `PageActions`; nav config split by section | Upgrade | `PageActions`, `route-meta` | Already responsive; keep | S | Low | `app-header`, `mobile-bottom-nav`, `nav-permissions` tests pass |
| Dashboard home | Persona sections gated inline; `StatCard` kit | Charts ignore `isError`; `department-drilldown-dialog.tsx` is dead code; no measured LCP | Same layout; `ErrorState` on widgets; dead code removed | Upgrade | `ErrorState`, `ChartCard` | Keep | S | Low | Four-state vitest on each widget |
| Employees list | `DataTable` with saved views and column visibility; primary action present | No status, department or branch filter (`employees/page.tsx:83-89`); bulk terminate without reason or date; 3 of 6 columns visible at 375px with no cards | `FilterBar`; `ReasonDialog` on terminate; card rows on phones; `SimpleTable` replacing `DataTable` | Redesign | `FilterBar`, `ReasonDialog`, `MobileRecordCard` | Cards at `sm`; 44px targets | L | Medium | `employees.spec` extended with filters and a reason on terminate |
| Employee detail | 12 tabs with `useZodForm` sub-forms; URL-synced tab | Edit only on Info tab (`employee-detail.tsx:186`); four sub-record deletes unconfirmed; hand-rolled avatar; any error reads "not found" (`:138`) | Edit scoped per tab; confirmations; `EmployeeAvatar`; distinct error state | Upgrade | `useConfirm`, `ErrorState` | Tab strip overflow affordance at 375px | M | Low | Vitest per tab delete; e2e edit on a non-Info tab |
| Employees / new | Sections, sticky bar, required note | 39 inputs on one page; 2 required markers; the only unsaved-changes guard | `Stepper` with per-step validation; unsaved guard kept | Upgrade | `Stepper`, `FormField` | Single column on phones | M | Low | `employees.spec` create flow |
| Employees / import | Flow with mapping and validation | 928 lines; no column hiding on the preview table; migration step duplicates it | Shared import flow used by onboarding | Upgrade | `SimpleTable` cards | Preview cards on phones | M | Low | Import e2e unchanged |
| Organization | Tabs for every unit; org chart | 1,549 lines; six copied CRUD sections; six English `confirm()`; no `isError`; labels unlinked; `grid-cols-2` dialogs; tab strip overflows at 375px (measured) | Six `CrudSection`s; one title-bar primary per tab; proper dialogs | Redesign | `CrudSection`, `useConfirm`, `useZodForm` | Dialogs single-column; tab select on phones | L | Medium | New `organization.spec` smoke; vitest per section |
| Attendance records | Filters, skeleton, error and empty states (`attendance/page.tsx:401-440`) | Personal punch buttons on the admin records view; no employee search; hand-rolled pager; filter omits three statuses; Gregorian-only dates | Records view without personal punches (those live on My Attendance); `FilterBar` with employee search; registry statuses | Upgrade | `FilterBar`, `PaginationControls`, registry | Cards at `sm` | M | Low | `attendance.spec` |
| Attendance mobile and kiosk | Geofence, selfie, offline queue, auto-reset kiosk; tested | 847 and 725 lines; three check-in entry points | Preserve flows; one entry that routes by device | Preserve | none new | Already phone-first | S | Low | `offline.spec`, `kiosk.spec` unchanged |
| Corrections and conflicts | Reject requires a reason; e2e covered | Local status maps; no gate on conflicts and integrity pages (server still gates) | Registry statuses; `RoleGate` for parity | Preserve | registry | Keep | S | Low | `attendance-conflicts.spec` |
| Leave and approvals | Primary action; balances as `StatCard`; approvals page already requires a reason on reject | Raw ISO dates; team tab reject is one click with "Rejected"; no toasts; dialog labels unlinked; approvals has no filters or bulk; gating by role not permission | Calendar-aware dates; `ReasonDialog` on the team tab (reuse the approvals pattern); `FilterBar` and bulk on approvals; `can.approveLeave` | Redesign | `ReasonDialog`, `FilterBar`, date wrapper | Cards exist on approvals; add to leave | M | Medium | `leave.spec` extended; new `approvals.spec` |
| Payroll runs and detail | Primary action gated on permission and plan; void asks a reason; exports | Completed vs approved indistinguishable; no stepper; hand-rolled pager; Cost Sharing missing from the sub-nav | `Stepper` (processing, awaiting approval, approved); registry; one pager; full sub-nav | Upgrade | `Stepper`, registry, `PaginationControls` | Mobile labels exist | M | Medium (money) | `payroll.spec` sees the stepper and distinct badges |
| Payslips, loans, cost-sharing | Role-gated; `cost-sharing.spec` | No `isError` on payslips; role-list gating denies custom roles | `ErrorState`; permission gating | Preserve | `ErrorState` | Keep | S | Low | Existing spec; vitest custom role |
| Reports | Builder, quick, saved, scheduled | 1,284 lines; English CSV headers; native `confirm()`; tabs not URL-synced | Split into builder, quick and schedules modules; translated headers; `useQueryTab` | Upgrade | `useConfirm`, `useQueryTab` | Keep | M | Low | Vitest; CSV header keys in `am.json` |
| Analytics | `QueryBoundary` on every tab; recharts | No primary action (measured); duplicate `ChartCard`; tabs not URL-synced | Period selector as the primary; shared chart kit; `useQueryTab` | Upgrade | `ChartCard`, `ErrorState` | Charts fit at 375px (measured: no overflow) | S | Low | Vitest on tabs |
| Settings hub and sub-pages | Left nav; `useZodForm` on most sub-pages; `SettingRow` exists | General page has four save behaviours (`settings/page.tsx:135,212-223,588`; measured today: two "Save" buttons visible); Attendance Rules outside the hub; two shift pages; leave-types delete unconfirmed; `landing-card.tsx` 1,205 lines | One save contract per card; Attendance Rules inside the hub; one shift route; confirmations | Redesign | `SettingRow`, `useConfirm`, `CrudSection` | Keep | L | Low | Vitest on save behaviour; e2e redirect |
| Admin console | Recently worked (#316), tested (`admin.spec`, seven vitest files); typed delete | `tenant-detail.tsx` 1,531 lines; failed jobs page 1 only; plan retire and SMS gateway remove unconfirmed | Split tenant detail into cards; confirmations; paginate failed jobs | Preserve | `useConfirm` | Keep | M | Low | Existing tests |
| Profile and self-service | `QueryBoundary` on profile pages; consent card | `announcements` 1,282 lines and mixes manager and reader views; `voice` 17 `useState`; no `isError` on payslips, notifications, announcements, vacancies, voice; photo removal unconfirmed | Reader and manager views split; `useZodForm`; `ErrorState` | Upgrade | `ErrorState`, `useZodForm`, `useConfirm` | Keep | M | Low | Four-state vitest |
| Auth and onboarding | Sign-in flows and MFA have e2e coverage; wizard steps use `QueryBoundary` | Auth forms use three form styles; MFA has no recovery path (API has none either); migration step duplicates import; commit and go-live unconfirmed | **Sign-in is out of scope by owner rule.** Onboarding: reuse the import flow; confirm commit and go-live; type tokens only | Preserve | `useConfirm` | Keep | S | Low | `sign-in-flows.spec`, `mfa.spec` unchanged |
| Marketing site | Keyword titles, solution pages, JSON-LD, consent-gated trust (#305, #314) | "Fingerprint" claim in the hero; unstyled 404; `landing-card.tsx` (portal settings) 1,205 lines; mobile LCP 4.3 to 5.0 s | Reword; wrap the 404; mobile budget | Upgrade | none | Already responsive | S then L | Low | Lighthouse mobile; `marketing-pages.test` |
| Tenant portal | One shared template; sections in the organisation's order; branding and favicon | Generic heading on first paint; dark-mode brand contrast unverified | Skeleton; per-theme contrast | Upgrade | none | Keep | S | Low | Vitest on loading; branding test |

---

## H. Production-safe implementation roadmap

Every phase ends with the repository gates green and nothing deployed until the release gates in section K pass. Validation happens on the local-production rehearsal (`scripts/local-production/up.sh`, Apache and MariaDB on `:8081`) and the dev stack, never on production.

### Phase 1: verify the audit, decisions and constraints (quick wins and baselines)

Scope: confirm this document against the code with the owner; create the measurement baselines that make everything after it safe.
- Authenticated Lighthouse (desktop and mobile) through Playwright storage state against the local-production static export, for `/dashboard`, `/employees`, `/attendance`, `/leave`, `/payroll`, `/settings`; record in `BASELINE.md`.
- Extend `ux-audit.spec.ts` with the 20 missing routes.
- Playwright screenshot baseline: about 15 routes, three themes, two viewports, stored outside the repository until a storage location is agreed (the brief forbids adding baselines to the repository in this phase).
- Quick wins (each its own PR): wrap `not-found.tsx` so the exported 404 carries the stylesheet; reword the hero claim; portal name skeleton; route-meta entries for `/settings/users` and `/settings/scim`.
Gates: `gates.sh quick`, `gates.sh frontend`, `gates.sh export`, `gates.sh e2e`. Rollback: additive only.

### Phase 2: design tokens and shared foundations (structural, the first implementation phase)

Scope: type, spacing, density and size tokens in `globals.css`; guard tests for `text-[` and `window.confirm` (initial failing counts recorded as the baseline, then driven to zero within the phase for `components/ui` and shared components, and to zero for pages by Phase 6); toaster off `richColors`; status registry and `StatusBadge` on it; `useDateFormatters` as a wrapper over `useCalendar`; one error extractor plus the `MutationCache` default; `useConfirm`; translated dialog Close and `ConfirmDialog` label.
Gates: `semantic-color-tokens`, `design-token-contrast`, `hardcoded-english`, i18n parity, the two new guards, screenshot diff reviewed route by route. Rollback: tokens are additive; wrappers are one-file reverts.

### Phase 3: reusable components and application layouts

Scope: `PageActions` rename; `SimpleTable` named columns, `hideBelow`, `renderCard`; `MobileRecordCard`; `ErrorState`; one pager; `FilterBar`; `ReasonDialog`; `CrudSection`; `Stepper`; `PageBody` density wrapper; delete `analytics/chart-helpers` duplicates. New components ship unused until a consumer migrates.
Gates: vitest per component covering all four `QueryBoundary` states where relevant; axe at three viewports. Rollback: per-component, consumers unchanged.

### Phase 4: navigation and the highest-value workflows

Scope: organization split into `CrudSection`s; employees list filters, cards and reason on terminate; leave team reject through `ReasonDialog`, calendar dates, toasts; approvals filters and bulk; payroll stepper and distinct states; settings General one save contract; Attendance Rules into the hub; one shift route with an exported redirect; permission-based gating on payroll and leave.
Gates: `employees.spec`, `leave.spec`, `payroll.spec`, new `approvals.spec` and `organization.spec`; MariaDB suite if any API contract is touched (none planned). Rollback: per PR; redirects reversible.

### Phase 5: remaining modules and role-specific experiences

Scope: reports split and translated exports; announcements reader and manager split; voice and vacancies to `useZodForm`; tenant-detail split; onboarding reuses the import flow and confirms commit and go-live; dictionary split and lazy charts (bundle budget).
Gates: as Phase 4 plus bundle-size gate and authenticated Lighthouse against the Phase 1 baseline.

### Phase 6: responsive, accessibility and localisation validation

Scope: card mode on all 16 tables; 44px targets on phone layouts; `focus-visible` in the four primitives; the 22 concatenations rewritten; 14 placeholders in `am.json` replaced; CSV headers and payslip period labels translated; `:lang(am)` line-height token; target-size assertion in `ux-audit.spec`.
Gates: `amharic-layout.spec`, `responsive.spec`, full `ux-audit.spec`, i18n-check.

### Phase 7: regression and release readiness

Scope: full `gates.sh`, `gates.sh mysql`, `gates.sh lighthouse` (public and authenticated), `gates.sh export`, screenshot sign-off, rehearsal on `:8081`, release notes and the rollback note (Plesk Git re-pull of the previous release commit, `docs/deployment/PLESK-GO-LIVE.md`), production smoke after deploy: apex sign-in page, one organisation portal, one tenant dashboard, `release-status.sh`.

### What must be tested in a non-production environment before any release

Every phase: the dev stack (`localhost:3010`) for interaction tests and the local-production rehearsal (`:8081`) for the exported build, including the `.htaccess` rules; all three themes; 375, 768 and 1280 widths; Amharic and English; at least one supervisor, one employee and one admin account; the offline queue; the kiosk screen; the organisation portal for a tenant with and without branding.

### The single highest-impact first implementation phase

**Phase 2 (tokens and shared foundations), with Phase 1's baselines as its prerequisite.** It touches `s/styles/globals.css`, `s/components/shared/status-badge.tsx`, a new `s/lib/status/registry.ts`, `s/lib/hooks/useTenantTimezone.ts`, `s/lib/api/error-message.ts`, `s/components/shared/confirm-dialog.tsx`, `s/components/ui/dialog.tsx` and `s/app/providers.tsx`. It fixes three of the five highest-impact problems (silent failures, unguarded actions via the hook, calendar-blind dates) with no visual redesign, and makes every later phase measurable.

Measurable quality gates that must be true before Phase 2 is authorised:
1. Authenticated Lighthouse figures recorded for the six routes above.
2. Screenshot baseline exists for about 15 routes, three themes, two viewports.
3. `ux-audit.spec` covers every dashboard route and is green.
4. `gates.sh quick`, `frontend` and `export` are green on `main`.
5. The two guard tests exist with their starting counts recorded.
6. A one-page token sheet (section E) is approved by the owner.

---

## I. Risk register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Production regression through a shared component change | Medium | High | Screenshot baseline before Phase 2; shared components change behind unchanged props first; one consumer per PR; old component kept until the last consumer migrates. |
| Broken routes in the static export | Low | High | `gates.sh export` on every PR; redirects implemented as exported pages, never middleware; `Tenant::RESERVED_SUBDOMAINS` reviewed for any new top-level route (`PathAndCustomDomainTenancyTest`). |
| API contract mismatch | Low | High | No API changes planned; `API contract` CI gate on drift; the status registry test enumerates API enums so a new value fails the build. |
| Shared-component coupling | Medium | Medium | Consumer inventory in this document; PR template line listing consumers; vitest per consumer. |
| Tenant isolation | Low | Critical | No change to scope logic; branding stays cosmetic; search and lists keep server-side scoping; backend isolation suites run in the full sweep. |
| Permission-sensitive UI | Medium | High | `RoleGate` unchanged; gating aligned to `can.*`; `nav-permissions` and `admin.spec`; a custom-role vitest for payroll. |
| Localisation failures | Medium | Medium | i18n-check on every PR; the Close fix early; widened `hardcoded-english` rejects concatenations; Amharic snapshots. |
| Accessibility regressions | Low | High | axe in `ux-audit.spec` every phase; the 12px floor; focus ring unchanged. |
| Performance regressions | Medium | Medium | Authenticated Lighthouse baseline; bundle budget on `/dashboard`; dictionary split measured before merge. |
| Hosting incompatibility | Low | Critical | Rule: no SSR, no middleware, no Node API at run time; rehearsal on `:8081` before each release; `.user.ini` limits are provider-locked (uploads over 2 MB fail), so no feature may depend on larger uploads. |
| Scope expansion | High | Medium | The matrix verdicts are the boundary; Preserve groups accept token, registry and confirmation changes only; sign-in is out of scope. |
| Historical measurements going stale | Medium | Low | Phase 1 re-measures before any claim of improvement. |

---

## J. Prioritised implementation backlog

P1 first. Effort is an estimate. Each task names its tests and a measurable completion criterion.

| # | Task | Priority | Files | Depends on | Effort | Risk | Tests | Done when |
|---|---|---|---|---|---|---|---|---|
| 1 | Wrap `not-found.tsx` in a shell that imports `globals.css` so the exported 404 is styled | P1 | `s/app/not-found.tsx` | none | S | Low | `verify-static-export` plus a check that `out/404.html` links a `.css` | Live 404 carries the stylesheet |
| 2 | Reword `marketing.flow.step_checkin_desc` (en, am) to drop the fingerprint claim | P1 | `en.json`, `am.json` | none | S | Low | `solution-pages.test` style assertion on `marketing.*` | No `fingerprint` or `biometric` in marketing keys |
| 3 | `ErrorState` and a required error slot in `QueryBoundary`; `MutationCache` default error toast | P1 | `QueryBoundary.tsx`, `providers.tsx`, new component | none | M | Low | Four-state vitest; MSW 500 | 26 files render an error, not an empty state |
| 4 | `useConfirm()` and `ReasonDialog`; translate dialog Close and `ConfirmDialog` default; guard test on `window.confirm` | P1 | `confirm-dialog.tsx`, `dialog.tsx`, new hook | none | M | Low | Vitest; guard | `grep -c "confirm(" src/src/app` is 0 |
| 5 | Confirmations on the 20 unguarded actions; leave reject through `ReasonDialog` | P1 | section A list | 4 | M | Low | Vitest per action | Every listed action opens a dialog |
| 6 | Status registry; `StatusBadge` on it; delete local maps; payroll completed distinct | P1 | `status-badge.tsx`, `status-colors.ts`, 19 files | none | M | Low | Registry enumerates API enums | One map; `grep -c "StatusBadge ="` outside shared is 0 |
| 7 | `useDateFormatters` wraps `useCalendar`; leave dates through it | P1 | `useTenantTimezone.ts`, `leave/page.tsx` | baseline | S | Medium | EC and GC snapshots | Leave rows show Ethiopian dates when selected |
| 8 | One error extractor; retire the other three | P1 | `lib/errors.ts`, `apply-server-errors.ts`, two local copies | none | M | Low | Unit tests | Imports of retired modules are 0 |
| 9 | Type, spacing, density and size tokens; `text-[` guard; remove sub-12px text in shared components | P2 | `globals.css`, `components/ui`, `components/shared` | baseline | M | Low | `design-token-contrast`; guard | 0 `text-[1[01]px]` outside pages |
| 10 | `PageActions` rename; fix 20 empty calls; route-meta entries | P2 | `page-header.tsx`, 57 callers, `route-meta.ts` | none | S | Low | `tsc`; title-bar tests | No `title`/`description` props remain |
| 11 | `SimpleTable` named columns, `hideBelow`, `renderCard`; `MobileRecordCard` | P2 | `simple-table.tsx`, new component | 9 | M | Medium | `responsive.spec` | Component tests pass; first consumer migrated |
| 12 | One pager and one key family | P2 | 7 sites, `DataTable` | none | S | Low | i18n-check | Hand-rolled Previous/Next is 0 |
| 13 | `FilterBar`; employees list filters and cards; `ReasonDialog` on bulk terminate | P2 | `employees/page.tsx`, `bulk-actions-bar.tsx` | 4, 11 | L | Medium | `employees.spec` | Filters by status and department; terminate asks a reason |
| 14 | Approvals filters and bulk actions | P2 | `approvals/page.tsx` | 13 | M | Medium | new `approvals.spec` | Filter by type; select many; one reason per batch |
| 15 | `CrudSection`; organization split | P2 | `organization/page.tsx` | 3, 4 | L | Medium | new `organization.spec`; vitest per section | File under 400 lines **(target)**; no `confirm()` |
| 16 | Payroll `Stepper` and distinct states; full sub-nav | P2 | `payroll/*` | 6 | M | Medium | `payroll.spec` | Stepper visible; completed and approved differ |
| 17 | Settings General one save contract; Attendance Rules into the hub; one shift route | P2 | `settings/page.tsx`, `settings-nav.tsx`, `shifts/*` | 4 | M | Low | Vitest; e2e redirect | One Save behaviour per card; one shift route |
| 18 | Employee detail Edit per tab; `EmployeeAvatar`; `ErrorState` | P2 | `employee-detail.tsx` | 3 | S | Low | Vitest | Edit works on every tab |
| 19 | `employees/new` `Stepper` | P2 | `employees/new/page.tsx` | 11 | M | Low | `employees.spec` | Steps with per-step validation |
| 20 | Permission gating on payroll and leave | P2 | `payroll/*`, `leave/page.tsx` | none | S | Low | Vitest with a custom role | Custom role with `payroll.viewAll` reaches payroll |
| 21 | `useQueryTab` on reports, analytics, settings, profile, voice | P2 | those pages | none | S | Low | Vitest | `?tab=` opens the tab |
| 22 | Translate CSV headers and payslip period label | P2 | `payroll-run-detail.tsx`, `audit-log-explorer.tsx`, payslip resource | none | S | Low | i18n-check | Keys exist in `am.json` |
| 23 | Rewrite the 22 concatenations; replace 14 English placeholders in `am.json` | P2 | 13 files, `am.json` | none | M | Low | widened `hardcoded-english` | 0 `_prefix`/`_suffix` pairs |
| 24 | `focus-visible` in badge, dialog, dropdown-menu, select; offline banner live region | P2 | 4 primitives, `offline-banner.tsx` | none | S | Low | axe; vitest | `grep "focus:ring" components/ui` is 0 |
| 25 | Tenant branding per-theme contrast with fallback | P2 | `TenantBrandingProvider.tsx` | none | S | Low | extended branding test | Dark brand colour passes AA on dark surfaces or falls back |
| 26 | Portal name on first paint (server-injected or skeleton) | P3 | `portal-view.tsx`, `api/Support/PortalHead.php` | none | S | Low | Vitest | The organisation name is shown or skeletoned, never the generic ETHR heading |
| 27 | Reports split; announcements split; voice and vacancies to `useZodForm`; tenant-detail split | P3 | those files | 3, 4 | L | Low | Existing specs | Each file under 600 lines **(target)** |
| 28 | Dictionary split by namespace; lazy recharts; bundle gate | P3 | `amharic-dictionary.tsx`, `translations.ts`, analytics | baseline | L | Medium | i18n-check; bundle gate; authenticated Lighthouse | `/dashboard` first load under 350 KB **(target, est.)** |
| 29 | Toaster token variants | P3 | `providers.tsx`, `globals.css` | 9 | S | Low | Vitest | No `richColors` |
| 30 | Extend `ux-audit.spec` routes; target-size assertion | P3 | `ux-audit.spec.ts` | none | S | Low | the spec | Every dashboard route listed |

---

## K. Definition of Done and release gates

A phase is done when all of the following hold, with evidence attached to the PR or release note:

1. **No critical business workflow is broken.** `sign-in-flows`, `mfa`, `employees`, `attendance`, `leave`, `payroll`, `kiosk` and `offline` Playwright specs pass on desktop Chromium against the local-production rehearsal.
2. **Shared components follow the documented conventions.** Each changed shared component has a vitest covering its states; its consumers are listed in the PR; `DESIGN_SYSTEM.md` is updated in a follow-up docs PR.
3. **High-priority UX and accessibility issues resolved or formally accepted.** Every P1 item in section J is closed or carries a written owner decision.
4. **Supported viewports tested.** 375, 768 and 1280 widths in `ux-audit.spec` and `responsive.spec`, three themes, Amharic and English.
5. **Tenant isolation and permission boundaries unchanged.** Backend isolation and authorisation inventories pass; `nav-permissions`, `platform-admin-nav` and `admin.spec` pass; no `withoutGlobalScope` added.
6. **Backend behaviour, API contracts, authentication and business logic intact.** `API contract` gate shows no drift unless a PR deliberately regenerates `generated.ts`; `gates.sh mysql` green at release.
7. **Automated and manual regression pass.** `./scripts/gates.sh` (full sweep) green; the manual checklist in section H run on `:8081`.
8. **Visual and performance claims have evidence.** Screenshot diffs reviewed and attached; Lighthouse public and authenticated figures attached and compared with the Phase 1 baseline; no claim of improvement without a before and after.
9. **No unnecessary dependencies or duplicate frameworks.** `package.json` diff reviewed; one library per concern remains.
10. **Validated in a non-production environment.** Dev stack and local-production rehearsal, as section H describes, before the release build.
11. **Safe release, rollback and verification.** Release through `release.yml` and Plesk Git; release steps (`clear`, `optimize`) only when routes change, with a letters-and-digits maintenance token emptied afterwards; rollback is a Plesk Git pull of the previous `release:` commit; post-deploy smoke: `release-status.sh`, apex sign-in page, one organisation portal, one dashboard in Amharic.

---

## Closing

Highest-impact first phase: **Phase 2, tokens and shared foundations**, authorised only once the six Phase 1 gates above are met. Prerequisites: the authenticated Lighthouse and screenshot baselines, the extended `ux-audit.spec`, green quick, frontend and export gates, the two guard tests with recorded starting counts, and an approved token sheet. Affected components: `globals.css`, `status-badge.tsx`, the new status registry, `useTenantTimezone.ts`, `error-message.ts`, `confirm-dialog.tsx`, `dialog.tsx`, `providers.tsx`. It changes no API, no route and no business rule, and it is reversible file by file.

This document was produced without modifying any application source, test, dependency, configuration or deployment file, and nothing in it has been implemented.
