# Frontend audit against Vercel's Web Interface Guidelines, 2026-11-18

Base commit `b452c55983` on `main`. Scope: the frontend only, every source file under
`src/src` except `test/`, `e2e/` and the generated API client: 721 files (app, components,
features, lib, api, `styles/globals.css`, the three locale dictionaries). The ruleset is Vercel's
[Web Interface Guidelines](https://github.com/vercel-labs/web-interface-guidelines), read from
`command.md` on 2026-11-18 at 14:49Z, the file the `web-design-guidelines` agent skill fetches
before each review. It has 17 sections: Accessibility, Focus States, Forms, Animation,
Typography, Content Handling, Images, Performance, Navigation & State, Touch & Interaction,
Safe Areas & Layout, Dark Mode & Theming, Locale & i18n, Hydration Safety, Hover & Interactive
States, Content & Copy, and Anti-patterns.

This register builds on [`QA-FRONTEND-2026-10-15.md`](QA-FRONTEND-2026-10-15.md),
[`AZ-REVIEW-2026-11-03.md`](AZ-REVIEW-2026-11-03.md) and section C.E of
[`FRONTEND_REDESIGN_PLAN.md`](../FRONTEND_REDESIGN_PLAN.md); it does not repeat what they
already record (see *Known before this pass*). It lists defects only. **Report only**: no code
changed in this pass, by the owner's decision of 2026-11-18. The fix plan at the end proposes the
pull requests.

**How to read this register.** Severities are the house scale from the A-Z review: **S1** data
loss, a security or cross-tenant failure, or an organisation locked out; **S2** a wrong figure,
a wrong decision recorded, or a control that does not hold (here: a keyboard user cannot see or
reach a control, a value is saved differently from what was typed, an irreversible action runs
with no confirmation); **S3** a defect with a workaround or a narrow audience; **S4** cosmetic or
hardening. Rules that the owner's house style overrides are not graded; they are listed under
*Owner decisions, not findings*. A finding that could not be confirmed is not listed; what the
readers suspected but nobody confirmed is under *What this pass does not claim*.

## Method

1. **Mechanical sweep.** A Node script (kept out of the repository) walked the 721 files and
   emitted `file:line` candidates for every rule a regex can see: `outline-none` with no
   focus-visible replacement in the same element, plain `focus:`, `transition-all`, the
   `tw-animate-css` class names, three ASCII dots in user text, loading labels with no `…`,
   `<img>` without dimensions or lazy loading, click handlers on non-buttons, icon buttons and
   `title=`-only labels, inputs without `autoComplete` or `name`, email and code fields without
   `spellCheck={false}`, `autoFocus`, `onPaste` + `preventDefault`, zoom-disabling viewports,
   `toLocale*`/`toFixed`/hard-coded `en-GB`/`en-US`/`en-ET` outside `lib/`, layout reads,
   `h-screen`, `dark:` utilities, hex colours and raw palette classes in TSX,
   `dangerouslySetInnerHTML`, `onClick` navigation, `window.confirm`, `value=` without
   `onChange`, `role="button"`, positive `tabIndex`, native `<select>` without colours, `h1`/`h2`
   without `text-balance`, animated GIFs, sticky and fixed bars. 1,881 candidate lines across
   302 files; 419 files had no candidate.
2. **Judgement pass.** Nine readers, one per area (shell and primitives; shared components;
   sign-in and onboarding; people; attendance, devices and kiosk; finance and insights; settings
   and the platform console; public site; libraries), each given the ruleset, the house
   overrides, the known-gap list, its file list and its sweep candidates. Each confirmed or
   rejected every candidate by reading it in context, read for the rules a regex cannot see
   (keyboard reachability, heading order, label association, URL state, unbounded lists, live
   regions, destructive actions) and returned `file:line` findings with the attribute quoted.
   Coverage caveat: three readers did not read every file line by line. Attendance/devices/kiosk
   read 27 of 78 files in full and pattern-scanned the rest; settings and console read about 30 of
   121 in full (the batch is 37,354 lines); libraries read 29 of 118 in full and grepped the
   `.ts` modules. Every candidate line was still read in context.
3. **Verification.** Every finding below was re-read at its cited line by the lead. Readers'
   claims that failed verification were dropped, among them about 300 "icon without
   `aria-hidden`" lines (lucide-react 1.66 sets it by default; 107 of 107 icons on the dashboard
   carry it), `outline-none` on the skip link and on `<main tabIndex={-1}>` (a programmatic focus
   target), `title=` beside an sr-only name, and a claim that the shared form hook has no
   callers (it has 23).
4. **Browser pass.** The local stack from this checkout (`api-8010` and `web-3010` in
   `.claude/launch.json`, MariaDB `ethr`, the seeded demo organisation, signed in as its tenant
   admin), read-only. Checked: the loaded stylesheets for the overlay animation classes; the
   `theme-color` metas against the body background; the auth and public trees for a skip link and
   a `main` id; the prerendered HTML of `/en` and `/en/contact`; the dashboard's lucide icons,
   heading order, sidebar closed groups and pin buttons, landmark names and `aria-sort`; the
   leave page's tab click against the URL; the mobile tab bar's focused controls at 375 px; and
   a marketing scene's fill with the app forced dark while the OS was emulated light.
   Screenshots were not kept; each result is one line in the *Confirmed how* column.

## Headline

| | |
|---|---|
| Files in scope | 721 |
| Files with a confirmed finding | about 190 |
| Files passed by a reader after a full read | 206 |
| Findings (deduplicated, below) | 46: S1 0, S2 8, S3 23, S4 15 |
| Confirmed in the browser as well as at the line | 12 |
| Owner decisions, not graded | 5 |

By rule section, confirmed defects cluster in **Focus States** (invisible or lost focus in the
shell), **Forms** (metadata, labels, first-error focus, confirmation), **Navigation & State**
(URL state and destructive actions), **Locale & i18n** (formats that bypass the house helpers),
**Animation** (dead classes, Recharts, `transition-all`) and **Accessibility** (live regions,
custom widgets, heading hierarchy). **Hydration Safety**, **Images** and **Hover** are close to
clean; **Performance** findings are unbounded lists, not render cost.

## Findings

Primitives and the shell first, because every page inherits them; then pages. *Blast radius* is
the number of files that import the component or the number of sites the pattern was found at.

| ID | Rule section | Sev | Finding | Where | Blast radius | Confirmed how |
|---|---|---|---|---|---|---|
| W-01 | Focus States | **S2** | **Keyboard focus is invisible on the phone tab bar.** Tab links and the More button carry `focus-visible:outline-none` with no ring, outline or background replacement, so the base `:focus-visible` outline is removed and nothing takes its place. | `components/layouts/mobile-bottom-nav.tsx:137`, `:166` | Every dashboard page below `lg` | Line read; at 375 px the focused More button and a focused tab computed `outline: none`, `box-shadow: none`, transparent background |
| W-02 | Focus States, Touch | **S2** | **Sidebar pin buttons are invisible when focused and unreachable by touch.** 31 pin/unpin buttons are `opacity-0`, revealed only by `group-hover/pin:opacity-100`; there is no focus-visible reveal, so Tab lands on a button nobody can see, and a touch user has no hover. | `components/layouts/sidebar-nav.tsx:580`, `:598`, `:632` | Every dashboard page at `lg` and up | Line read; in the browser `.focus()` on a pin button left `opacity: 0` and the element was `document.activeElement` |
| W-03 | Accessibility, Focus States | **S2** | **Closed sidebar groups keep their links in the tab order.** Collapsed groups, the Recent list and the setup banner are hidden only by `grid-rows-[0fr]` plus `overflow-hidden`, with no `inert`, `hidden` or `aria-hidden`; a keyboard user tabs through links that are not on screen and a screen reader reads them. | `components/layouts/sidebar-nav.tsx:409-431`, `:800-828`, `:944-972` | Every dashboard page at `lg` and up | Line read; on the dashboard 3 closed groups held 28 focusable links, none inert |
| W-04 | Forms, Accessibility | **S2** | **The one-time code boxes cannot take autofill or a paste outside box 1, and have no name.** Six `maxLength={1}` inputs: `autoComplete="one-time-code"` sits on box 0 only, so an SMS or email autofill is cut to one digit; `onPaste` is wired on box 0 only; no box has `aria-label` or `name`, and the callers' sr-only `<Label>` has no `htmlFor`. | `components/shared/otp-code-input.tsx:83-99`; `app/(auth)/login/mfa/mfa-form.tsx:117`; `app/(auth)/login/otp/otp-form.tsx:425` | Sign-in by code and two-factor sign-in | Line read; autofill behaviour not exercised on a phone |
| W-05 | Forms | **S2** | **A pasted phone number is silently cut.** The handler strips non-digits then `slice(0, 9)`, so pasting `0912345678`, `+251912345678` or `251912345678` stores a different number from the one pasted, with no message. | `components/shared/phone-input.tsx:43` | 3 consumers (profile/personal twice, sign-in by code) | Line read |
| W-06 | Forms, Locale | **S2** | **A loan amount loses its cents.** `parseInt(form.amount_cents) * 100` turns 1500.50 into 1500.00 (and the same for the monthly deduction); the field is `type="number"` with no `step`, and it is named cents while it takes birr. | `app/(dashboard)/payroll/loans/page.tsx:131-133` | Loans | Line read |
| W-07 | Accessibility, Anti-patterns | **S2** | **The attendance import drop zone cannot be used without a mouse.** The zone is a `<div onClick>` over a `className="hidden"` file input, with no button, no key handler and no focusable control, so a keyboard or screen-reader user has no way to choose a file. | `app/(dashboard)/attendance/import/page.tsx:215-222`, `:250` | Attendance import | Line read |
| W-08 | Navigation & State | **S2** | **Irreversible actions run on one click with no confirmation or undo.** Regenerate a kiosk token (invalidates the live terminal's token) and delete a kiosk; delete a rotation; mark a contract expired or end it early; close a disciplinary case; delete a salary step; remove an SMS gateway (deletes stored credentials); disconnect Google Drive backup; delete an alert threshold or a digest; "Use the law's rates" and "Reset to default" on statutory rules; revoke a session and sign out all. The rule is explicit: confirmation modal or undo window, never immediate. Other deletes in the same screens do confirm, so the pattern exists. | `app/(dashboard)/attendance/kiosks/page.tsx:283`, `:297`; `app/(dashboard)/shifts/rotations/page.tsx:298`; `features/employees/components/contracts-tab.tsx:214`, `:226`; `features/employees/components/disciplinary-cases-tab.tsx:385`; `features/organization/components/grade-salary-steps-dialog.tsx:190`; `app/(dashboard)/admin/sms/page.tsx:565`; `features/admin/components/backup-drive-card.tsx:372`; `features/dashboard/components/alert-thresholds-dialog.tsx:244`; `features/dashboard/components/dashboard-digest-dialog.tsx:252`; `features/payroll/components/statutory-rules-card.tsx:233`, `:292`; `features/auth/components/active-sessions-card.tsx:82-114`, `:181-190` | 13 sites | Each line read |
| W-09 | Dark Mode & Theming | S3 | **`dark:` utilities follow the operating system, not the theme picker.** Tailwind v4's `dark:` variant is the `prefers-color-scheme` media query unless `@custom-variant dark` is declared, and `globals.css` declares none, while the theme is the `.dark` class from next-themes. Where the picker and the OS differ, these elements render the other theme: the branding card's dark-logo preview box and three marketing scene backdrops. | `features/settings/components/branding-card.tsx:162`; `components/marketing/landing/hero-story.tsx:593`; `components/marketing/landing/problem-stories.tsx:1907`; `components/marketing/landing/scenes.tsx:27` | 4 sites | `globals.css` read (no `@custom-variant`); in the browser with `html.dark` forced and the OS emulated light, `fill-muted dark:fill-background` computed to `--muted`, not `--background` |
| W-10 | Accessibility | S3 | **The public site has no skip link and its `<main>` has no id.** Only the dashboard shell has one; the marketing, auth, kiosk and offline roots have none, under a `sticky top-0 z-50` header. On the organisation portal the skip link exists but lands after the hero, past the sign-in form. | `app/(marketing)/[locale]/layout.tsx:109`; `components/layouts/marketing-header.tsx:227`; `features/auth/components/portal-view.tsx:302-316` | Every public and auth page | Prerendered HTML of `/en` and `/en/contact` has no `href="#main"` and `<main class="flex-1">`; the sign-in page likewise in the browser |
| W-11 | Performance, Hydration Safety | S3 | **Site content is absent from the prerendered HTML.** `useSiteContent()` returns an empty value until hydration plus a fetch, so the metrics band, hero badge, testimonial, trusted-by strip and the contact column (email, phone, address) are not in the static export; they pop in after load and push the page down, and a no-JS visitor or crawler never sees the contact details. The comment at `metrics-band.tsx:13` says the opposite. | `features/marketing/site-content.ts:201`, `:207`; `components/marketing/landing/metrics-band.tsx:40`; `components/marketing/landing/trusted-by.tsx:21`; `app/(marketing)/[locale]/contact/contact-content.tsx:136`, `:199` | Home and contact pages in every locale | Prerendered `/en/contact` (71 KB) contains no `mailto:` or `tel:` link |
| W-12 | Navigation & State | S3 | **Tabs, pages and filters live in component state, not the URL.** `useQueryTab` reads `?tab=` but its setter never writes it back, so a clicked tab is lost on reload, share and back/forward on the 9 pages that use it. Page numbers are `useState(1)` on employees, directory, payroll runs, notifications, users, admin tenants and leave; filters and periods on analytics, reports builder and quick tab, reconcile panel, team attendance, visits, overtime, roster, support and audit log; the register, guided onboarding and settings/payroll keep their step or tab in state. | `lib/hooks/useQueryTab.ts:30-39` (9 pages); `app/(dashboard)/employees/page.tsx:137`; `app/(dashboard)/directory/page.tsx:24`; `app/(dashboard)/payroll/page.tsx:37`; `app/(dashboard)/notifications/page.tsx:30`; `app/(dashboard)/settings/users/page.tsx:136`; `app/(dashboard)/admin/tenants/page.tsx:89`; `app/(dashboard)/leave/page.tsx:303`, `:498-499`; `app/(dashboard)/analytics/page.tsx:64-66`; `features/reports/components/builder-tab.tsx:87`; `features/reports/components/quick-tab.tsx:221`; `features/payroll/components/reconcile-panel.tsx:94`; `app/(dashboard)/settings/payroll/page.tsx:25`; `features/onboarding/v2/components/guided-onboarding.tsx:52-59`; `app/(auth)/register/register-form.tsx:85-90` | 9 tab pages plus about 20 list and report views | Hook read; on `/leave`, clicking the second tab left `location.search` empty |
| W-13 | Accessibility | S3 | **Hand-built radio groups and tab lists have no arrow-key behaviour.** Groups of `<button role="radio">` or `role="tab"` with no `onKeyDown`, no roving `tabIndex` and (for tabs) no `aria-controls`: every option is its own tab stop and arrow keys do nothing. | `app/(auth)/login/otp/otp-form.tsx:302-328`; `features/onboarding/v2/components/industry-config-step.tsx:414-453`; `app/(dashboard)/vacancies/page.tsx:207-232`; `features/employees/components/lifecycle-tab.tsx:316-349`; `app/(dashboard)/attendance/mobile/page.tsx:868-886`; `app/(dashboard)/attendance/integrity/page.tsx:236-246`; `app/(dashboard)/attendance/photos/page.tsx:100-110`; `features/dashboard/components/interactive-charts.tsx:85`; `app/(dashboard)/voice/page.tsx:244`, `:275`, `:784`; `app/(dashboard)/voice/insights/page.tsx:424`; `features/settings/components/landing-card.tsx:541`; `features/settings/components/portal-preview.tsx:85-156`; `features/support/components/*:430` | 14 sites | Each line read |
| W-14 | Forms | S3 | **Labels that are not associated with their control.** `<Label>` without `htmlFor` beside an `Input`, `Select` or `Textarea` without `id`, outside `FormField`: the text is not clickable and the control may be unnamed. | `app/(dashboard)/employees/[id]/employee-detail.tsx:319-357` (name, email, phone); `app/(dashboard)/leave/page.tsx:165`, `:188`, `:199`, `:209`; `app/(dashboard)/payroll/loans/page.tsx:174`, `:186`, `:204`; `features/payroll/components/reconcile-panel.tsx:135`, `:143`; `features/dashboard/components/alert-thresholds-dialog.tsx:115-170`; `app/(dashboard)/attendance/page.tsx:430`, `:443`, `:457-467`, `:504-514`, `:788`; `app/(dashboard)/attendance/corrections/page.tsx:678`; `features/employees/components/disciplinary-cases-tab.tsx:1050-1088`; `app/(dashboard)/admin/tenants/[id]/tenant-detail.tsx:1553`; `app/(dashboard)/admin/sms/page.tsx:160`, `:728`; `features/reports/components/builder-tab.tsx:607`; `features/reports/components/saved-tab.tsx:300`, `:325`; `features/dashboard/components/dashboard-digest-dialog.tsx:127`, `:150`; `app/kiosk/page.tsx:491-497`; `features/devices/components/device-workforce-dialogs.tsx:337` | About 35 controls on 16 screens | Each line read (the FormField pattern that fixes this is used 122 times elsewhere) |
| W-15 | Accessibility | S3 | **Controls with no accessible name.** The attendance heat map renders about 90 `16×16 px` day buttons per range with only a `title` and a colour; the dashboard calendar renders 28 to 31 `<button type="button">` days with no handler and no label (dead focus stops); the shared search box has a placeholder and no label; the kiosk admin PIN has a placeholder only; the investigation and review note inputs have placeholders only; `audit-log-explorer` puts ids only in `title=`. | `features/employees/components/attendance-timeline-tab.tsx:201-216`, `:143-152`; `features/dashboard/components/calendar-widget.tsx:85`, `:154`; `components/shared/search-input.tsx:53` (6 consumers); `app/kiosk/page.tsx:534-542`; `features/employees/components/disciplinary-cases-tab.tsx:332`; `features/employees/components/retirement-tab.tsx:255`; `components/shared/audit-log-explorer.tsx:333`, `:356`, `:363` | 9 sites, search box on 6 pages | Each line read |
| W-16 | Accessibility | S3 | **A table row and a list item take `role="button"` and nest the row's own buttons.** Enter on an inner Edit or Delete bubbles to the row's key handler, which calls `preventDefault()` and fires the row click instead; the row's table semantics are lost. One page sets a row `onClick` today, and it navigates by `router.push`, so a middle-click or Ctrl-click cannot open the tenant in a new tab. | `components/shared/simple-table.tsx:158`, `:227`; `app/(dashboard)/admin/tenants/page.tsx:317` | 45 importers; 1 page with row `onClick` | Line read |
| W-17 | Animation, Accessibility | S3 | **Charts animate under reduced motion and have no name.** Recharts animates every Area, Bar, Line and Pie on mount by JavaScript, which the global `prefers-reduced-motion` CSS rule does not reach; `isAnimationActive` appears nowhere in `src/src`. No chart wrapper carries `role="img"`, `aria-label` or `aria-describedby`; the "Show as table" toggle is the only alternative. Two drill-downs exist only as `<Bar onClick>`, with no keyboard or touch path. | `components/shared/audit-log-charts.tsx:108`, `:169`; `features/employees/components/headcount-charts.tsx:110-116`, `:163-171`; `features/shifts/components/shift-coverage-charts.tsx:190-257`; `app/(dashboard)/analytics/attendance-tab.tsx:200`, `:286`; `app/(dashboard)/analytics/overview-tab.tsx:260`, `:267` (drill-down), `:328`; `app/(dashboard)/analytics/payroll-tab.tsx:188-378`; `app/(dashboard)/analytics/workforce-tab.tsx:132`, `:180`; `features/dashboard/components/interactive-charts.tsx:219`, `:300`, `:411`; `features/leave/components/leave-charts.tsx:186`, `:220`, `:308`; `app/(dashboard)/voice/insights/page.tsx:215-325` | Every chart in the product | Grep for `isAnimationActive` returned 0; Recharts defaults it to true |
| W-18 | Performance | S3 | **Unbounded collections rendered without a cap.** `useEmployeeOptions` fetches every page of `/employees` and renders the whole workforce as `SelectItem`s in a non-searchable supervisor picker, loaded on mount of `/employees/new`; the reporting chart renders every employee as nested `<li>`, open; a payroll run's entries table and the run preview render every payslip; the staged migration renders up to 2,000 rows each with a Radix Select, and its logins list up to 2,000; the report preview keeps every row past 200 in the DOM as `hidden`; approvals, loans, cost sharing, reconcile exceptions, letters, announcements and holidays render whole collections. | `features/attendance/api.ts:297-304` (3 consumers); `features/organization/components/reporting-chart.tsx:153`, `:223`; `app/(dashboard)/payroll/[id]/payroll-run-detail.tsx:754`; `features/payroll/components/run-payroll-dialog.tsx:262`; `features/onboarding/v2/components/migration-step.tsx:1488-1634`; `features/onboarding/v2/components/migration-logins.tsx:263-291`; `features/reports/components/preview-result.tsx:200`; `app/(dashboard)/approvals/page.tsx:248`; `app/(dashboard)/payroll/loans/page.tsx:80`; `features/payroll/components/reconcile-panel.tsx:257`; `app/(dashboard)/employees/letters/page.tsx:361`; `app/(dashboard)/announcements/page.tsx:580`; `features/holidays/api.ts:14` | 13 sites | Each line read; the Habru organisation has 3,927 employees (BASELINE §25p), which is the size the supervisor picker would render |
| W-19 | Forms | S3 | **The registration form gives no feedback on an invalid step.** Next and Create are `disabled` until the step validates (the rule: submit stays enabled until the request starts); the form has no `noValidate` although fields carry `minLength` and `type="email"`, so the native English bubble can appear; the server error box has no `role="alert"` and takes no focus; the step indicator has no `aria-current="step"`; subdomain availability is shown by an icon alone; `autoFocus` sits on all three steps of a mobile flow. | `app/(auth)/register/register-form.tsx:429`, `:877-889`, `:853-857`, `:392-427`, `:611-632`, `:448`, `:664`, `:777` | Sign-up | Line read |
| W-20 | Accessibility | S3 | **Show-password buttons are unreachable by keyboard.** Three auth forms give the toggle `tabIndex={-1}`; none sets `aria-pressed`; the revealed field has no `spellCheck={false}`. | `app/(auth)/login/activate/activate-form.tsx:261`; `app/(auth)/login/reset/reset-form.tsx:191`; `app/(auth)/login/change-password/change-password-form.tsx:177` | 3 forms | Each line read |
| W-21 | Accessibility | S3 | **Async results arrive silently.** No live region on: the kiosk punch result and errors, the QR scan result, the contact form's success state, the one-time secret cards for API key, webhook secret and SCIM token (and their copy feedback), the app loading screen, the error boundary and route error pages, the default `QueryBoundary` skeleton (81 consumers), the map picker's geolocation and search results, command-palette results, the "code sent" message, the subdomain availability status, the PIN error, notification list changes, report generation. | `app/kiosk/page.tsx:501`, `:544`, `:701`; `features/kiosk/components/kiosk-punch-result.tsx:87`; `app/(dashboard)/attendance/scan/page.tsx:260-299`; `app/(marketing)/[locale]/contact/contact-content.tsx:215`; `app/(dashboard)/settings/api-keys/page.tsx:223-262`; `app/(dashboard)/settings/webhooks/page.tsx:223-250`; `app/(dashboard)/settings/scim/page.tsx:164-204`; `components/shared/app-loading-screen.tsx:53`; `components/shared/error-boundary.tsx:31`; `app/error.tsx:38`; `app/(dashboard)/error.tsx:43`; `components/patterns/QueryBoundary.tsx:18`; `components/shared/map-picker/map-picker.tsx:228`, `:259`, `:293`; `components/shared/command-palette.tsx:276`; `app/(auth)/login/otp/otp-form.tsx:418-422`; `features/settings/components/address-name-section.tsx:222-232`; `features/employees/components/kiosk-pin-card.tsx:134-143`; `app/(dashboard)/notifications/page.tsx:80-131`; `features/reports/components/builder-tab.tsx:525` | 20 sites, one of them on 81 pages | Each line read |
| W-22 | Forms | S3 | **No unsaved-changes guard outside one form.** `useUnsavedChangesWarning` has a single caller (`/employees/new`) and is `beforeunload` only, so in-app navigation is never intercepted. Forms that track a dirty state and still have no guard: employee detail edit (Cancel discards silently), vacancy dialog (a 5,000-character description), settings/attendance, settings/page, accounting, exit-clearance, leave-types, notification preferences, profile/personal, organization, address and branding cards, the migration paste box, and the registration steps. The Form Pattern Library in `docs/CLAUDE.md` requires the warning. | `lib/hooks/useUnsavedChangesWarning.ts:1-21`; `app/(dashboard)/employees/[id]/employee-detail.tsx:295`, `:303`; `app/(dashboard)/vacancies/page.tsx:609`, `:873`; `app/(dashboard)/settings/attendance/page.tsx:180`; `app/(dashboard)/settings/page.tsx:255`; `features/onboarding/v2/components/migration-step.tsx:1072-1076` | About 15 forms | Grep: one caller |
| W-23 | Forms | S3 | **The first error is not focused after a failed submit.** Hand-rolled forms set an error state and return: sign-in (focus stays on the button), sign-in by code, employee detail (toast only), vacancies (toast; the per-field alerts exist), CRUD section (toast only). Forms on `useZodForm` do focus the first field; these do not use it. | `app/(auth)/login/login-form.tsx:704-711`; `app/(auth)/login/otp/otp-form.tsx:98-129`; `app/(dashboard)/employees/[id]/employee-detail.tsx:146-152`; `app/(dashboard)/vacancies/page.tsx:590`; `components/shared/crud-section.tsx:111` | 5 forms, CRUD section on 6 pages | Each line read |
| W-24 | Locale & i18n | S3 | **Numbers and dates formatted outside the house helpers.** `toLocaleString`, `toFixed` or a hard-coded locale in page code instead of `formatETB`, `useDateFormatters` or `intlLocale`: a correction amount via `toLocaleString("en-ET")`; device counts via bare `toLocaleString()`; overtime hours via `toFixed`; pricing and landing figures via `"en-ET"` regardless of page language; every analytics axis tick via `Intl.NumberFormat("en")`; the payroll analytics table showing money as compact `1.2K` where the chart shows exact birr; "Date paid" defaulting from `toLocaleDateString("en-CA")` in the browser's zone; salary bands via `toLocaleString()`; file sizes and disk sizes via `toFixed` plus a literal unit; the Ethiopian month label hard-coding `"am"` so Oromo falls through to English. | `app/(dashboard)/attendance/corrections/page.tsx:343`; `app/(dashboard)/devices/page.tsx:584`; `app/(dashboard)/devices/dashboard/page.tsx:96-336`; `app/(dashboard)/devices/[id]/device-detail.tsx:383`, `:388`; `app/(dashboard)/attendance/overtime/page.tsx:102`, `:110`, `:164`; `app/(marketing)/[locale]/pricing/pricing-content.tsx:123`, `:168`; `components/marketing/landing/landing-hero.tsx:68`; `components/marketing/landing/metrics-band.tsx:83`; `app/(dashboard)/analytics/axis-format.ts:21`; `app/(dashboard)/analytics/payroll-tab.tsx:143-146`; `app/(dashboard)/payroll/[id]/payroll-run-detail.tsx:284`, `:913`; `features/organization/components/grade-salary-steps-dialog.tsx:157`; `features/employees/components/attendance-timeline-tab.tsx:125`, `:309`; `features/news/news-format.ts:15`; `app/(dashboard)/settings/data-export/page.tsx:45`; `app/(dashboard)/admin/operations/page.tsx:581`; `lib/uploads/upload-limit.ts:19`; `lib/calendar/period-label.ts:46`; `components/shared/currency-input.tsx:24` | 44 sweep hits, 24 confirmed as display | Each line read; `lib/utils/date.ts` and `currency.ts` themselves are the sanctioned helpers and not counted |
| W-25 | Locale & i18n | S3 | **English rendered outside `t()`.** A status map and two sentences in the lifecycle tab; `" to "` joining a date range in the command palette; `"User"` in the header; a Reverb toast; `"PDF"` on every news attachment; raw API enums shown with `replace("_", " ")` (notification type, pension exemption reason, alert severity, timeline status); a hard-coded Amharic paragraph on the offline page with no `lang`; a literal English placeholder in the migration paste box. Convention #9 forbids literals; `hardcoded-english.test.tsx` did not catch these. | `features/employees/components/lifecycle-tab.tsx:60-67`, `:165-167`, `:272`; `components/shared/command-palette.tsx:415`; `components/layouts/app-header.tsx:130`; `components/providers/reverb-provider.tsx:403`; `features/news/components/article-view.tsx:111`; `features/dashboard/components/recent-activity.tsx:137`; `features/payroll/components/run-payroll-dialog.tsx:292`; `features/dashboard/components/alert-thresholds-dialog.tsx:237`; `features/employees/components/attendance-timeline-tab.tsx:263-264`; `app/offline/page.tsx:24`; `features/onboarding/v2/components/migration-step.tsx:1072-1089` | 12 sites | Each line read |
| W-26 | Forms | S3 | **Device credential fields invite the admin's own password.** Username and password inputs for a terminal have no `autoComplete`, so the browser offers the ETHR sign-in; the kiosk admin PIN is `type=password` with no `autoComplete`; serial numbers, IPs, API keys, tokens and TINs lack `spellCheck={false}` and `autoComplete="off"`. | `app/(dashboard)/devices/page.tsx:888-911`, `:767`, `:862`; `app/(dashboard)/devices/[id]/device-detail.tsx:926-1071`; `app/(dashboard)/attendance/kiosks/page.tsx:396`, `:414`, `:463`; `features/payroll/components/statutory-details-card.tsx:146`, `:220`; `app/(dashboard)/settings/users/page.tsx:541`, `:607`; `app/(dashboard)/settings/webhooks/page.tsx:492-497` | 6 screens | Each line read |
| W-27 | Forms | S3 | **The radius field fights the typist.** The map picker clamps the radius to `[min, max]` inside `onChange`, so typing "50" when the minimum is 10 becomes "10" after the first keystroke, then "100". | `components/shared/map-picker/map-picker.tsx:366-372` | Work sites and branches | Line read |
| W-28 | Accessibility | S3 | **Hidden file inputs stay in the tab order.** `<input type="file" className="sr-only">` is focusable with no visible focus; Tab lands on an invisible control before the visible button. | `app/(dashboard)/announcements/page.tsx:974`, `:1060`; `features/settings/components/brand-image-upload.tsx:142`; `features/settings/components/logo-upload.tsx:114`; `features/admin/marketing/marketing-image-upload.tsx:128` | 5 upload controls | Each line read |
| W-29 | Touch & Interaction | S3 | **The cover focal-point picker has no keyboard path.** One button whose handler reads `e.clientX`/`e.clientY`; Enter or Space reports 0,0 and snaps the focal point to the top-left corner. | `features/settings/components/brand-image-upload.tsx:253-276` | Branding | Line read |
| W-30 | Content Handling | S3 | **User-written text with no `truncate`, `line-clamp` or `break-words`.** Employee name and email cells, the detail `h1` and its parent flex with no `min-w-0`, `InfoRow` values, directory position and department, contract terms and notes (2,000 characters), disciplinary descriptions, cover notes (5,000 characters, `whitespace-pre-line`), vacancy descriptions where a long URL overflows the dialog, voice and support messages, announcement titles, session device strings. | `app/(dashboard)/employees/page.tsx:279-292`; `app/(dashboard)/employees/[id]/employee-detail.tsx:192-208`; `features/employees/components/info-row.tsx:13-18`; `app/(dashboard)/directory/page.tsx:97-103`; `features/employees/components/contracts-tab.tsx:197-199`; `features/employees/components/disciplinary-cases-tab.tsx:273-325`; `features/recruitment/components/applicants-panel.tsx:418-422`, `:470`; `app/(dashboard)/vacancies/page.tsx:468`, `:474`; `app/(dashboard)/voice/insights/page.tsx:367`; `app/(dashboard)/support/page.tsx:313`; `app/(dashboard)/announcements/page.tsx:589`; `features/auth/components/active-sessions-card.tsx:150-156` | 13 sites | Each line read; overflow not reproduced with long data |
| W-31 | Accessibility | S3 | **Sort state is visual only, and the data table's states are silent.** `aria-sort` appears nowhere in `src/src`; the sort control is a button with an arrow icon as the only cue. `DataTable`'s loading, error and empty states have no role or live region, the expand toggle swaps its label instead of `aria-expanded`, and the Columns menu prints raw column ids for non-string headers. | `components/patterns/DataTable.tsx:424`, `:323-341`, `:216-231`, `:396-398` | 3 consumers | Line read; `document.querySelectorAll('[aria-sort]')` on the dashboard returned 0 |
| W-32 | Animation | S4 | **Overlay enter and exit animations are dead code.** `animate-in`, `animate-out`, `fade-in-0`, `zoom-in-95`, `slide-in-from-*` and `slide-out-to-*` are `tw-animate-css` class names; neither that plugin nor `tailwindcss-animate` is installed, `globals.css` has only `@import "tailwindcss"`, and no stylesheet rule matches them. Every Dialog, Sheet, Select, Dropdown, Popover and Tooltip appears and disappears with no transition, as do the kiosk and scan result cards. | `components/ui/dialog.tsx:21`, `:46`; `components/ui/sheet.tsx:59-65`; `components/ui/select.tsx:40`; `components/ui/dropdown-menu.tsx:21`; `components/ui/popover.tsx:20`; `components/ui/tooltip.tsx:35`; `app/kiosk/page.tsx:697`; `app/(dashboard)/attendance/scan/page.tsx:263`, `:285`; `features/kiosk/components/kiosk-punch-result.tsx:65` | Dialog 82 files, Select 78, Dropdown 12 | `package.json` and `globals.css` read; in the browser, 0 stylesheet rules match the class names and 45 keyframes exist, none of them these |
| W-33 | Touch & Interaction, Safe Areas | S4 | **No touch hardening anywhere.** `overscroll-behavior: contain` is absent from Dialog, Sheet and every scroll region (0 hits in `src/src`), so a scrolled-out drawer scrolls the page behind it; `touch-action: manipulation` and `-webkit-tap-highlight-color` are set only by the kiosk lock hook, not on the punch button or any Button; the kiosk draws edge to edge with `viewportFit: cover` and no `env(safe-area-inset-*)`; `h-screen`/`min-h-screen` is used at 15 sites and `vh` in dialogs where `dvh` fits mobile browser chrome (`ui/dialog.tsx` already uses `dvh`). | `components/ui/dialog.tsx:46`; `components/ui/sheet.tsx:62`; `components/ui/button.tsx:7`; `features/attendance/components/mobile/punch-button.tsx:149-164`; `app/kiosk/page.tsx:586`, `:894`; `app/(dashboard)/dashboard-shell.tsx:58`; `app/(marketing)/[locale]/layout.tsx:107`; `app/kiosk/layout.tsx:31`; `app/(dashboard)/shifts/rotations/page.tsx:313`, `:667`, `:702`; `styles/globals.css` | Whole app | `grep overscroll` = 0; in the browser no loaded rule sets `overscroll-behavior` |
| W-34 | Dark Mode & Theming | S4 | **`theme-color` matches neither background.** Light is `#0F4C75` (the brand blue) while the page is white; dark is `#020617` (the sidebar) while the page is `#0f172a`; both follow the OS media query, never the picker, and high-contrast has no entry. | `app/root-shell.tsx:109-112` | Every page on Android Chrome | Metas read in the browser against `body` background `rgb(15, 23, 42)` in the dark theme |
| W-35 | Accessibility | S4 | **Heading hierarchy leans on `<div>` titles.** `CardTitle` is a `<div>` (95 files), so most pages have an `h1` and no section headings; `EmptyState` hard-codes `<h3>` (70 files); SCIM, the API reference and the tenant journey card have `h3`/`h4` with no `h2`; the vacancies card is `h3` with no `h2`; the news list's story titles are `<span>`; the 404 page's `h1` is the bare "404"; the kiosk punch screen has no heading. | `components/ui/card.tsx:35`; `components/shared/empty-state.tsx:22`; `app/(dashboard)/settings/scim/page.tsx:113-266`; `features/api-reference/endpoint-list.tsx:69-185`; `app/(dashboard)/admin/tenants/[id]/tenant-journey-card.tsx:143-315`; `app/(dashboard)/vacancies/page.tsx:273`; `features/news/components/news-card.tsx:151`; `app/not-found-content.tsx:58`; `app/kiosk/page.tsx:483` | 95 + 70 files | Line read; the dashboard's own outline is H1 > H2 > H2 > H3 because its sections use real headings |
| W-36 | Accessibility | S4 | **Landmark and menu semantics.** `<aside aria-label="Main navigation">` wraps `<nav aria-label="Main navigation">` (two landmarks, one name); `role="main"` is redundant on `<main>`; a sidebar trigger says `aria-haspopup="menu"` over a flyout of plain links with no `role="menu"`; theme items show selection by a trailing icon with no checked state while the sibling menus use `RadioGroup`; the notification bell's badge count is not exposed to assistive tech; the Columns toggle in the mobile nav sets no `aria-pressed`. | `components/layouts/app-sidebar.tsx:188`; `components/layouts/sidebar-nav.tsx:302`, `:715`; `app/(dashboard)/dashboard-shell.tsx:73`; `components/layouts/app-header.tsx:337-360`; `features/notifications/components/notification-bell.tsx:56` | Shell | Landmarks listed in the browser: ASIDE and NAV both "Main navigation" |
| W-37 | Forms | S4 | **Form metadata gaps.** Inputs without `autoComplete` (about 260 candidates; confirmed where the field is identity data: contact form name, email, phone and organisation; invite and recipient emails; profile email; emergency phone), `type="text"` for phone fields, email fields without `spellCheck={false}` (26 sites), `type="number"` on a year field, placeholders that are patterns rather than examples ending in `…` (phone `9XXXXXXXX`, `EMP-0001`, `0000000000`, `hr@example.com`), checkboxes whose icon is a dead zone, and no `name` on hand-written inputs (283 candidates; the Input primitive adds none and only `useZodForm` forms get one). | `app/(marketing)/[locale]/contact/contact-content.tsx:239-266`; `app/(dashboard)/settings/users/page.tsx:523`; `features/reports/components/saved-tab.tsx:328`; `features/dashboard/components/dashboard-digest-dialog.tsx:153`; `app/(dashboard)/profile/personal/page.tsx:521`; `components/shared/phone-input.tsx:52`, `:59`; `app/(dashboard)/employees/new/page.tsx:734`, `:837`, `:1050`; `features/onboarding/v2/components/access-step.tsx:294-311`; `components/shared/dual-calendar-date-input.tsx:264` | Most forms | Sweep counts; representative lines read |
| W-38 | Typography | S4 | **Three dots, missing ellipses and breakable units.** 100 user-facing strings use `...` where `…` belongs: 21 in `en.json`, 24 in `am.json`, 22 in `om.json` (`common.loading` is "Loading…" nowhere) and the rest in TSX fallbacks and placeholders; 38 loading labels end with no `…` ("Loading", "Checking", "Testing", "Publishing", four `PageSkeleton` defaults); no non-breaking space joins a number and its unit anywhere (`en.json` has 0 U+00A0; ":hours h :minutes min", "5 MB"); the API key prefix is printed as `{prefix}...`. | `lib/i18n/locales/en.json:1967` and 20 more lines; `am.json` 24 lines; `om.json` 22 lines; `components/patterns/PageSkeleton.tsx:48`, `:93`, `:136`, `:180`; `components/shared/saved-views-menu.tsx:89`, `:98`; `app/(dashboard)/settings/api-keys/page.tsx:330`; `lib/attendance/shift-moment-text.ts:12-17` | Every page that shows a loading state | Sweep plus line reads; `copy-style.test.ts` does not check this |
| W-39 | Typography | S4 | **Headings are not balanced.** No `text-wrap: balance` or `text-pretty` in the base layer and 93 `h1`/`h2` without it; confirmed where the heading wraps (marketing section intros, the hero, pricing and FAQ heads, CTA blocks, auth form titles, kiosk prompts, page title bar). Short one-line titles were rejected. | `styles/globals.css:629-638`; `components/marketing/landing/section-intro.tsx:24`; `components/marketing/landing/landing-hero.tsx:76`; `components/layouts/page-title-bar.tsx:418`; `app/(auth)/**` 18 headings | Marketing and auth | Line reads; the sign-in `h1` already has `text-balance` |
| W-40 | Animation | S4 | **`transition-all` at 25 sites, and one reduced-motion gap.** `TabsTrigger` (16 consumers), `Progress` (5), the sidebar (5 sites), the kiosk type toggle (3), the scan page (2), the register step circle, admin console bars (3), dashboard widgets (5), the marketing feature cards (2), and `.number-transition` in CSS (unused). The global reduced-motion block zeroes duration and iteration but not `animation-delay`, so `.stagger-children` keeps its 350 ms delays with `both` fill from opacity 0; `scrollIntoView({behavior:"smooth"})` is called without a reduced-motion check in the phone punch flow; the setup dot in the mobile nav is an infinite `animate-ping` where the sidebar's comment asks for a steady dot. | `components/ui/tabs.tsx:53`; `components/ui/progress.tsx:39`; `components/layouts/sidebar-nav.tsx:580-887`; `app/kiosk/page.tsx:713-735`; `app/(dashboard)/attendance/scan/page.tsx:237`, `:249`; `app/(auth)/register/register-form.tsx:400`; `app/(dashboard)/admin/page.tsx:952-1125`; `styles/globals.css:720`, `:1016-1056`; `features/attendance/components/mobile/punch-confirmation.tsx:44`; `app/(dashboard)/attendance/mobile/page.tsx:652-664`; `components/layouts/mobile-bottom-nav.tsx:174` | 25 sites | Sweep plus line reads |
| W-41 | Dark Mode & Theming | S4 | **Hex colours in TSX.** Dead `var(--token, #hex)` fallbacks (the tokens exist), raw white strokes and fills in the marketing SVGs, a Leaflet pin built from `#0F4C75`/`#fff`, raw `rgba()` gradients on the auth layout and portal footer, `bg-black/80` scrims, `#ffffff` forced on the portal cover. Convention #12. The OG image and `global-error.tsx` are excluded as deliberate (no stylesheet there). | `features/devices/components/device-workforce-dialogs.tsx:49-53`; `features/onboarding/v2/components/migration-step.tsx:100-107`; `features/onboarding/v2/components/readiness-step.tsx:18-20`, `:123`; `components/marketing/landing/story-parts.tsx:230-266`; `components/marketing/landing/problem-stories.tsx:353-1868`; `components/shared/map-picker/leaflet-map.tsx:34`; `app/(auth)/auth-layout-client.tsx:167`, `:222`; `features/auth/components/portal-chrome.tsx:399`; `features/auth/components/portal-view.tsx:185`; `components/ui/dialog.tsx:21`; `components/ui/sheet.tsx:59` | 11 files | Sweep (37 hits) plus line reads |
| W-42 | Locale & i18n | S4 | **No `translate="no"` on brand marks, codes and secrets.** The ETHR wordmark, tenant names, employee and badge codes, activation codes, API keys, webhook secrets, SCIM and TOTP secrets, backup encryption keys, starting passwords, DNS records, bank account numbers and invoice ids are all plain text; browser auto-translation can garble them. | `components/layouts/app-sidebar.tsx:172`; `components/layouts/marketing-footer.tsx:118`; `app/(dashboard)/settings/api-keys/page.tsx:245`; `app/(dashboard)/settings/webhooks/page.tsx:230`; `app/(dashboard)/settings/scim/page.tsx:179`; `app/(dashboard)/profile/security/page.tsx:418`; `features/users/components/starting-password-dialog.tsx:161`; `features/employees/components/issue-activation-code-button.tsx:187-191`; `components/shared/dns-record.tsx:24`; `app/(dashboard)/billing/page.tsx:248`, `:313` | Whole app | Grep: `translate="no"` appears nowhere |
| W-43 | Focus States | S4 | **Sticky bars with no scroll padding.** The marketing header is `sticky top-0 z-50` with no `scroll-padding-top` on the document, so anchor and focus jumps land under it (3 pages add `scroll-mt-*`); the dashboard `main` has no `scroll-padding-bottom` for the fixed mobile tab bar or the employee form's sticky save bar; the impersonation banner is `fixed top-0` with no offset on the `h-screen` shell below it; the settings nav strip is `overflow-x-auto` with `ring-2` links that the edge may clip. | `components/layouts/marketing-header.tsx:227`; `styles/globals.css`; `app/(dashboard)/dashboard-shell.tsx:77`; `app/(dashboard)/employees/new/page.tsx:1061`; `components/shared/impersonation-banner.tsx:64`; `components/layouts/settings-nav.tsx:244` | Shell | `scroll-padding-top: auto` and `scroll-padding-bottom: auto` read in the browser; the covering itself not reproduced |
| W-44 | Hover & Interactive States | S4 | **Missing or faint interaction states.** The pill tab variant has no `hover:`; the payroll run row's focus is `bg-muted/40`, the same tint as its hover; the map provider toggles have no hover and are 24 px tall; the command-palette input has `outline-none` with no ring of its own (the dialog frame carries the focus context). | `components/ui/tabs.tsx:56`; `app/(dashboard)/payroll/page.tsx:161`; `components/shared/map-picker/map-picker.tsx:174`; `components/ui/command.tsx:68` | 4 sites | Line reads |
| W-45 | Images | S4 | **Images without dimensions or lazy loading.** The trusted-by logos (`max-h-12 w-auto`, no width/height); the header tenant logo (above the fold, no `fetchPriority`); marketing image upload previews; the attendance photo dialogs; `Avatar` and the footer logo without `loading="lazy"`. `next/image` is not used anywhere (static export). | `components/marketing/landing/trusted-by.tsx:51`; `features/branding/TenantBrandingProvider.tsx:177`; `features/admin/marketing/marketing-image-upload.tsx:105`; `app/(dashboard)/attendance/integrity/page.tsx:581`; `app/(dashboard)/attendance/photos/page.tsx:367`; `components/ui/avatar.tsx:33`; `components/layouts/marketing-footer.tsx:109` | 7 sites | Line reads; CLS not measured |
| W-46 | Hydration Safety, Locale | S4 | **Browser-zone dates and storage-seeded state.** `useCalendar().formatDate` omits the tenant time zone and `formatEth` reads local date parts, unlike every `useDateFormatters` path; `todayEthiopian()` and the reconcile panel's `monthToDate()` use `new Date()` in the browser's zone; `calendar-context`, `useLocalStorage` and the impersonation banner seed `useState` from `localStorage`, which is safe only while they stay behind the client-rendered dashboard; `portal-chrome` renders `new Date().getFullYear()` in a prerendered tree. | `lib/calendar/calendar-context.tsx:51-65`, `:102-110`; `lib/calendar/ethiopian.ts:204`; `features/payroll/components/reconcile-panel.tsx:75`; `lib/hooks/useLocalStorage.ts:4-12`; `components/shared/impersonation-banner.tsx:23`; `features/auth/components/portal-chrome.tsx:534` | lib | Line reads; no mismatch reproduced |

## Owner decisions, not findings

| Vercel rule | ETHR's rule | Source |
|---|---|---|
| Title Case for headings and buttons | Sentence case; no long dashes, emoji or `!` endings, in en and am | Owner, 2026-10-14; `src/src/test/copy-style.test.ts` |
| Curly quotes `“ ”` | Straight quotes in dictionaries and fallbacks | Same decision |
| `Intl.DateTimeFormat` directly | `useDateFormatters()`, `lib/calendar/*` and `formatETB` are the sanctioned path (Ethiopian calendar, Addis time, `X,XXX.XX ETB`); their internal `en-GB`/`en-ET` tags are the helpers, not page code. W-24 lists only page code that bypasses them | `docs/CLAUDE.md` Conventions #2, #3, Ethiopian Calendar Rules |
| Never disable zoom | The kiosk root sets `maximumScale: 1, userScalable: false` (`app/kiosk/layout.tsx:10-11`) with a written reason: a zoomed shared terminal stays zoomed for the next person. The kiosk lock hook adds the JS guards. Kept as a documented exception; note that the setup screen shares the viewport while the lock is inactive, and a low-vision person at the lobby terminal has no in-app text size | Comment in the file |
| Semantic tokens only | `bg-white` behind QR codes (a QR needs a light quiet zone), the OG image and `global-error.tsx` hex, `--brand-mark-*` tokens in the logo | Convention #12 and the files' own comments |

## Known before this pass

Registered in [`FRONTEND_REDESIGN_PLAN.md`](../FRONTEND_REDESIGN_PLAN.md) §C.E and not repeated:
the Dialog close label in literal English; organisation dialog labels without `htmlFor`; 18
plain `focus:` usages in badge, dialog, dropdown-menu and select (W-44 lists a different one);
touch targets under 44 px on `/employees`, `/employees/new` and `/organization`; 16 table files
with no narrow-screen strategy; 39 inputs on one scroll in `/employees/new`; 22 Amharic
prefix/suffix concatenations; 14 English placeholders in `am.json`. Registered in
[`AZ-REVIEW-2026-11-03.md`](AZ-REVIEW-2026-11-03.md): C-11, forms shown to roles that cannot
submit them.

Already right, and worth saying so: `FormField` wires `id`, `htmlFor`, `aria-invalid`,
`aria-required` and `aria-describedby` and is used 122 times; `FormErrorSummary` is a focused
`role="alert"`; `useZodForm` (23 callers) focuses the first rejected field; every lucide icon is
`aria-hidden`; the dashboard shell has a skip link that lands in `main`; the sidebar, mobile and
settings navigation set `aria-current="page"`; the marketing animations check reduced motion,
the 12 s and 16 s loops have a pause button and stop off screen, and `useCountUp` honours reduced
motion; `useHydrated` uses `useSyncExternalStore`; `base :focus-visible` is a 2 px outline
widened to 3 px in high contrast; the `mobile-bottom-nav` and shell handle the bottom safe-area
inset; no `dangerouslySetInnerHTML` carries user input; no raw Tailwind palette class exists in
`src/src`; no page blocks paste.

## Fix plan

In priority order. Each PR is small enough for one review. Sonnet implements; Opus reviews; the
test named is the one that would keep the fix.

| PR | Scope | Findings | Effort | Pins it |
|---|---|---|---|---|
| 1 | **Shell focus and keyboard.** Give the mobile tab bar a `focus-visible:ring-2` (or drop `outline-none`); reveal pin buttons on `focus-visible` and give them a touch path (always visible on coarse pointers, or in the row menu); add `inert` to closed sidebar groups, the Recent list and the setup banner while collapsed; one name for the aside, or drop the nav's; `aria-pressed` on theme items via `DropdownMenuRadioGroup`; expose the bell count. | W-01, W-02, W-03, W-36 | Small | A Playwright journey that tabs the mobile nav at 375 px and asserts a visible focus ring, and that `Tab` from the sidebar never lands on an element with `opacity: 0` or inside a `grid-rows-[0fr]` box |
| 2 | **Shared inputs.** Rewrite `OtpCodeInput` as one `inputMode="numeric"` `autoComplete="one-time-code"` field with a visual six-cell overlay, named by the caller's label; make `PhoneInput` accept and normalise `09…`, `+251…` and `251…` pastes; move the map radius clamp to `onBlur`; label `SearchInput` (`aria-label` from its placeholder or a prop); fix the loan amount to a decimal money field through `CurrencyInput`/`etbToCents`; give the import drop zone a real `<button>` and a visible file input. | W-04, W-05, W-06, W-07, W-15 (search), W-27 | Small to medium | Vitest for the three components (paste `+251912345678`, autofill a 6-digit string, type `50`); a backend-side assertion that `1500.50` round-trips as 150050 cents |
| 3 | **Confirmations.** Route the 13 one-click actions through `ConfirmDialog`/`useConfirm` (22 files already use it), with a specific button label (`Regenerate token`, `End contract`, `Remove gateway`). | W-08 | Small | `CrossTenantRouteSweepTest` is untouched; a Vitest per screen that the mutation is not called until the dialog confirms |
| 4 | **URL state.** Make `useQueryTab`'s setter write `?tab=` with `history.replaceState` (mirror `useQueryFilters`); add a `useQueryPage` and use it on the seven paginated lists; move analytics period, report builder config and reconcile period into the query string. | W-12 | Medium | Vitest on the hook; a Playwright check that `/leave?tab=team` survives reload and that clicking a tab updates the URL |
| 5 | **Keyboard widgets and labels.** Replace the 14 hand-built radio and tab groups with Radix `RadioGroup`/`Tabs` (both installed); wrap the 35 unassociated labels in `FormField` or add `htmlFor`/`id`; name the heat-map and calendar day buttons (`aria-label` from the formatted date and status), or render calendar days as non-interactive `<span>`s; `tabIndex={0}` and `aria-pressed` on the show-password toggles; `inert`/`tabIndex={-1}` on the sr-only file inputs; a keyboard path for the focal point (arrow keys nudge by 5 %). | W-13, W-14, W-15, W-20, W-28, W-29 | Medium | Extend `src/e2e/accessibility.spec.ts` with the affected routes and raise it to fail on `label` and `button-name` moderate violations |
| 6 | **Live regions and first-error focus.** `role="status"` on the kiosk and scan results, the secret cards, the loading screen and the default skeleton (`QueryBoundary`, 81 pages); `role="alert"` plus focus on the error boundary, route errors and the register server error; `setFocus`/`focus()` on the first invalid field in the five hand-rolled forms, or move them to `useZodForm`. | W-19, W-21, W-23 | Medium | Vitest: after a 422 the first invalid control is `document.activeElement`; the status elements exist |
| 7 | **Formats and literals.** Route the 24 display formats through `formatETB`, `useDateFormatters`, `intlLocale` and a new `formatNumber(locale)`; make `birrTick` and the axis formatter take the locale; show exact birr in the payroll analytics table; fix `period-label.ts` for `om`; move the 12 literals into the dictionaries; extend `hardcoded-english.test.tsx` to catch `.replace("_", " ")` on API enums. | W-24, W-25, W-46 (zone parts) | Medium | `hardcoded-english.test.tsx`; a Vitest that `formatNumber("am-ET")` is used by every analytics tick |
| 8 | **Guards and forms metadata.** Make `useUnsavedChangesWarning` intercept in-app navigation (Next 16 has no router events; wrap `Link` clicks and `popstate` behind a `useBlocker`-style hook) and call it from the 15 dirty forms; `autoComplete="off"` + `spellCheck={false}` on identifier fields and `autoComplete="username"`/`"new-password"` deliberately on device credentials; `type="tel"` + `inputMode` on phone fields; example placeholders ending in `…`; `name` on hand-written inputs. | W-22, W-26, W-37 | Medium | A Vitest that every `<Input>` under `app/` and `features/` has `autoComplete` (a tokeniser pass like the tenant-scope inventory), with an allow-list for free text |
| 9 | **Charts and lists.** `isAnimationActive={!reduced}` through `chart-theme.tsx` for every Recharts series; `role="img"` + `aria-label` on each chart wrapper; a keyboard path for the two bar drill-downs (the table rows become links); `EmployeeSelect`/supervisor picker becomes a searchable, paginated `Command` list; cap the reporting chart, run entries and preview with pagination or `virtua`; drop hidden rows from the report preview. | W-17, W-18, W-31 | Medium to large | Vitest for `isAnimationActive` under a mocked `matchMedia`; a Performance gate reading for `/employees/new` with 4,000 employees |
| 10 | **Theme, motion and touch hardening.** Add `@custom-variant dark (&:where(.dark, .dark *))` to `globals.css` (fixes W-09 at the root); install `tw-animate-css` or delete the dead classes and write the six keyframes; `theme-color` per theme via `next-themes`' `data-theme` or a client effect; `overscroll-behavior: contain` on `DialogContent` and `SheetContent`; `touch-action: manipulation` on `Button` and the punch button; `env(safe-area-inset-*)` on the kiosk; `h-dvh` on the shell; `scroll-padding-top` for the marketing header and `scroll-padding-bottom` on `main`; `animation-delay: 0ms` in the reduced-motion block. | W-09, W-32, W-33, W-34, W-40 (delay), W-43 | Small, high leverage | `theme-switching.spec.ts` asserts a `dark:` element follows the picker with the OS forced light; a Vitest that `globals.css` contains the custom variant |
| 11 | **Copy, typography, colours.** Scripted `...` → `…` across the three dictionaries and TSX; `…` on the 38 loading labels; ` ` before units in the dictionary and `shift-moment-text`; `text-balance` in the base layer for `h1, h2`; `tabular-nums` on numeric table cells in `SimpleTable` and the report preview; `translate="no"` on the brand mark, codes and secrets (a `<Code>` or `<Token>` primitive); replace the dead hex fallbacks with the tokens; `transition-[color,background-color,...]` for the 25 `transition-all`; `CardTitle` as `h2`/`h3` via an `as` prop and `EmptyState` heading level from context. | W-35, W-38, W-39, W-40, W-41, W-42, W-44, W-45 | Medium, mechanical | Extend `copy-style.test.ts` to `om.json`, to `...` and to loading labels; the sweep script from this audit as a Vitest over `src/src` |
| 12 | **Public site.** A skip link and `id="main-content"` on the marketing, auth, kiosk and offline roots; the portal's `main` moved to wrap the sign-in form; site content read at build time for the static export (figures and contact channels in the HTML, the API only to refresh); `width`/`height` on the trusted-by logos. | W-10, W-11, W-45 | Medium | `accessibility.spec.ts` on `/en` and `/en/contact`; a build-time test that the exported `en/contact/index.html` contains a `mailto:` link |

Tooling that would stop regressions: enable `eslint-plugin-jsx-a11y` recommended in
`src/eslint.config.mjs` (it would have caught W-07, W-14, W-15, W-16 and W-28), and fix the
dangling `docs/audits/UX_PHASE_XX.md` reference in `src/e2e/ux-audit.spec.ts`.

## What this pass does not claim

- Nothing was fixed. Every line above is a reading of `b452c55983`.
- The browser pass used the desktop app's built-in pane with the viewport emulated at 375 px and
  the colour scheme emulated. It did not run on a phone, iPad Safari or Android Chrome, so the
  address-bar colour (W-34), kiosk safe areas and `h-screen` folds (W-33), pull-to-refresh and
  overscroll chaining, double-tap zoom on the punch button, and SMS autofill into the OTP boxes
  (W-04) were read from code, not seen. Native `<select>` colours under Windows dark mode
  (`register-form.tsx:471`, `activation-code-sheet-dialog.tsx:297`, `issue-activation-code-button.tsx:240`)
  were not confirmed and are not listed.
- Recharts animation under reduced motion (W-17) rests on the library default and the absence of
  `isAnimationActive`, not on an emulated run.
- No screen reader was used. Live-region and name findings (W-15, W-21, W-31, W-35) are from the
  DOM.
- Overflow with long user text (W-30) was not reproduced with data.
- Three readers pattern-scanned part of their batch (see *Method*). A rule a regex cannot see
  may still be unviolated or violated in a file they did not read line by line; the sweep
  candidates in those files were all read.
- Hydration mismatches (W-46) were reasoned from the trees that prerender, not observed.
- The three-reader claims that Enter on the register form's first step submits the whole schema,
  that the kiosk cover overlay leaves the keypad tabbable, and that `Ctrl+B` steals the shortcut
  inside inputs were not confirmed and are not listed.

## Appendix: findings in the guidelines' own format

Confirmed items only, grouped by file, `path:line - issue`, in the terse form `command.md`
prescribes. Files a reader passed after a full read are not listed; the batch reports hold them.

```text
## components/layouts/mobile-bottom-nav.tsx
components/layouts/mobile-bottom-nav.tsx:137 - focus-visible:outline-none, no ring (W-01)
components/layouts/mobile-bottom-nav.tsx:166 - focus-visible:outline-none, no ring (W-01)
components/layouts/mobile-bottom-nav.tsx:174 - infinite animate-ping on setup dot (W-40)

## components/layouts/sidebar-nav.tsx
components/layouts/sidebar-nav.tsx:302 - nav "Main navigation" inside aside with the same name (W-36)
components/layouts/sidebar-nav.tsx:409 - collapsed Recent list focusable, no inert (W-03)
components/layouts/sidebar-nav.tsx:580 - pin button opacity-0 with no focus reveal (W-02)
components/layouts/sidebar-nav.tsx:598 - pin button opacity-0 with no focus reveal (W-02)
components/layouts/sidebar-nav.tsx:632 - unpin button opacity-0 with no focus reveal (W-02)
components/layouts/sidebar-nav.tsx:580,595,632,717,887 - transition-all (W-40)
components/layouts/sidebar-nav.tsx:715 - aria-haspopup="menu" over plain links (W-36)
components/layouts/sidebar-nav.tsx:800 - closed group links focusable, no inert (W-03)
components/layouts/sidebar-nav.tsx:882 - title= as the only name (W-15)
components/layouts/sidebar-nav.tsx:944 - setup banner detail focusable while closed (W-03)

## components/layouts/app-sidebar.tsx
components/layouts/app-sidebar.tsx:172 - tenant name and wordmark without translate="no" (W-42)
components/layouts/app-sidebar.tsx:188 - aside and inner nav share one landmark name (W-36)

## components/layouts/app-header.tsx
components/layouts/app-header.tsx:130 - hardcoded "User" (W-25)
components/layouts/app-header.tsx:337 - theme items have no checked state (W-36)

## components/layouts/marketing-header.tsx
components/layouts/marketing-header.tsx:227 - sticky top-0 header, no scroll-padding-top (W-43)
components/layouts/marketing-header.tsx:244 - no skip link on public roots (W-10)

## components/layouts/marketing-footer.tsx
components/layouts/marketing-footer.tsx:109 - img without loading="lazy" (W-45)
components/layouts/marketing-footer.tsx:118 - wordmark without translate="no" (W-42)

## components/layouts/page-title-bar.tsx
components/layouts/page-title-bar.tsx:418 - h1 without text-balance (W-39)

## components/layouts/settings-nav.tsx
components/layouts/settings-nav.tsx:244 - ring-2 links inside overflow-x-auto (W-43)

## components/ui/card.tsx
components/ui/card.tsx:35 - CardTitle is a div (W-35)

## components/ui/dialog.tsx
components/ui/dialog.tsx:21 - animate-in/fade-in-0/zoom-in-95 undefined; bg-black/80 (W-32, W-41)
components/ui/dialog.tsx:46 - dead animation classes; overflow-y-auto without overscroll-behavior (W-32, W-33)

## components/ui/sheet.tsx
components/ui/sheet.tsx:59 - dead animation classes; bg-black/80 (W-32, W-41)
components/ui/sheet.tsx:62 - dead animation classes; drawer without overscroll-behavior (W-32, W-33)
components/ui/sheet.tsx:64 - slide-out-to-left undefined (W-32)
components/ui/sheet.tsx:65 - slide-out-to-right undefined (W-32)

## components/ui/select.tsx
components/ui/select.tsx:40 - dead animation classes (W-32)

## components/ui/dropdown-menu.tsx
components/ui/dropdown-menu.tsx:21 - dead animation classes (W-32)

## components/ui/popover.tsx
components/ui/popover.tsx:20 - dead animation classes (W-32)

## components/ui/tooltip.tsx
components/ui/tooltip.tsx:35 - dead animation classes (W-32)

## components/ui/tabs.tsx
components/ui/tabs.tsx:53 - transition-all (W-40)
components/ui/tabs.tsx:56 - pill variant has no hover state (W-44)

## components/ui/progress.tsx
components/ui/progress.tsx:39 - transition-all on a transform (W-40)

## components/ui/command.tsx
components/ui/command.tsx:68 - outline-none on the input, no ring (W-44)

## components/ui/button.tsx
components/ui/button.tsx:7 - no touch-action: manipulation (W-33)

## components/ui/avatar.tsx
components/ui/avatar.tsx:33 - img without loading="lazy" (W-45)

## components/patterns/DataTable.tsx
components/patterns/DataTable.tsx:216 - expand toggle swaps label, no aria-expanded (W-31)
components/patterns/DataTable.tsx:323 - loading/error/empty states silent (W-31)
components/patterns/DataTable.tsx:396 - raw column.id in Columns menu (W-31)
components/patterns/DataTable.tsx:424 - sort button, no aria-sort (W-31)

## components/patterns/QueryBoundary.tsx
components/patterns/QueryBoundary.tsx:18 - default skeleton has no role="status" (W-21)

## components/patterns/SettingRow.tsx
components/patterns/SettingRow.tsx:69 - title is <p>, not <label> (W-14)

## components/patterns/PageSkeleton.tsx
components/patterns/PageSkeleton.tsx:48,93,136,180 - "Loading" without … (W-38)

## components/providers/reverb-provider.tsx
components/providers/reverb-provider.tsx:403 - hardcoded English toast (W-25)

## components/shared/otp-code-input.tsx
components/shared/otp-code-input.tsx:83 - six inputs with no accessible name (W-04)
components/shared/otp-code-input.tsx:88 - maxLength=1 with one-time-code on box 0 only (W-04)
components/shared/otp-code-input.tsx:95 - onPaste on box 0 only (W-04)

## components/shared/phone-input.tsx
components/shared/phone-input.tsx:43 - slice(0, 9) cuts pasted numbers (W-05)
components/shared/phone-input.tsx:52 - no autoComplete, name, spellCheck (W-37)
components/shared/phone-input.tsx:59 - placeholder is a pattern, no … (W-37)

## components/shared/search-input.tsx
components/shared/search-input.tsx:53 - input with placeholder only, no label (W-15)

## components/shared/simple-table.tsx
components/shared/simple-table.tsx:55 - 28 px icon buttons (known class, see §C.E)
components/shared/simple-table.tsx:158 - <tr role="button"> nests action buttons (W-16)
components/shared/simple-table.tsx:180 - numeric cells without tabular-nums (W-38)
components/shared/simple-table.tsx:227 - <li role="button"> (W-16)

## components/shared/pagination-controls.tsx
components/shared/pagination-controls.tsx:53 - page numbers not tabular, raw totals (W-38)
components/shared/pagination-controls.tsx:70 - page not in URL (W-12)

## components/shared/currency-input.tsx
components/shared/currency-input.tsx:24 - toFixed(2) builds the edit value (W-24)

## components/shared/saved-views-menu.tsx
components/shared/saved-views-menu.tsx:72 - delete nested in a menu item, no confirm (W-08, W-13)
components/shared/saved-views-menu.tsx:89 - "..." → "…" (W-38)
components/shared/saved-views-menu.tsx:98 - "..." → "…" (W-38)

## components/shared/auth-guard.tsx
components/shared/auth-guard.tsx:41 - "Loading..." → "Loading…" (W-38)

## components/shared/app-loading-screen.tsx
components/shared/app-loading-screen.tsx:53 - no role="status" (W-21)

## components/shared/error-boundary.tsx
components/shared/error-boundary.tsx:31 - fallback not role="alert"; raw error.message (W-21)

## components/shared/empty-state.tsx
components/shared/empty-state.tsx:22 - hard-coded <h3> (W-35)

## components/shared/crud-section.tsx
components/shared/crud-section.tsx:111 - save failure is a toast only, no first-error focus (W-23)
components/shared/crud-section.tsx:252 - dialog closes with unsaved edits (W-22)

## components/shared/impersonation-banner.tsx
components/shared/impersonation-banner.tsx:64 - fixed top-0 over an h-screen shell (W-43)
components/shared/impersonation-banner.tsx:77 - exit button has no pending state (W-19 class)

## components/shared/map-picker/map-picker.tsx
components/shared/map-picker/map-picker.tsx:174 - provider toggles no hover, 24 px (W-44)
components/shared/map-picker/map-picker.tsx:228 - async results not announced (W-21)
components/shared/map-picker/map-picker.tsx:236 - inputs without autoComplete="off", spellCheck (W-37)
components/shared/map-picker/map-picker.tsx:366 - radius clamped on every keystroke (W-27)

## components/shared/map-picker/leaflet-map.tsx
components/shared/map-picker/leaflet-map.tsx:34 - #0F4C75, #fff in pin markup (W-41)

## components/shared/command-palette.tsx
components/shared/command-palette.tsx:276 - results not announced (W-21)
components/shared/command-palette.tsx:415 - hardcoded " to " (W-25)

## components/shared/audit-log-explorer.tsx
components/shared/audit-log-explorer.tsx:80 - filters and page in useState (W-12)
components/shared/audit-log-explorer.tsx:191 - filter input without autoComplete="off" (W-37)
components/shared/audit-log-explorer.tsx:333,356,363 - ids only in title= (W-15)

## components/shared/audit-log-charts.tsx
components/shared/audit-log-charts.tsx:108,169 - Recharts animate under reduced motion (W-17)

## components/shared/dns-record.tsx
components/shared/dns-record.tsx:24 - DNS values without translate="no" (W-42)

## components/shared/employee-select.tsx
components/shared/employee-select.tsx:50 - every employee as a SelectItem (W-18)

## components/dashboard/chart-card.tsx
components/dashboard/chart-card.tsx:69 - table toggle is the only chart alternative (W-17)
components/dashboard/chart-card.tsx:121 - scroll region not focusable (W-31)
components/dashboard/chart-card.tsx:192 - h2 without text-balance (W-39)

## components/dashboard/stat-card.tsx
components/dashboard/stat-card.tsx:207 - delta sign lost, direction by colour (W-17 class)

## components/marketing/landing/hero-story.tsx
components/marketing/landing/hero-story.tsx:593 - dark: follows the OS (W-09)

## components/marketing/landing/problem-stories.tsx
components/marketing/landing/problem-stories.tsx:1907 - dark: follows the OS (W-09)
components/marketing/landing/problem-stories.tsx:353,527,956,1549,1732,1868 - raw white (W-41)

## components/marketing/landing/scenes.tsx
components/marketing/landing/scenes.tsx:27 - dark: follows the OS (W-09)

## components/marketing/landing/story-parts.tsx
components/marketing/landing/story-parts.tsx:230,248,265,266 - raw white strokes and fills (W-41)

## components/marketing/landing/landing-hero.tsx
components/marketing/landing/landing-hero.tsx:68 - Intl.NumberFormat("en-ET") (W-24)
components/marketing/landing/landing-hero.tsx:76 - h1 without text-balance (W-39)

## components/marketing/landing/metrics-band.tsx
components/marketing/landing/metrics-band.tsx:13 - comment claims prerendered values; they are not (W-11)
components/marketing/landing/metrics-band.tsx:40 - returns null until fetched (W-11)
components/marketing/landing/metrics-band.tsx:83 - Intl.NumberFormat("en-ET") (W-24)

## components/marketing/landing/trusted-by.tsx
components/marketing/landing/trusted-by.tsx:21 - returns null until fetched (W-11)
components/marketing/landing/trusted-by.tsx:51 - img without width/height (W-45)

## components/marketing/landing/section-intro.tsx
components/marketing/landing/section-intro.tsx:24 - section h2 without text-balance (W-39)

## components/marketing/landing/cta-section.tsx
components/marketing/landing/cta-section.tsx:25 - h2 without text-balance (W-39)

## components/marketing/solution-page.tsx
components/marketing/solution-page.tsx:74,99,150 - headings without text-balance (W-39)

## components/marketing/legal-document.tsx
components/marketing/legal-document.tsx:37,129 - headings without text-balance (W-39)

## features/marketing/site-content.ts
features/marketing/site-content.ts:201 - enabled only after hydration (W-11)
features/marketing/site-content.ts:207 - EMPTY until fetched (W-11)

## features/news/news-format.ts
features/news/news-format.ts:15 - toFixed(1) + " MB" (W-24)

## features/news/components/article-view.tsx
features/news/components/article-view.tsx:111 - "PDF" literal (W-25)

## features/news/components/news-listing.tsx
features/news/components/news-listing.tsx:192 - search input without name/autoComplete, no … (W-37)
features/news/components/news-listing.tsx:218,355,371 - button onClick navigation with pushState (W-12 class)
features/news/components/news-listing.tsx:239 - aria-live wraps the whole result list (W-21 class)

## features/news/components/news-card.tsx
features/news/components/news-card.tsx:151 - story title is a span (W-35)

## features/news/components/news-state.tsx
features/news/components/news-state.tsx:13 - states without role (W-21)

## app/root-shell.tsx
app/root-shell.tsx:109 - theme-color matches neither background (W-34)

## app/(dashboard)/dashboard-shell.tsx
app/(dashboard)/dashboard-shell.tsx:58 - h-screen shell (W-33)
app/(dashboard)/dashboard-shell.tsx:73 - role="main" on <main> (W-36)
app/(dashboard)/dashboard-shell.tsx:77 - no scroll-padding-bottom for the fixed tab bar (W-43)

## app/(dashboard)/layout.tsx
app/(dashboard)/layout.tsx:41,46 - "Loading..." → "Loading…" (W-38)

## app/(dashboard)/loading.tsx
app/(dashboard)/loading.tsx:16 - "Loading" without … (W-38)

## app/(dashboard)/error.tsx
app/(dashboard)/error.tsx:43 - no role="alert", no focus (W-21)
app/(dashboard)/error.tsx:52 - h2 without text-balance (W-39)
app/(dashboard)/error.tsx:63 - digest without translate="no" (W-42)

## app/error.tsx
app/error.tsx:38 - no role="alert" (W-21)
app/error.tsx:46 - h1 without text-balance (W-39)

## app/not-found-content.tsx
app/not-found-content.tsx:50 - min-h-screen (W-33)
app/not-found-content.tsx:55 - decorative glyph not aria-hidden (W-35 class)
app/not-found-content.tsx:58 - h1 is "404"; the title is a <p> (W-35)

## app/offline/page.tsx
app/offline/page.tsx:11 - min-h-screen; no <main> (W-33, W-35)
app/offline/page.tsx:24 - literal Amharic, no lang, not t() (W-25)

## app/(marketing)/[locale]/layout.tsx
app/(marketing)/[locale]/layout.tsx:107 - min-h-screen (W-33)
app/(marketing)/[locale]/layout.tsx:109 - no skip link; main has no id (W-10)

## app/(marketing)/[locale]/contact/contact-content.tsx
app/(marketing)/[locale]/contact/contact-content.tsx:116 - h1 without text-balance (W-39)
app/(marketing)/[locale]/contact/contact-content.tsx:136 - contact channels absent from SSR (W-11)
app/(marketing)/[locale]/contact/contact-content.tsx:215 - success state not announced (W-21)
app/(marketing)/[locale]/contact/contact-content.tsx:239 - name input no autoComplete (W-37)
app/(marketing)/[locale]/contact/contact-content.tsx:247 - email no autoComplete, spellCheck (W-37)
app/(marketing)/[locale]/contact/contact-content.tsx:256 - phone is type=text, "+251" no … (W-37)
app/(marketing)/[locale]/contact/contact-content.tsx:266 - organisation no autoComplete (W-37)
app/(marketing)/[locale]/contact/contact-content.tsx:318 - "Sending..." → "Sending…" (W-38)

## app/(marketing)/[locale]/pricing/pricing-content.tsx
app/(marketing)/[locale]/pricing/pricing-content.tsx:123 - Intl.NumberFormat("en-ET") (W-24)
app/(marketing)/[locale]/pricing/pricing-content.tsx:168 - Intl.NumberFormat("en-ET") (W-24)
app/(marketing)/[locale]/pricing/pricing-content.tsx:207,318 - headings without text-balance (W-39)
app/(marketing)/[locale]/pricing/pricing-content.tsx:260 - price without tabular-nums (W-38)

## app/(marketing)/[locale]/features/features-content.tsx
app/(marketing)/[locale]/features/features-content.tsx:110,155 - transition-all (W-40)
app/(marketing)/[locale]/features/features-content.tsx:68,91,139,182,216 - headings without text-balance (W-39)

## app/(marketing)/[locale]/faq/faq-content.tsx
app/(marketing)/[locale]/faq/faq-content.tsx:26,73 - headings without text-balance (W-39)

## app/(auth)/auth-layout-client.tsx
app/(auth)/auth-layout-client.tsx:167,222 - raw rgba() gradients (W-41)
app/(auth)/auth-layout-client.tsx:182 - wordmark without translate="no" (W-42)
app/(auth)/auth-layout-client.tsx:192 - h2 without text-balance (W-39)

## app/(auth)/login/login-form.tsx
app/(auth)/login/login-form.tsx:575 - tenant field no aria-invalid/aria-describedby (W-14)
app/(auth)/login/login-form.tsx:583 - field errors not role="alert" (W-21)
app/(auth)/login/login-form.tsx:652 - revealed password no spellCheck={false} (W-37)
app/(auth)/login/login-form.tsx:704 - failed sign-in leaves focus on the button (W-23)
app/(auth)/login/login-form.tsx:721 - "Signing in..." → "Signing in…" (W-38)

## app/(auth)/login/otp/otp-form.tsx
app/(auth)/login/otp/otp-form.tsx:98 - validation without first-error focus (W-23)
app/(auth)/login/otp/otp-form.tsx:302 - role=radio buttons, no arrow keys (W-13)
app/(auth)/login/otp/otp-form.tsx:350,372 - inputs without name/spellCheck (W-37)
app/(auth)/login/otp/otp-form.tsx:409,456 - "..." → "…" (W-38)
app/(auth)/login/otp/otp-form.tsx:418 - "code sent" not announced (W-21)
app/(auth)/login/otp/otp-form.tsx:425 - sr-only Label without htmlFor (W-04)

## app/(auth)/login/mfa/mfa-form.tsx
app/(auth)/login/mfa/mfa-form.tsx:104 - h1 without text-balance (W-39)
app/(auth)/login/mfa/mfa-form.tsx:117 - sr-only Label without htmlFor (W-04)
app/(auth)/login/mfa/mfa-form.tsx:157 - rejected code clears all boxes (W-04)

## app/(auth)/login/activate/activate-form.tsx
app/(auth)/login/activate/activate-form.tsx:99,165,191 - headings without text-balance (W-39)
app/(auth)/login/activate/activate-form.tsx:156 - done state not announced; timer not cleared (W-21)
app/(auth)/login/activate/activate-form.tsx:261 - show-password tabIndex={-1} (W-20)
app/(auth)/login/activate/activate-form.tsx:336 - "Loading..." → "Loading…" (W-38)

## app/(auth)/login/change-password/change-password-form.tsx
app/(auth)/login/change-password/change-password-form.tsx:154 - autoFocus on a mobile-first screen (W-37)
app/(auth)/login/change-password/change-password-form.tsx:177 - show-password tabIndex={-1} (W-20)

## app/(auth)/login/reset/reset-form.tsx
app/(auth)/login/reset/reset-form.tsx:191 - show-password tabIndex={-1} (W-20)
app/(auth)/login/reset/reset-form.tsx:269 - "Loading..." → "Loading…" (W-38)

## app/(auth)/login/forgot/forgot-form.tsx
app/(auth)/login/forgot/forgot-form.tsx:208 - tenant input no autoComplete, spellCheck (W-37)
app/(auth)/login/forgot/forgot-form.tsx:116 - success view not announced (W-21)

## app/(auth)/login/find/find-organisation-form.tsx
app/(auth)/login/find/find-organisation-form.tsx:135 - email no spellCheck; duplicate id (W-37)
app/(auth)/login/find/find-organisation-form.tsx:76 - success view not announced (W-21)

## app/(auth)/register/register-form.tsx
app/(auth)/register/register-form.tsx:85 - step in useState only (W-12)
app/(auth)/register/register-form.tsx:392 - step indicator without aria-current (W-19)
app/(auth)/register/register-form.tsx:400 - transition-all (W-40)
app/(auth)/register/register-form.tsx:429 - form without noValidate (W-19)
app/(auth)/register/register-form.tsx:448,664,777 - autoFocus on three steps (W-19)
app/(auth)/register/register-form.tsx:611 - availability by icon only (W-19)
app/(auth)/register/register-form.tsx:684 - admin email no spellCheck (W-37)
app/(auth)/register/register-form.tsx:853 - server error not role="alert" (W-19)
app/(auth)/register/register-form.tsx:877 - Next disabled while invalid (W-19)
app/(auth)/register/register-form.tsx:894 - "Creating account..." → "…" (W-38)

## features/auth/components/active-sessions-card.tsx
features/auth/components/active-sessions-card.tsx:82 - sign out all with no confirm (W-08)
features/auth/components/active-sessions-card.tsx:150 - flex child without min-w-0 (W-30)
features/auth/components/active-sessions-card.tsx:181 - revoke with no confirm (W-08)

## features/auth/components/portal-chrome.tsx
features/auth/components/portal-chrome.tsx:304 - Sheet without overscroll-behavior (W-33)
features/auth/components/portal-chrome.tsx:399 - raw rgba() (W-41)
features/auth/components/portal-chrome.tsx:534 - new Date() in a prerendered tree (W-46)

## features/auth/components/portal-view.tsx
features/auth/components/portal-view.tsx:185 - #ffffff forced (W-41)
features/auth/components/portal-view.tsx:239 - sr-only "Loading" without … (W-38)
features/auth/components/portal-view.tsx:302 - main starts after the hero; skip link skips the form (W-10)
features/auth/components/portal-view.tsx:456 - vacancy titles without break-words (W-30)

## features/onboarding/v2/components/industry-config-step.tsx
features/onboarding/v2/components/industry-config-step.tsx:414 - role=radio buttons, no arrow keys (W-13)
features/onboarding/v2/components/industry-config-step.tsx:552 - Region input no name/autoComplete, no … (W-37)

## features/onboarding/v2/components/migration-step.tsx
features/onboarding/v2/components/migration-step.tsx:100 - dead hex fallbacks (W-41)
features/onboarding/v2/components/migration-step.tsx:1072 - paste box: literal English, no guard (W-25, W-22)
features/onboarding/v2/components/migration-step.tsx:1488 - up to 2,000 rows with a Select each (W-18)

## features/onboarding/v2/components/migration-logins.tsx
features/onboarding/v2/components/migration-logins.tsx:263 - up to 2,000 rows (W-18)

## features/onboarding/v2/components/readiness-step.tsx
features/onboarding/v2/components/readiness-step.tsx:18,123 - dead hex fallbacks (W-41)

## features/onboarding/v2/components/guided-onboarding.tsx
features/onboarding/v2/components/guided-onboarding.tsx:52 - step in useState only (W-12)

## features/onboarding/v2/components/access-step.tsx
features/onboarding/v2/components/access-step.tsx:294 - checkbox icon is a dead zone (W-37)

## app/(dashboard)/employees/page.tsx
app/(dashboard)/employees/page.tsx:137 - page in useState (W-12)
app/(dashboard)/employees/page.tsx:279 - name/email cell without truncate (W-30)
app/(dashboard)/employees/page.tsx:499 - "..." → "…" (W-38)

## app/(dashboard)/employees/[id]/employee-detail.tsx
app/(dashboard)/employees/[id]/employee-detail.tsx:146 - save failure toast only (W-23)
app/(dashboard)/employees/[id]/employee-detail.tsx:192 - h1 flex without min-w-0 (W-30)
app/(dashboard)/employees/[id]/employee-detail.tsx:295 - Cancel discards edits silently (W-22)
app/(dashboard)/employees/[id]/employee-detail.tsx:319,330,341,353 - Labels without htmlFor (W-14)
app/(dashboard)/employees/[id]/employee-detail.tsx:388 - supervisor picker lists everyone (W-18)

## app/(dashboard)/employees/new/page.tsx
app/(dashboard)/employees/new/page.tsx:734 - email without spellCheck (W-37)
app/(dashboard)/employees/new/page.tsx:750 - "+251 9..." → "…" (W-38)
app/(dashboard)/employees/new/page.tsx:1061 - sticky save bar, no scroll-padding-bottom (W-43)

## app/(dashboard)/directory/page.tsx
app/(dashboard)/directory/page.tsx:24 - page in useState (W-12)
app/(dashboard)/directory/page.tsx:48 - "..." → "…" (W-38)
app/(dashboard)/directory/page.tsx:97 - position/department without truncate (W-30)

## app/(dashboard)/vacancies/page.tsx
app/(dashboard)/vacancies/page.tsx:207 - role=tab buttons, no arrow keys (W-13)
app/(dashboard)/vacancies/page.tsx:273 - h3 with no h2 (W-35)
app/(dashboard)/vacancies/page.tsx:468 - whitespace-pre-line without break-words (W-30)
app/(dashboard)/vacancies/page.tsx:590 - no first-error focus (W-23)
app/(dashboard)/vacancies/page.tsx:789 - email/URL without spellCheck (W-37)

## app/(dashboard)/employees/letters/page.tsx
app/(dashboard)/employees/letters/page.tsx:361 - unpaginated rows (W-18)

## features/employees/components/attendance-timeline-tab.tsx
features/employees/components/attendance-timeline-tab.tsx:125,309 - toFixed(1) + "h" (W-24)
features/employees/components/attendance-timeline-tab.tsx:143 - range inputs without label (W-15)
features/employees/components/attendance-timeline-tab.tsx:201 - 16 px day buttons named by title only (W-15)
features/employees/components/attendance-timeline-tab.tsx:263 - raw API enums (W-25)

## features/employees/components/contracts-tab.tsx
features/employees/components/contracts-tab.tsx:197 - terms without break-words (W-30)
features/employees/components/contracts-tab.tsx:214,226 - end contract with no confirm (W-08)

## features/employees/components/disciplinary-cases-tab.tsx
features/employees/components/disciplinary-cases-tab.tsx:332 - note input placeholder only (W-15)
features/employees/components/disciplinary-cases-tab.tsx:385 - close case with no confirm (W-08)
features/employees/components/disciplinary-cases-tab.tsx:1050 - Labels without htmlFor (W-14)

## features/employees/components/lifecycle-tab.tsx
features/employees/components/lifecycle-tab.tsx:60,165,272 - hardcoded English (W-25)
features/employees/components/lifecycle-tab.tsx:316 - role=radio buttons, no arrow keys (W-13)

## features/employees/components/retirement-tab.tsx
features/employees/components/retirement-tab.tsx:255 - note input placeholder only (W-15)

## features/employees/components/headcount-charts.tsx
features/employees/components/headcount-charts.tsx:110,163 - Recharts animate under reduced motion (W-17)

## features/employees/components/info-row.tsx
features/employees/components/info-row.tsx:13 - value without break-words (W-30)

## features/employees/components/kiosk-pin-card.tsx
features/employees/components/kiosk-pin-card.tsx:134 - PIN error not announced (W-21)

## features/organization/components/grade-salary-steps-dialog.tsx
features/organization/components/grade-salary-steps-dialog.tsx:157 - toLocaleString() (W-24)
features/organization/components/grade-salary-steps-dialog.tsx:190 - delete step with no confirm (W-08)

## features/organization/components/reporting-chart.tsx
features/organization/components/reporting-chart.tsx:153,223 - whole tree rendered (W-18)

## features/attendance/api.ts
features/attendance/api.ts:297 - useEmployeeOptions fetches every page (W-18)

## features/recruitment/components/applicants-panel.tsx
features/recruitment/components/applicants-panel.tsx:264,277,289 - inputs without autoComplete (W-37)
features/recruitment/components/applicants-panel.tsx:418,470 - cover note without break-words (W-30)

## app/(dashboard)/attendance/page.tsx
app/(dashboard)/attendance/page.tsx:397 - Filters button without aria-expanded (W-36 class)
app/(dashboard)/attendance/page.tsx:430,443,457,504,788 - Labels not tied to controls (W-14)

## app/(dashboard)/attendance/import/page.tsx
app/(dashboard)/attendance/import/page.tsx:215 - <div onClick> drop zone over a hidden input (W-07)

## app/(dashboard)/attendance/kiosks/page.tsx
app/(dashboard)/attendance/kiosks/page.tsx:283 - regenerate token with no confirm (W-08)
app/(dashboard)/attendance/kiosks/page.tsx:297 - delete kiosk with no confirm (W-08)
app/(dashboard)/attendance/kiosks/page.tsx:396 - admin PIN without autoComplete (W-26)
app/(dashboard)/attendance/kiosks/page.tsx:414,463 - identifier/token without spellCheck (W-26)

## app/(dashboard)/attendance/scan/page.tsx
app/(dashboard)/attendance/scan/page.tsx:183 - 3 s redirect timer, no cleanup (W-21 class)
app/(dashboard)/attendance/scan/page.tsx:202 - token input without name/autoComplete (W-37)
app/(dashboard)/attendance/scan/page.tsx:237,249 - transition-all (W-40)
app/(dashboard)/attendance/scan/page.tsx:260 - results not announced (W-21)
app/(dashboard)/attendance/scan/page.tsx:263,285 - dead animate-in (W-32)

## app/(dashboard)/attendance/qr/page.tsx
app/(dashboard)/attendance/qr/page.tsx:280 - countdown not announced (W-21)
app/(dashboard)/attendance/qr/page.tsx:315 - QR without an accessible name (W-15)

## app/(dashboard)/attendance/mobile/page.tsx
app/(dashboard)/attendance/mobile/page.tsx:652 - smooth scrollIntoView without reduced-motion check (W-40)
app/(dashboard)/attendance/mobile/page.tsx:778 - offline banner not role="status" (W-21)
app/(dashboard)/attendance/mobile/page.tsx:868 - role=radio buttons, no arrow keys (W-13)

## app/(dashboard)/attendance/corrections/page.tsx
app/(dashboard)/attendance/corrections/page.tsx:343 - toLocaleString("en-ET") instead of formatETB (W-24)
app/(dashboard)/attendance/corrections/page.tsx:678 - Label without htmlFor (W-14)

## app/(dashboard)/attendance/integrity/page.tsx
app/(dashboard)/attendance/integrity/page.tsx:236 - role=radio pills, no arrow keys (W-13)
app/(dashboard)/attendance/integrity/page.tsx:581 - img without width/height (W-45)

## app/(dashboard)/attendance/photos/page.tsx
app/(dashboard)/attendance/photos/page.tsx:100 - role=radio pills, no arrow keys (W-13)
app/(dashboard)/attendance/photos/page.tsx:367 - img without width/height (W-45)

## app/(dashboard)/attendance/overtime/page.tsx
app/(dashboard)/attendance/overtime/page.tsx:102,110,164 - toFixed for hours (W-24)

## app/(dashboard)/attendance/devices/page.tsx
app/(dashboard)/attendance/devices/page.tsx:56 - filter, search, page in useState (W-12)

## app/(dashboard)/devices/page.tsx
app/(dashboard)/devices/page.tsx:584 - toLocaleString() (W-24)
app/(dashboard)/devices/page.tsx:767,862 - serial/IP without spellCheck (W-26)
app/(dashboard)/devices/page.tsx:888 - credentials invite the admin's own login (W-26)

## app/(dashboard)/devices/dashboard/page.tsx
app/(dashboard)/devices/dashboard/page.tsx:96,97,171,181,189,336 - toLocaleString() (W-24)

## app/(dashboard)/devices/[id]/device-detail.tsx
app/(dashboard)/devices/[id]/device-detail.tsx:286 - h1 without text-balance (W-39)
app/(dashboard)/devices/[id]/device-detail.tsx:383,388 - toLocaleString() (W-24)
app/(dashboard)/devices/[id]/device-detail.tsx:926,977,1007,1019,1071 - credentials without autoComplete (W-26)

## app/(dashboard)/shifts/page.tsx
app/(dashboard)/shifts/page.tsx:624 - working-day toggles without aria-pressed (W-36 class)

## app/(dashboard)/shifts/rotations/page.tsx
app/(dashboard)/shifts/rotations/page.tsx:298 - delete rotation with no confirm (W-08)
app/(dashboard)/shifts/rotations/page.tsx:313,667,702 - vh where dvh fits (W-33)
app/(dashboard)/shifts/rotations/page.tsx:364 - Pattern Label not tied (W-14)
app/(dashboard)/shifts/rotations/page.tsx:573 - "Select..." → "Select…" (W-38)

## app/(dashboard)/shifts/roster/page.tsx
app/(dashboard)/shifts/roster/page.tsx:118 - anchor date, view in useState (W-12)

## features/shifts/components/shift-coverage-charts.tsx
features/shifts/components/shift-coverage-charts.tsx:190 - Recharts animate under reduced motion (W-17)

## features/devices/components/device-workforce-dialogs.tsx
features/devices/components/device-workforce-dialogs.tsx:49 - hex fallbacks (W-41)
features/devices/components/device-workforce-dialogs.tsx:337 - Label not tied (W-14)

## features/attendance/components/mobile/punch-confirmation.tsx
features/attendance/components/mobile/punch-confirmation.tsx:44 - smooth scroll without reduced-motion check (W-40)

## features/attendance/components/mobile/readiness-list.tsx
features/attendance/components/mobile/readiness-list.tsx:330 - h-[50vh] (W-33)
features/attendance/components/mobile/readiness-list.tsx:337 - liveness prompt aria-hidden (W-21)

## features/attendance/components/mobile/punch-button.tsx
features/attendance/components/mobile/punch-button.tsx:149 - no touch-action: manipulation (W-33)

## app/kiosk/layout.tsx
app/kiosk/layout.tsx:10 - zoom disabled (owner decision, documented)
app/kiosk/layout.tsx:31 - min-h-screen (W-33)

## app/kiosk/page.tsx
app/kiosk/page.tsx:476,522,584 - min-h-screen (W-33)
app/kiosk/page.tsx:483,527 - no h1; headings unbalanced (W-35, W-39)
app/kiosk/page.tsx:491 - token Label without htmlFor; autoFocus (W-14, W-37)
app/kiosk/page.tsx:501,544,701 - errors not announced (W-21)
app/kiosk/page.tsx:534 - admin PIN placeholder only (W-15)
app/kiosk/page.tsx:586,894 - no env(safe-area-inset-*) (W-33)
app/kiosk/page.tsx:697 - dead animate-in (W-32)
app/kiosk/page.tsx:713,724,735 - transition-all; type toggle without aria-pressed (W-40, W-36)

## features/kiosk/components/kiosk-punch-result.tsx
features/kiosk/components/kiosk-punch-result.tsx:65 - dead animate-in (W-32)
features/kiosk/components/kiosk-punch-result.tsx:87 - result not announced (W-21)

## app/(dashboard)/payroll/page.tsx
app/(dashboard)/payroll/page.tsx:37 - page in useState (W-12)
app/(dashboard)/payroll/page.tsx:161 - focus tint equals hover tint (W-44)

## app/(dashboard)/payroll/loans/page.tsx
app/(dashboard)/payroll/loans/page.tsx:80 - all loans, no pagination (W-18)
app/(dashboard)/payroll/loans/page.tsx:131 - parseInt drops cents (W-06)
app/(dashboard)/payroll/loans/page.tsx:174,186,204 - Labels without htmlFor (W-14)

## app/(dashboard)/payroll/[id]/payroll-run-detail.tsx
app/(dashboard)/payroll/[id]/payroll-run-detail.tsx:284,913 - toLocaleDateString("en-CA") in browser zone (W-24)
app/(dashboard)/payroll/[id]/payroll-run-detail.tsx:532 - h1 without text-balance (W-39)
app/(dashboard)/payroll/[id]/payroll-run-detail.tsx:754 - every entry rendered (W-18)
app/(dashboard)/payroll/[id]/payroll-run-detail.tsx:922 - reference without autoComplete="off" (W-37)

## features/payroll/components/run-payroll-dialog.tsx
features/payroll/components/run-payroll-dialog.tsx:262 - every payslip in a scroll box (W-18)
features/payroll/components/run-payroll-dialog.tsx:292 - raw enum (W-25)

## features/payroll/components/reconcile-panel.tsx
features/payroll/components/reconcile-panel.tsx:75 - new Date() in browser zone (W-46)
features/payroll/components/reconcile-panel.tsx:94 - filters in useState (W-12)
features/payroll/components/reconcile-panel.tsx:135,143 - Labels without htmlFor (W-14)
features/payroll/components/reconcile-panel.tsx:257 - all exceptions rendered (W-18)

## features/payroll/components/statutory-rules-card.tsx
features/payroll/components/statutory-rules-card.tsx:233,292 - rule changes with no confirm (W-08)

## features/payroll/components/statutory-details-card.tsx
features/payroll/components/statutory-details-card.tsx:146,220 - TIN/registration without autoComplete="off", translate="no" (W-26, W-42)

## features/payroll/components/overtime-limits-banner.tsx
features/payroll/components/overtime-limits-banner.tsx:10 - toFixed(1) hours (W-24)

## features/payroll/components/tax-brackets-card.tsx
features/payroll/components/tax-brackets-card.tsx:56 - money cells not tabular; rate without % (W-38)

## app/(dashboard)/approvals/page.tsx
app/(dashboard)/approvals/page.tsx:248 - every pending approval rendered (W-18)
app/(dashboard)/approvals/page.tsx:302 - global pending spinner on every row (W-19 class)

## app/(dashboard)/leave/page.tsx
app/(dashboard)/leave/page.tsx:165,188,199,209 - Labels without htmlFor (W-14)
app/(dashboard)/leave/page.tsx:303,498,499 - page and view in useState (W-12)
app/(dashboard)/leave/page.tsx:930 - calendar detail only in title= (W-15)

## features/leave/components/leave-charts.tsx
features/leave/components/leave-charts.tsx:142 - monthName(...).split(" ")[0] (W-24)
features/leave/components/leave-charts.tsx:186,220,308 - Recharts animate under reduced motion (W-17)

## app/(dashboard)/analytics/page.tsx
app/(dashboard)/analytics/page.tsx:64,66 - period and scope in useState (W-12)
app/(dashboard)/analytics/page.tsx:103 - CSV labels hard-coded; UTC stamp (W-25)

## app/(dashboard)/analytics/axis-format.ts
app/(dashboard)/analytics/axis-format.ts:21 - Intl.NumberFormat("en") (W-24)

## app/(dashboard)/analytics/attendance-tab.tsx
app/(dashboard)/analytics/attendance-tab.tsx:155 - chart without a name (W-17)
app/(dashboard)/analytics/attendance-tab.tsx:200,286 - Recharts animate under reduced motion (W-17)

## app/(dashboard)/analytics/overview-tab.tsx
app/(dashboard)/analytics/overview-tab.tsx:260,328 - Recharts animate (W-17)
app/(dashboard)/analytics/overview-tab.tsx:267 - drill-down only by <Bar onClick> (W-17)

## app/(dashboard)/analytics/payroll-tab.tsx
app/(dashboard)/analytics/payroll-tab.tsx:143 - compact "1.2K" in the table view (W-24)
app/(dashboard)/analytics/payroll-tab.tsx:188,267,320,378 - Recharts animate (W-17)

## app/(dashboard)/analytics/workforce-tab.tsx
app/(dashboard)/analytics/workforce-tab.tsx:132,180 - Recharts animate (W-17)

## features/dashboard/components/calendar-widget.tsx
features/dashboard/components/calendar-widget.tsx:26 - weekday names hard-coded (W-25 class)
features/dashboard/components/calendar-widget.tsx:85,154 - day buttons with no handler or label (W-15)

## features/dashboard/components/interactive-charts.tsx
features/dashboard/components/interactive-charts.tsx:85 - role=tab without arrow keys; tab in useState (W-13, W-12)
features/dashboard/components/interactive-charts.tsx:219,300,411 - Recharts animate (W-17)

## features/dashboard/components/alert-thresholds-dialog.tsx
features/dashboard/components/alert-thresholds-dialog.tsx:115,135,156,170 - Labels without htmlFor (W-14)
features/dashboard/components/alert-thresholds-dialog.tsx:237 - raw severity enum (W-25)
features/dashboard/components/alert-thresholds-dialog.tsx:244 - delete with no confirm (W-08)

## features/dashboard/components/dashboard-digest-dialog.tsx
features/dashboard/components/dashboard-digest-dialog.tsx:153 - email without autoComplete/spellCheck (W-37)
features/dashboard/components/dashboard-digest-dialog.tsx:252 - delete with no confirm (W-08)

## features/dashboard/components/recent-activity.tsx
features/dashboard/components/recent-activity.tsx:137 - type.replace(/_/g, " ") (W-25)
features/dashboard/components/recent-activity.tsx:147 - transition-all (W-40)

## features/dashboard/components/{announcements-widget,leave-overview,team-overview}.tsx
features/dashboard/components/announcements-widget.tsx:137 - transition-all (W-40)
features/dashboard/components/leave-overview.tsx:120,143 - transition-all (W-40)
features/dashboard/components/team-overview.tsx:171 - transition-all (W-40)

## features/reports/components/builder-tab.tsx
features/reports/components/builder-tab.tsx:87 - report definition in useState (W-12)
features/reports/components/builder-tab.tsx:281 - 14 px checkboxes in an unfocusable scroll box (W-37)
features/reports/components/builder-tab.tsx:525 - generating state not announced (W-21)
features/reports/components/builder-tab.tsx:607 - Label without htmlFor (W-14)

## features/reports/components/quick-tab.tsx
features/reports/components/quick-tab.tsx:221 - month in useState (W-12)
features/reports/components/quick-tab.tsx:379 - twelve buttons named "Run" (W-15 class)

## features/reports/components/preview-result.tsx
features/reports/components/preview-result.tsx:200 - rows past 200 kept hidden in the DOM (W-18)
features/reports/components/preview-result.tsx:202 - numbers not tabular (W-38)

## features/reports/components/saved-tab.tsx
features/reports/components/saved-tab.tsx:300,325 - Labels without htmlFor (W-14)
features/reports/components/saved-tab.tsx:328 - email without autoComplete/spellCheck (W-37)

## features/reports/field-labels.ts
features/reports/field-labels.ts:222 - String(value) for numbers (W-24)

## app/(dashboard)/billing/page.tsx
app/(dashboard)/billing/page.tsx:248,313 - account number, invoice id without translate="no" (W-42)
app/(dashboard)/billing/page.tsx:329 - due date printed as raw ISO (W-24)
app/(dashboard)/billing/page.tsx:517 - plan buttons without aria-pressed (W-36 class)
app/(dashboard)/billing/page.tsx:523 - transition-all (W-40)

## app/(dashboard)/settings/users/page.tsx
app/(dashboard)/settings/users/page.tsx:136 - page in useState (W-12)
app/(dashboard)/settings/users/page.tsx:523 - invite email without autoComplete/spellCheck (W-37)
app/(dashboard)/settings/users/page.tsx:541,607 - usernames without spellCheck (W-26)
app/(dashboard)/settings/users/page.tsx:729 - "Remove user" dialog confirms with "Delete" (W-19 class)

## app/(dashboard)/settings/api-keys/page.tsx
app/(dashboard)/settings/api-keys/page.tsx:223 - new-key card not announced (W-21)
app/(dashboard)/settings/api-keys/page.tsx:245 - key without translate="no" (W-42)
app/(dashboard)/settings/api-keys/page.tsx:330 - "{prefix}..." (W-38)

## app/(dashboard)/settings/webhooks/page.tsx
app/(dashboard)/settings/webhooks/page.tsx:223 - secret card not announced (W-21)
app/(dashboard)/settings/webhooks/page.tsx:230 - secret without translate="no" (W-42)
app/(dashboard)/settings/webhooks/page.tsx:492 - URL input without spellCheck (W-26)

## app/(dashboard)/settings/scim/page.tsx
app/(dashboard)/settings/scim/page.tsx:113,214,255,266 - h3 with no h2 (W-35)
app/(dashboard)/settings/scim/page.tsx:164 - token card not announced (W-21)
app/(dashboard)/settings/scim/page.tsx:179 - token without translate="no" (W-42)

## app/(dashboard)/settings/data-export/page.tsx
app/(dashboard)/settings/data-export/page.tsx:45 - toFixed(1) + " MB" (W-24)

## app/(dashboard)/settings/payroll/page.tsx
app/(dashboard)/settings/payroll/page.tsx:25 - tab in useState (W-12)

## app/(dashboard)/settings/attendance/page.tsx
app/(dashboard)/settings/attendance/page.tsx:180 - dirty form with no guard (W-22)

## app/(dashboard)/settings/page.tsx
app/(dashboard)/settings/page.tsx:255 - dirty form with no guard (W-22)

## app/(dashboard)/profile/security/page.tsx
app/(dashboard)/profile/security/page.tsx:418 - TOTP secret without translate="no" (W-42)

## app/(dashboard)/profile/personal/page.tsx
app/(dashboard)/profile/personal/page.tsx:521 - email without autoComplete/spellCheck (W-37)

## app/(dashboard)/notifications/page.tsx
app/(dashboard)/notifications/page.tsx:30 - page in useState (W-12)
app/(dashboard)/notifications/page.tsx:80 - list changes not announced (W-21)
app/(dashboard)/notifications/page.tsx:92 - button rows navigate by router.push (W-16 class)

## features/notifications/components/notification-bell.tsx
features/notifications/components/notification-bell.tsx:56 - badge count not exposed (W-36)
features/notifications/components/notification-bell.tsx:95 - router.push from onClick (W-16 class)

## app/(dashboard)/announcements/page.tsx
app/(dashboard)/announcements/page.tsx:580 - every announcement rendered (W-18)
app/(dashboard)/announcements/page.tsx:589 - title without break class (W-30)
app/(dashboard)/announcements/page.tsx:974,1060 - sr-only file inputs focusable (W-28)

## app/(dashboard)/voice/insights/page.tsx
app/(dashboard)/voice/insights/page.tsx:215,296 - Recharts animate under reduced motion (W-17)
app/(dashboard)/voice/insights/page.tsx:367 - whitespace-pre-line without break-words (W-30)
app/(dashboard)/voice/insights/page.tsx:424 - role=radio buttons, no arrow keys (W-13)

## app/(dashboard)/voice/page.tsx
app/(dashboard)/voice/page.tsx:244,275,784 - role=radio buttons, no arrow keys (W-13)

## app/(dashboard)/support/page.tsx
app/(dashboard)/support/page.tsx:92,119,158 - tab, filter, page in useState (W-12)
app/(dashboard)/support/page.tsx:313 - whitespace-pre-line without break-words (W-30)

## app/(dashboard)/admin/page.tsx
app/(dashboard)/admin/page.tsx:952,1021,1125 - transition-all on bars (W-40)

## app/(dashboard)/admin/operations/page.tsx
app/(dashboard)/admin/operations/page.tsx:581 - toFixed(1) + " GB" (W-24)

## app/(dashboard)/admin/tenants/page.tsx
app/(dashboard)/admin/tenants/page.tsx:89 - page in useState (W-12)
app/(dashboard)/admin/tenants/page.tsx:131 - "Search tenants..." → "…" (W-38)
app/(dashboard)/admin/tenants/page.tsx:317 - row navigates by router.push, no link (W-16)

## app/(dashboard)/admin/tenants/[id]/tenant-detail.tsx
app/(dashboard)/admin/tenants/[id]/tenant-detail.tsx:1553 - Label without htmlFor (W-14)

## app/(dashboard)/admin/tenants/[id]/tenant-journey-card.tsx
app/(dashboard)/admin/tenants/[id]/tenant-journey-card.tsx:143,250,289,315 - h3 with no h2 (W-35)

## app/(dashboard)/admin/sms/page.tsx
app/(dashboard)/admin/sms/page.tsx:160,728 - Label not tied to Select (W-14)
app/(dashboard)/admin/sms/page.tsx:565 - remove gateway with no confirm (W-08)

## features/admin/components/backup-drive-card.tsx
features/admin/components/backup-drive-card.tsx:372 - disconnect with no confirm (W-08)

## features/admin/marketing/marketing-image-upload.tsx
features/admin/marketing/marketing-image-upload.tsx:105 - img without width/height (W-45)
features/admin/marketing/marketing-image-upload.tsx:128 - sr-only file input focusable (W-28)

## features/settings/components/branding-card.tsx
features/settings/components/branding-card.tsx:162 - dark:bg-background follows the OS (W-09)

## features/settings/components/brand-image-upload.tsx
features/settings/components/brand-image-upload.tsx:142 - sr-only file input focusable (W-28)
features/settings/components/brand-image-upload.tsx:253 - focal point from clientX/clientY only (W-29)

## features/settings/components/logo-upload.tsx
features/settings/components/logo-upload.tsx:114 - sr-only file input focusable (W-28)

## features/settings/components/landing-card.tsx
features/settings/components/landing-card.tsx:541 - role=radio buttons, no arrow keys (W-13)
features/settings/components/landing-card.tsx:685 - email without spellCheck/autoComplete (W-37)

## features/settings/components/portal-preview.tsx
features/settings/components/portal-preview.tsx:85 - role=radio buttons, no arrow keys (W-13)

## features/settings/components/address-name-section.tsx
features/settings/components/address-name-section.tsx:222 - availability status not announced (W-21)

## features/branding/TenantBrandingProvider.tsx
features/branding/TenantBrandingProvider.tsx:177 - header logo img without width/height or priority (W-45)

## features/users/components/starting-password-dialog.tsx
features/users/components/starting-password-dialog.tsx:161 - password without translate="no" (W-42)

## features/employees/components/issue-activation-code-button.tsx
features/employees/components/issue-activation-code-button.tsx:187 - codes without translate="no" (W-42)

## features/api-reference/endpoint-list.tsx
features/api-reference/endpoint-list.tsx:69,150,176,185 - h3/h4 with no h2 (W-35)

## lib/hooks/useQueryTab.ts
lib/hooks/useQueryTab.ts:30 - setter never writes ?tab= (W-12)

## lib/hooks/useUnsavedChangesWarning.ts
lib/hooks/useUnsavedChangesWarning.ts:1 - beforeunload only; one caller (W-22)

## lib/calendar/period-label.ts
lib/calendar/period-label.ts:46 - "am" hard-coded; om falls to English (W-24)

## lib/calendar/calendar-context.tsx
lib/calendar/calendar-context.tsx:51 - useState seeded from localStorage (W-46)
lib/calendar/calendar-context.tsx:102 - formatters omit the tenant zone (W-46)

## lib/calendar/ethiopian.ts
lib/calendar/ethiopian.ts:204 - todayEthiopian() in browser zone (W-46)

## lib/uploads/upload-limit.ts
lib/uploads/upload-limit.ts:19 - toFixed(1) + " MB" (W-24)

## lib/attendance/shift-moment-text.ts
lib/attendance/shift-moment-text.ts:12 - breakable space before units (W-38)

## lib/i18n/locales/en.json
lib/i18n/locales/en.json:646,1624,1721,1726,1768,1967,2412,2476,2623,2672,2680,2698,2826,2918,2939,2954,3612,4452,5624,6403,6550 - "..." → "…" (W-38)
lib/i18n/locales/en.json:5699 - "Checking" → "Checking…" (W-38)

## lib/i18n/locales/am.json
lib/i18n/locales/am.json:30,646,1624,1655,1683,1691,1721,1726,1741,1750,1768,1967,2412,2476,2623,2672,2826,2918,2939,2954,3612,5624,6403,6550 - "..." → "…" (W-38)

## lib/i18n/locales/om.json
lib/i18n/locales/om.json:30,646,1624,1721,1726,1768,1967,2412,2476,2623,2672,2680,2698,2826,2918,2939,2954,3612,4452,5624,6403,6550 - "..." → "…" (W-38)

## styles/globals.css
styles/globals.css:629 - h1, h2, h3 without text-wrap: balance (W-39)
styles/globals.css:720 - reduced-motion block leaves animation-delay (W-40)
styles/globals.css:1056 - .number-transition uses transition: all (W-40)
styles/globals.css - no @custom-variant dark; no overscroll-behavior; no touch-action; no scroll-padding (W-09, W-33, W-43)
```
